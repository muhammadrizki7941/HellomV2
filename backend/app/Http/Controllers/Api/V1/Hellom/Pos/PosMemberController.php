<?php

namespace App\Http\Controllers\Api\V1\Hellom\Pos;

use App\Models\AuditLog;
use App\Models\MemberPointTransaction;
use App\Models\Order;
use App\Models\Organization;
use App\Models\PosFraudFlag;
use App\Models\PosMember;
use App\Models\PosRewardRule;
use App\Services\Pos\LoyaltyService;
use App\Services\Pos\MemberService;
use App\Services\Pos\PricingException;
use App\Services\Pos\Verification\PointRedemptionVerifier;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Members belong to the organization: every outlet sees the same member, the phone is
 * the identity (normalised 628…). Points live in the ledger (member_point_transactions);
 * the balance on the member is a cache kept by LoyaltyService.
 */
class PosMemberController extends BasePosController
{
    public function __construct(
        private readonly MemberService $members,
        private readonly LoyaltyService $loyalty,
    ) {
    }

    /**
     * Owner member page: search (name/phone/email), outlet filter (members who ordered
     * there), sort (recent | most_active | top_spend | points), paginated.
     */
    public function index(Request $request): JsonResponse
    {
        $org = $this->getOrg($request);
        if (!$org) {
            return $this->error('Konteks POS tidak tersedia', 'CONTEXT_MISSING');
        }

        $query = $this->filteredQuery($request, $org);
        $members = $query->paginate(min(100, max(10, (int) $request->query('per_page', 20))));

        $members->getCollection()->transform(fn (PosMember $m) => $this->present($m));

        return $this->success($members, 'Members retrieved');
    }

    public function search(Request $request): JsonResponse
    {
        $org = $this->getOrg($request);
        if (!$org) {
            return $this->error('Konteks POS tidak tersedia', 'CONTEXT_MISSING');
        }
        $term = trim((string) $request->input('q', ''));
        if (mb_strlen($term) < 2) {
            return $this->success(['members' => []], 'Ketik minimal 2 karakter');
        }

        $members = PosMember::query()
            ->forOrganization($org->id)
            ->where(fn ($q) => $this->applySearch($q, $term))
            ->orderByDesc('last_order_at')
            ->limit(10)
            ->get();

        return $this->success(['members' => $members->map(fn (PosMember $m) => $this->present($m))->values()], 'Hasil pencarian');
    }

    /** Cashier registration — same path as self-order and the public page (MemberService). */
    public function store(Request $request): JsonResponse
    {
        $org = $this->getOrg($request);
        if (!$org) {
            return $this->error('Konteks POS tidak tersedia', 'CONTEXT_MISSING');
        }
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'phone' => 'required|string|max:20',
            'email' => 'nullable|email|max:100',
        ]);
        if (!PhoneNumber::normalize($validated['phone'])) {
            return $this->error('Nomor HP tidak valid', 'PHONE_INVALID', null, 422);
        }
        $outlet = $this->posOutlet($request);

        [$member, $created] = $this->members->register($org, $validated['name'], $validated['phone'], $validated['email'] ?? null, $outlet?->id, $outlet?->tenant_slug);
        if (!$created) {
            return $this->error('Nomor HP sudah terdaftar sebagai member: ' . $member->name, 'MEMBER_EXISTS', ['member' => $this->present($member)], 422);
        }

        return $this->success(['member' => $this->present($member)], 'Member berhasil didaftarkan! 🎉', 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $org = $this->getOrg($request);
        if (!$org) {
            return $this->error('Konteks POS tidak tersedia', 'CONTEXT_MISSING');
        }
        $member = $this->findMember($org, $id);
        $rules = $this->rewardRules($org);

        return $this->success([
            'member' => $this->present($member),
            'available_rewards' => $rules->filter(fn (PosRewardRule $r) => $this->progress($r, $member) >= (int) $r->trigger_value)
                ->map(fn (PosRewardRule $r) => [
                    'id' => $r->id,
                    'name' => $r->name,
                    'description' => $r->description,
                    'reward_type' => $r->reward_type,
                    'reward_value' => $r->reward_value,
                    'product' => $r->scopedRewardProduct()?->name,
                ])->values(),
            'recent_points' => $member->ledger()->latest('id')->limit(10)->get()->map(fn ($row) => $this->presentLedger($row))->values(),
            'next_reward' => $this->nextReward($rules, $member),
            'redeem' => [
                'settings' => $this->loyalty->settingsFor($org)->toPosPayload(),
                'verification' => app(PointRedemptionVerifier::class)->requirement(),
            ],
        ], 'Data member berhasil dimuat');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $org = $this->getOrg($request);
        if (!$org) {
            return $this->error('Konteks POS tidak tersedia', 'CONTEXT_MISSING');
        }
        $member = $this->findMember($org, $id);
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:100',
        ]);

        $normalized = PhoneNumber::normalize($validated['phone'] ?? null);
        if ($normalized && $normalized !== $member->phone_normalized) {
            $taken = $this->members->findByPhone($org, $normalized);
            if ($taken && $taken->id !== $member->id) {
                return $this->error('Nomor HP sudah dipakai member lain: ' . $taken->name, 'MEMBER_EXISTS', null, 422);
            }
        }

        $member->update([
            'name' => trim($validated['name']),
            'phone' => $normalized ?? ($validated['phone'] ?? null),
            'phone_normalized' => $normalized,
            'email' => $validated['email'] ?? null,
        ]);

        return $this->success(['member' => $this->present($member)], 'Member updated');
    }

    /** Ledger history (newest first), with the order and outlet of each row. */
    public function pointHistory(Request $request, int $id): JsonResponse
    {
        $org = $this->getOrg($request);
        if (!$org) {
            return $this->error('Konteks POS tidak tersedia', 'CONTEXT_MISSING');
        }
        $member = $this->findMember($org, $id);

        $rows = MemberPointTransaction::query()
            ->where('member_id', $member->id)
            ->with(['order:id,order_number,outlet_id', 'outlet:id,name', 'user:id,name'])
            ->orderByDesc('id')
            ->paginate(20);
        $rows->getCollection()->transform(fn ($row) => $this->presentLedger($row));

        return $this->success($rows, 'Point history retrieved');
    }

    /** Orders of the member across all outlets. */
    public function orders(Request $request, int $id): JsonResponse
    {
        $org = $this->getOrg($request);
        if (!$org) {
            return $this->error('Konteks POS tidak tersedia', 'CONTEXT_MISSING');
        }
        $member = $this->findMember($org, $id);
        $orders = Order::withoutGlobalScope('tenant')
            ->where('member_id', $member->id)
            ->with('outlet:id,name')
            ->orderByDesc('id')
            ->paginate(20);
        $orders->getCollection()->transform(fn (Order $o) => [
            'id' => $o->id,
            'order_number' => $o->order_number,
            'outlet' => $o->outlet?->name,
            'status' => $o->status,
            'payment_status' => $o->payment_status,
            'final_amount' => (int) $o->final_amount,
            'points_earned' => (int) $o->points_earned,
            'redeemed_points' => (int) $o->redeemed_points,
            'created_at' => optional($o->created_at)->toIso8601String(),
        ]);

        return $this->success($orders, 'Riwayat pesanan member');
    }

    /** Manual +/- points (owner or supervisor only; reason required; audit-logged). */
    public function adjustPoints(Request $request, int $id): JsonResponse
    {
        $org = $this->getOrg($request);
        if (!$org) {
            return $this->error('Konteks POS tidak tersedia', 'CONTEXT_MISSING');
        }
        if (!$this->canPos($request, 'member_points')) {
            return $this->error('Akun kamu belum punya akses mengubah poin. Minta owner/admin mengaktifkannya di POS › Staff.', 'FORBIDDEN', null, 403);
        }
        $validated = $request->validate([
            'points' => 'required|integer|not_in:0|min:-100000|max:100000',
            'reason' => 'required|string|min:3|max:255',
        ]);
        $member = $this->findMember($org, $id);
        $before = (int) $member->redeemable_points;

        try {
            $row = $this->loyalty->adjust($member, (int) $validated['points'], $validated['reason'], $request->user()?->id, $this->posOutlet($request)?->id);
        } catch (PricingException $e) {
            return $this->orderRuleFailed($e);
        }

        AuditLog::record('pos.member.points_adjusted', $request->user()?->id, $org->id, 'pos_member', $member->id,
            ['redeemable_points' => $before], ['redeemable_points' => (int) $row->balance_after],
            ['points' => (int) $validated['points'], 'reason' => $validated['reason'], 'ledger_id' => $row->id],
            $request->ip(), mb_substr((string) $request->userAgent(), 0, 255));

        return $this->success(['member' => $this->present($member->fresh()), 'transaction' => $this->presentLedger($row)], 'Poin diperbarui');
    }

    /** Phones shared by several members (legacy data) — the owner merges them by hand. */
    public function duplicates(Request $request): JsonResponse
    {
        $org = $this->getOrg($request);
        if (!$org) {
            return $this->error('Konteks POS tidak tersedia', 'CONTEXT_MISSING');
        }

        return $this->success(['duplicates' => $this->members->duplicates($org)], 'Nomor HP ganda');
    }

    public function merge(Request $request): JsonResponse
    {
        $org = $this->getOrg($request);
        if (!$org) {
            return $this->error('Konteks POS tidak tersedia', 'CONTEXT_MISSING');
        }
        if (!$this->isOrgOwner($request, $org)) {
            return $this->error('Hanya owner yang bisa menggabungkan member', 'FORBIDDEN', null, 403);
        }
        $validated = $request->validate([
            'target_member_id' => 'required|integer',
            'source_member_id' => 'required|integer|different:target_member_id',
            'reason' => 'required|string|min:3|max:255',
        ]);

        try {
            $member = $this->members->merge(
                $org,
                $this->findMember($org, (int) $validated['target_member_id']),
                $this->findMember($org, (int) $validated['source_member_id']),
                (int) $request->user()->id,
                $validated['reason'],
                $request->ip(),
                mb_substr((string) $request->userAgent(), 0, 255),
            );
        } catch (PricingException $e) {
            return $this->orderRuleFailed($e);
        }

        return $this->success(['member' => $this->present($member)], 'Member digabung');
    }

    public function export(Request $request)
    {
        $org = $this->getOrg($request);
        if (!$org) {
            return $this->error('Konteks POS tidak tersedia', 'CONTEXT_MISSING');
        }
        if (!$this->isOrgOwner($request, $org)) {
            return $this->error('Hanya owner yang bisa mengekspor data member', 'FORBIDDEN', null, 403);
        }

        $directory = storage_path('app/exports');
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        $filename = sprintf('Member-%s-%s.xlsx', Str::slug($org->name), now()->format('d-m-Y'));
        $path = $directory . DIRECTORY_SEPARATOR . now()->format('YmdHis') . '-' . Str::random(6) . '-' . $filename;

        $writer = new Writer();
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Member');
        $writer->addRow(Row::fromValues(['ID', 'Nama', 'No. HP', 'Email', 'Saldo Poin', 'Total Poin Diperoleh', 'Jumlah Pesanan', 'Total Belanja', 'Pesanan Terakhir', 'Terdaftar']));
        $this->filteredQuery($request, $org)->chunk(500, function ($chunk) use ($writer) {
            foreach ($chunk as $m) {
                $writer->addRow(Row::fromValues([
                    $m->id, $m->name, $m->phone_normalized ?: $m->phone, $m->email,
                    (int) $m->redeemable_points, (int) $m->total_points, (int) $m->total_orders, (int) $m->total_spent,
                    optional($m->last_order_at)->format('Y-m-d H:i'), optional($m->created_at)->format('Y-m-d'),
                ]));
            }
        });
        $writer->close();

        AuditLog::record('pos.member.exported', $request->user()?->id, $org->id, 'pos_member', null, null, null,
            ['filters' => $request->only(['q', 'outlet_id', 'sort'])], $request->ip(), mb_substr((string) $request->userAgent(), 0, 255));

        return response()->download($path, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /** Open fraud signals for the owner (FraudDetector). */
    public function fraudFlags(Request $request): JsonResponse
    {
        $org = $this->getOrg($request);
        if (!$org) {
            return $this->error('Konteks POS tidak tersedia', 'CONTEXT_MISSING');
        }
        if (!$this->canPos($request, 'member_points')) {
            return $this->error('Akun kamu belum punya akses ke data member ini', 'FORBIDDEN', null, 403);
        }
        $flags = PosFraudFlag::query()
            ->where('organization_id', $org->id)
            ->where('status', $request->query('status', 'open'))
            ->orderByDesc('id')
            ->paginate(20);
        $memberNames = PosMember::query()->whereIn('id', $flags->getCollection()->pluck('member_id'))->pluck('name', 'id');
        $userNames = \App\Models\User::query()->whereIn('id', $flags->getCollection()->pluck('user_id')->filter())->pluck('name', 'id');
        $flags->getCollection()->transform(fn (PosFraudFlag $f) => [
            'id' => $f->id,
            'rule' => $f->rule,
            'rule_label' => match ($f->rule) {
                'member_repeat_same_cashier' => 'Member sama berulang oleh kasir yang sama dalam satu shift',
                'member_phone_is_staff' => 'Nomor HP member = nomor HP staf',
                default => $f->rule,
            },
            'member_id' => $f->member_id,
            'member_name' => $memberNames[$f->member_id] ?? null,
            'user_id' => $f->user_id,
            'user_name' => $userNames[$f->user_id] ?? null,
            'order_id' => $f->order_id,
            'outlet_id' => $f->outlet_id,
            'details' => $f->details,
            'status' => $f->status,
            'created_at' => optional($f->created_at)->toIso8601String(),
        ]);

        return $this->success($flags, 'Sinyal kecurangan');
    }

    public function resolveFraudFlag(Request $request, int $flagId): JsonResponse
    {
        $org = $this->getOrg($request);
        if (!$org || !$this->canPos($request, 'member_points')) {
            return $this->error('Akun kamu belum punya akses ke data member ini', 'FORBIDDEN', null, 403);
        }
        $validated = $request->validate(['status' => 'required|in:dismissed,confirmed']);
        $flag = PosFraudFlag::query()->where('organization_id', $org->id)->findOrFail($flagId);
        $flag->update(['status' => $validated['status']]);
        AuditLog::record('pos.fraud_flag.' . $validated['status'], $request->user()?->id, $org->id, 'pos_fraud_flag', $flag->id,
            null, null, null, $request->ip(), mb_substr((string) $request->userAgent(), 0, 255));

        return $this->success(['id' => $flag->id, 'status' => $flag->status], 'Sinyal diperbarui');
    }

    public function publicRegister(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'org_slug' => 'required|string',
            'name' => 'required|string|max:100',
            'phone' => 'required|string|max:20',
            'email' => 'nullable|email|max:100',
        ]);

        $org = Organization::query()->where('slug', $validated['org_slug'])->first();
        if (!$org) {
            return $this->fail('Organisasi tidak ditemukan', null, 404);
        }
        if (!PhoneNumber::normalize($validated['phone'])) {
            return $this->fail('Nomor HP tidak valid', ['code' => 'PHONE_INVALID'], 422);
        }

        [$member, $created] = $this->members->register($org, $validated['name'], $validated['phone'], $validated['email'] ?? null);

        return $this->ok(
            ['member' => $this->presentPublic($member)],
            $created ? 'Pendaftaran member berhasil! 🎉' : 'Nomor HP sudah terdaftar. Selamat datang kembali!',
            $created ? 201 : 200
        );
    }

    /** Lookup by phone for the member portal. Returns only what the portal shows; email masked. */
    public function publicLookup(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'org' => 'required|string',
            'phone' => 'required|string|max:20',
        ]);

        $org = Organization::query()->where('slug', $validated['org'])->first();
        if (!$org) {
            return $this->fail('Organisasi tidak ditemukan', null, 404);
        }
        $member = $this->members->findByPhone($org, $validated['phone']);
        if (!$member) {
            return $this->fail('Nomor HP tidak terdaftar sebagai member', null, 404);
        }

        return $this->ok(['member' => $this->presentPublic($member)], 'Member ditemukan');
    }

    private function filteredQuery(Request $request, Organization $org)
    {
        $query = PosMember::query()->forOrganization($org->id);
        $term = trim((string) $request->query('q', ''));
        if ($term !== '') {
            $query->where(fn ($q) => $this->applySearch($q, $term));
        }
        if ($request->filled('outlet_id')) {
            $outletId = (int) $request->query('outlet_id');
            $query->whereIn('id', fn ($q) => $q->select('member_id')->from('orders')
                ->where('outlet_id', $outletId)->whereNotNull('member_id')->whereNull('deleted_at'));
        }

        return match ((string) $request->query('sort', 'recent')) {
            'most_active' => $query->orderByDesc('total_orders')->orderByDesc('last_order_at'),
            'top_spend' => $query->orderByDesc('total_spent'),
            'points' => $query->orderByDesc('redeemable_points'),
            'name' => $query->orderBy('name'),
            default => $query->orderByDesc('last_order_at')->orderByDesc('created_at'),
        };
    }

    private function applySearch($query, string $term): void
    {
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $term) . '%';
        $query->where('name', 'like', $like)
            ->orWhere('email', 'like', $like)
            ->orWhere('phone', 'like', $like);
        $digits = PhoneNumber::normalize($term);
        if ($digits && strlen(preg_replace('/\D/', '', $term)) >= 4) {
            $query->orWhere('phone_normalized', 'like', '%' . ltrim(substr($digits, 2), '0') . '%');
        }
    }

    private function findMember(Organization $org, int $id): PosMember
    {
        return PosMember::query()->forOrganization($org->id)->findOrFail($id);
    }

    private function rewardRules(Organization $org)
    {
        return PosRewardRule::query()
            ->where('tenant_id', LoyaltyService::organizationSlug($org))
            ->where('is_active', true)
            ->get();
    }

    private function progress(PosRewardRule $rule, PosMember $member): int
    {
        return match ($rule->trigger_type) {
            'points_threshold' => (int) $member->total_points,
            'orders_threshold' => (int) $member->total_orders,
            'spend_threshold' => (int) $member->total_spent,
            default => 0,
        };
    }

    private function nextReward($rules, PosMember $member): ?array
    {
        $closest = null;
        $closestPct = 0;
        foreach ($rules as $rule) {
            $current = $this->progress($rule, $member);
            if ((int) $rule->trigger_value <= 0 || $current >= (int) $rule->trigger_value) {
                continue;
            }
            $pct = ($current / (int) $rule->trigger_value) * 100;
            if ($pct > $closestPct || $closest === null) {
                $closestPct = $pct;
                $closest = [
                    'reward_name' => $rule->name,
                    'trigger_type' => $rule->trigger_type,
                    'current' => $current,
                    'target' => (int) $rule->trigger_value,
                    'progress_pct' => round($pct),
                    'remaining' => (int) $rule->trigger_value - $current,
                ];
            }
        }

        return $closest;
    }

    /** @return array<string, mixed> */
    private function present(PosMember $m): array
    {
        return [
            'id' => $m->id,
            'name' => $m->name,
            'phone' => $m->phone_normalized ?: $m->phone,
            'email' => $m->email,
            'outlet_id' => $m->outlet_id,
            'total_points' => (int) $m->total_points,
            'redeemable_points' => (int) $m->redeemable_points,
            'total_orders' => (int) $m->total_orders,
            'total_spent' => (int) $m->total_spent,
            'tier' => $m->tier,
            'last_order_at' => optional($m->last_order_at)->toIso8601String(),
            'created_at' => optional($m->created_at)->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function presentPublic(PosMember $m): array
    {
        $email = (string) $m->email;
        $masked = $email !== '' && str_contains($email, '@')
            ? Str::substr($email, 0, 1) . '***@' . Str::after($email, '@')
            : null;

        return [
            'id' => $m->id,
            'name' => $m->name,
            'phone' => $m->phone_normalized ?: $m->phone,
            'email' => $masked,
            'total_points' => (int) $m->total_points,
            'redeemable_points' => (int) $m->redeemable_points,
            'total_orders' => (int) $m->total_orders,
        ];
    }

    /** @return array<string, mixed> */
    private function presentLedger(MemberPointTransaction $row): array
    {
        return [
            'id' => $row->id,
            'type' => $row->type,
            'type_label' => MemberPointTransaction::LABELS[$row->type] ?? $row->type,
            'points' => (int) $row->points,
            'balance_after' => (int) $row->balance_after,
            'reason' => $row->reason,
            'order_number' => $row->relationLoaded('order') ? $row->order?->order_number : null,
            'outlet' => $row->relationLoaded('outlet') ? $row->outlet?->name : null,
            'user' => $row->relationLoaded('user') ? $row->user?->name : null,
            'expires_at' => optional($row->expires_at)->toIso8601String(),
            'created_at' => optional($row->created_at)->toIso8601String(),
        ];
    }
}
