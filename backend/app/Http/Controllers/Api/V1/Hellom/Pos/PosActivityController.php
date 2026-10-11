<?php

namespace App\Http\Controllers\Api\V1\Hellom\Pos;

use App\Models\Organization;
use App\Models\Outlet;
use App\Models\PosActivityLog;
use App\Services\Pos\Audit\ActivityLogger;
use App\Services\Pos\Audit\AnomalyReport;
use App\Services\Pos\Audit\OwnerPinException;
use App\Services\Pos\Audit\OwnerPinService;
use App\Support\Pos\ActivityActions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * POS › Log Audit (owner only): owner PIN (create on first POS visit, change, forgot → email to the
 * primary owner), and the audit trail / fraud alerts behind it. Reading the log needs a fresh PIN
 * unlock (X-Owner-Pin-Token, 15 minutes). Routes: posPermission:manager, owner checked here.
 */
class PosActivityController extends BasePosController
{
    public function __construct(private readonly OwnerPinService $pins)
    {
    }

    public function pinStatus(Request $request): JsonResponse
    {
        return $this->success($this->pins->status($this->organization($request), $request->user()), 'Status PIN');
    }

    public function createPin(Request $request): JsonResponse
    {
        $validated = $request->validate(['pin' => 'required|string|confirmed'], self::PIN_MESSAGES);

        return $this->withPin(function () use ($request, $validated) {
            $org = $this->organization($request);
            $this->pins->create($org, $request->user(), (string) $validated['pin']);

            return $this->success($this->pins->status($org, $request->user()), 'PIN pemilik dibuat', 201);
        });
    }

    public function changePin(Request $request): JsonResponse
    {
        $validated = $request->validate(['current_pin' => 'required|string', 'pin' => 'required|string|confirmed'], self::PIN_MESSAGES);

        return $this->withPin(function () use ($request, $validated) {
            $org = $this->organization($request);
            $this->pins->change($org, $request->user(), (string) $validated['current_pin'], (string) $validated['pin']);

            return $this->success($this->pins->status($org, $request->user()), 'PIN pemilik diganti');
        }, 'current_pin');
    }

    public function unlock(Request $request): JsonResponse
    {
        $validated = $request->validate(['pin' => 'required|string'], self::PIN_MESSAGES);

        return $this->withPin(fn () => $this->success($this->pins->unlock($this->organization($request), $request->user(), (string) $validated['pin']), 'Log audit terbuka'));
    }

    public function forgotPin(Request $request): JsonResponse
    {
        return $this->withPin(function () use ($request) {
            $masked = $this->pins->requestReset($this->organization($request), $request->user());

            return $this->success(['sent_to' => $masked], "Link reset PIN dikirim ke {$masked}");
        });
    }

    /** Audit trail, newest first, with filters. */
    public function index(Request $request): JsonResponse
    {
        if ($denied = $this->requireUnlock($request)) {
            return $denied;
        }
        $validated = $request->validate([
            'from' => 'nullable|date', 'to' => 'nullable|date',
            'action' => ['nullable', Rule::in(array_keys(ActivityActions::CATALOG))],
            'severity' => 'nullable|in:info,warning,critical',
            'actor' => ['nullable', 'regex:/^(staff|user):\d+$/'],
            'outlet_id' => 'nullable|integer',
            'q' => 'nullable|string|max:60',
            'page' => 'nullable|integer|min:1',
        ]);
        $org = $this->organization($request);
        [$from, $to] = $this->range($validated, 7);

        $query = PosActivityLog::query()->where('organization_id', $org->id)
            ->whereBetween('occurred_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->when($validated['action'] ?? null, fn ($q, $a) => $q->where('action', $a))
            ->when($validated['severity'] ?? null, fn ($q, $s) => $q->where('severity', $s))
            ->when($validated['outlet_id'] ?? null, fn ($q, $o) => $q->where('outlet_id', $o))
            ->when($validated['actor'] ?? null, function ($q, $actor) {
                [$type, $id] = explode(':', $actor);
                $type === 'staff' ? $q->where('actor_staff_id', (int) $id) : $q->where('actor_user_id', (int) $id)->whereNull('actor_staff_id');
            })
            ->when($validated['q'] ?? null, fn ($q, $term) => $q->where('target_label', 'like', '%' . str_replace(['%', '_'], ['\%', '\_'], ltrim($term, '#')) . '%'))
            ->orderByDesc('id');
        $page = $query->paginate(50, ['*'], 'page', (int) ($validated['page'] ?? 1));
        $outlets = Outlet::query()->where('organization_id', $org->id)->pluck('name', 'id');

        $actors = PosActivityLog::query()->where('organization_id', $org->id)->where('occurred_at', '>=', now()->subDays(90))
            ->whereNotNull('actor_name')->select('actor_staff_id', 'actor_user_id', 'actor_name', 'actor_role')->distinct()->limit(200)->get()
            ->map(fn ($a) => ['key' => $a->actor_staff_id ? 'staff:' . $a->actor_staff_id : 'user:' . $a->actor_user_id, 'name' => $a->actor_name, 'role' => $a->actor_role])
            ->unique('key')->values();

        return $this->success([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'logs' => collect($page->items())->map(fn (PosActivityLog $log) => $this->present($log, $outlets))->values(),
            'pagination' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
            'actions' => collect(ActivityActions::CATALOG)->map(fn ($def, $key) => ['key' => $key, 'label' => $def[0]])->values(),
            'actors' => $actors,
            'outlets' => $outlets->map(fn ($name, $id) => ['id' => (int) $id, 'name' => $name])->values(),
        ], 'Log audit');
    }

    public function anomalies(Request $request, AnomalyReport $report): JsonResponse
    {
        if ($denied = $this->requireUnlock($request)) {
            return $denied;
        }
        $validated = $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date', 'outlet_id' => 'nullable|integer']);
        [$from, $to] = $this->range($validated, 7);

        return $this->success($report->build($this->organization($request)->id, $from, $to, isset($validated['outlet_id']) ? (int) $validated['outlet_id'] : null), 'Peringatan kecurangan');
    }

    public function integrity(Request $request, ActivityLogger $logger): JsonResponse
    {
        if ($denied = $this->requireUnlock($request)) {
            return $denied;
        }

        return $this->success($logger->verify($this->organization($request)->id), 'Integritas log');
    }

    private const PIN_MESSAGES = [
        'pin.required' => 'Masukkan PIN 6 angka.',
        'pin.confirmed' => 'Konfirmasi PIN belum sama.',
        'current_pin.required' => 'Masukkan PIN lama.',
    ];

    private function withPin(callable $action, string $field = 'pin'): JsonResponse
    {
        try {
            return $action();
        } catch (OwnerPinException $e) {
            return $this->pinFailed($e, $field);
        }
    }

    private function requireUnlock(Request $request): ?JsonResponse
    {
        $org = $this->organization($request);
        if (!$this->pins->isOwner($request->user(), $org)) {
            return $this->error('Hanya pemilik toko yang bisa membuka log audit.', 'OWNER_ONLY', null, 403);
        }
        if (!$this->pins->unlockTokenValid($request->header('X-Owner-Pin-Token'), $org, $request->user())) {
            return $this->error('Masukkan PIN pemilik untuk membuka log audit.', 'PIN_UNLOCK_REQUIRED', null, 401);
        }

        return null;
    }

    private function organization(Request $request): Organization
    {
        return Organization::query()->findOrFail($this->posOrganizationId($request));
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function range(array $validated, int $defaultDays): array
    {
        $to = isset($validated['to']) ? Carbon::parse($validated['to']) : now();
        $from = isset($validated['from']) ? Carbon::parse($validated['from']) : $to->copy()->subDays($defaultDays - 1);
        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }
        if ($from->diffInDays($to) > 92) {
            $from = $to->copy()->subDays(92);
        }

        return [$from, $to];
    }

    /** @return array<string, mixed> */
    private function present(PosActivityLog $log, $outlets): array
    {
        return [
            'id' => (int) $log->id,
            'occurred_at' => Carbon::parse((string) $log->getRawOriginal('occurred_at'))->format('Y-m-d\TH:i:s.vP'),
            'action' => $log->action,
            'action_label' => ActivityActions::label((string) $log->action),
            'severity' => $log->severity,
            'outlet' => $log->outlet_id ? ['id' => (int) $log->outlet_id, 'name' => $outlets[$log->outlet_id] ?? null] : null,
            'actor' => ['name' => $log->actor_name, 'role' => $log->actor_role, 'user_id' => $log->actor_user_id, 'staff_id' => $log->actor_staff_id],
            'target' => ['type' => $log->target_type, 'id' => $log->target_id, 'label' => $log->target_label],
            'before' => $log->before_state,
            'after' => $log->after_state,
            'reason' => $log->reason,
            'approver' => $log->approver_name ? ['name' => $log->approver_name, 'method' => $log->approval_method] : null,
            'ip_address' => $log->ip_address,
            'user_agent' => $log->user_agent,
            'device_id' => $log->device_id,
            'session_ref' => $log->session_ref,
            'hash' => substr((string) $log->hash, 0, 12),
        ];
    }
}
