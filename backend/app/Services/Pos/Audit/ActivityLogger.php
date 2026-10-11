<?php

namespace App\Services\Pos\Audit;

use App\Models\ApiToken;
use App\Models\Outlet;
use App\Models\PosActivityLog;
use App\Models\PosStaff;
use App\Models\User;
use App\Support\Pos\ActivityActions;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Writes the POS audit trail (pos_activity_logs): who (user + POS staff + role), what (action,
 * target, before/after JSON, reason), who approved (owner PIN), from where (IP, device id from
 * the X-Device-Id header, session = API token id — never the token itself), millisecond time.
 *
 * Rows are hash-chained per organization: hash = sha256(prev_hash | canonical row). The head row
 * is locked while appending, so the chain has no forks; verify() recomputes it. Call inside the
 * same DB transaction as the change it describes so a rolled-back action leaves no log, and a
 * committed action always has one.
 */
final class ActivityLogger
{
    public const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    /** Fields covered by the hash, in a fixed order. */
    private const HASHED = [
        'organization_id', 'outlet_id', 'tenant_id', 'occurred_at', 'action', 'severity',
        'actor_user_id', 'actor_staff_id', 'actor_name', 'actor_role', 'target_type', 'target_id', 'target_label',
        'before_state', 'after_state', 'reason', 'approver_user_id', 'approver_name', 'approval_method',
        'ip_address', 'user_agent', 'device_id', 'session_ref',
    ];

    private const INT_FIELDS = ['organization_id', 'outlet_id', 'actor_user_id', 'actor_staff_id', 'target_id', 'approver_user_id'];

    /**
     * @param array{
     *   outlet?: ?Outlet, target_type?: ?string, target_id?: ?int, target_label?: ?string,
     *   before?: ?array, after?: ?array, reason?: ?string, approver?: ?User, approval_method?: ?string,
     *   severity?: ?string, actor?: ?User, actor_role?: ?string, request?: ?Request
     * } $entry
     */
    public function record(int $organizationId, string $action, array $entry = []): PosActivityLog
    {
        $request = $entry['request'] ?? $this->currentRequest();
        $outlet = $entry['outlet'] ?? ($request?->attributes->get('posOutlet') instanceof Outlet ? $request->attributes->get('posOutlet') : null);
        $staff = $request?->attributes->get('posStaff');
        $staff = $staff instanceof PosStaff ? $staff : null;
        $actor = $entry['actor'] ?? $request?->user();
        $actor = $actor instanceof User ? $actor : null;
        $approver = $entry['approver'] ?? null;
        $token = $request?->attributes->get('apiToken');

        $row = [
            'organization_id' => $organizationId,
            'outlet_id' => $outlet?->id,
            'tenant_id' => $outlet?->tenant_slug ?? $staff?->tenant_id,
            'occurred_at' => Carbon::now()->format('Y-m-d H:i:s.v'),
            'action' => $action,
            'severity' => $entry['severity'] ?? ActivityActions::severity($action),
            'actor_user_id' => $actor?->id,
            'actor_staff_id' => $staff?->id,
            'actor_name' => self::cut($staff?->name ?? $actor?->name, 120),
            'actor_role' => $entry['actor_role'] ?? $this->roleOf($organizationId, $actor, $staff),
            'target_type' => $entry['target_type'] ?? null,
            'target_id' => isset($entry['target_id']) ? (int) $entry['target_id'] : null,
            'target_label' => self::cut($entry['target_label'] ?? null, 120),
            'before_state' => $entry['before'] ?? null,
            'after_state' => $entry['after'] ?? null,
            'reason' => self::cut($entry['reason'] ?? null, 500),
            'approver_user_id' => $approver?->id,
            'approver_name' => self::cut($approver?->name, 120),
            'approval_method' => $approver ? ($entry['approval_method'] ?? 'owner_pin') : null,
            'ip_address' => $request?->ip(),
            'user_agent' => self::cut($request?->userAgent(), 255),
            'device_id' => $this->deviceId($request),
            'session_ref' => $token instanceof ApiToken ? 'token:' . $token->id : null,
        ];

        return DB::transaction(function () use ($row): PosActivityLog {
            DB::table('pos_activity_log_heads')->insertOrIgnore(['organization_id' => $row['organization_id'], 'last_id' => 0, 'last_hash' => self::GENESIS, 'updated_at' => now()]);
            $head = DB::table('pos_activity_log_heads')->where('organization_id', $row['organization_id'])->lockForUpdate()->first();
            $row['prev_hash'] = (string) $head->last_hash;
            $row['hash'] = self::hash($row['prev_hash'], $row);
            $row['created_at'] = now();
            $log = PosActivityLog::query()->create($row);
            DB::table('pos_activity_log_heads')->where('organization_id', $row['organization_id'])
                ->update(['last_id' => $log->id, 'last_hash' => $row['hash'], 'updated_at' => now()]);

            return $log;
        }, 3);
    }

    /**
     * Recompute the chain of one organization.
     *
     * @return array{ok: bool, checked: int, broken_id: ?int, problem: ?string}
     */
    public function verify(int $organizationId): array
    {
        $expectedPrev = self::GENESIS;
        $checked = 0;
        $lastId = 0;
        $result = null;
        PosActivityLog::query()->where('organization_id', $organizationId)->orderBy('id')
            ->chunk(500, function ($rows) use (&$expectedPrev, &$checked, &$lastId, &$result) {
                foreach ($rows as $log) {
                    $row = $log->getAttributes();
                    $row['before_state'] = $log->before_state;
                    $row['after_state'] = $log->after_state;
                    if ((string) $log->prev_hash !== $expectedPrev) {
                        $result = ['broken_id' => (int) $log->id, 'problem' => 'Urutan log terputus (ada baris yang hilang atau disisipkan).'];

                        return false;
                    }
                    if (!hash_equals((string) $log->hash, self::hash($expectedPrev, $row))) {
                        $result = ['broken_id' => (int) $log->id, 'problem' => 'Isi log berubah setelah dicatat.'];

                        return false;
                    }
                    $expectedPrev = (string) $log->hash;
                    $lastId = (int) $log->id;
                    $checked++;
                }

                return true;
            });

        if ($result === null) {
            $head = DB::table('pos_activity_log_heads')->where('organization_id', $organizationId)->first();
            if ($head && ((int) $head->last_id !== $lastId || (string) $head->last_hash !== $expectedPrev)) {
                $result = ['broken_id' => $lastId ?: null, 'problem' => 'Log terakhir hilang.'];
            }
        }

        return ['ok' => $result === null, 'checked' => $checked, 'broken_id' => $result['broken_id'] ?? null, 'problem' => $result['problem'] ?? null];
    }

    public static function hash(string $prevHash, array $row): string
    {
        $data = [];
        foreach (self::HASHED as $field) {
            $value = $row[$field] ?? null;
            if (in_array($field, self::INT_FIELDS, true) && $value !== null) {
                $value = (int) $value;
            } elseif ($field === 'occurred_at' && $value !== null) {
                $value = Carbon::parse((string) $value)->format('Y-m-d H:i:s.v');
            } elseif (is_array($value)) {
                $value = self::canonical($value);
            } elseif ($value !== null) {
                $value = (string) $value;
            }
            $data[$field] = $value;
        }

        return hash('sha256', $prevHash . '|' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    /** Sorted keys at every level (MySQL JSON columns reorder keys). */
    private static function canonical(array $value): array
    {
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::canonical($item);
            }
        }

        return $value;
    }

    private function roleOf(int $organizationId, ?User $actor, ?PosStaff $staff): ?string
    {
        if ($staff) {
            return (string) $staff->role === 'admin' ? 'pos_admin' : 'cashier';
        }
        if (!$actor) {
            return 'system';
        }
        $pivot = DB::table('organization_user')->where('organization_id', $organizationId)->where('user_id', $actor->id)->value('role');

        return $pivot ? (string) $pivot : ((string) $actor->role === 'super_admin' ? 'super_admin' : 'user');
    }

    private function deviceId(?Request $request): ?string
    {
        $raw = (string) $request?->header('X-Device-Id', '');

        return preg_match('/^[A-Za-z0-9-]{8,64}$/', $raw) ? $raw : null;
    }

    private function currentRequest(): ?Request
    {
        if (app()->runningInConsole() && !app()->runningUnitTests()) {
            return null;
        }
        $request = app('request');

        return $request instanceof Request && $request->route() !== null ? $request : null;
    }

    private static function cut(?string $value, int $max): ?string
    {
        return $value === null || $value === '' ? null : mb_substr($value, 0, $max);
    }
}
