<?php

namespace App\Services\Pos;

use App\Models\AuditLog;
use App\Models\MemberPointTransaction;
use App\Models\Order;
use App\Models\Organization;
use App\Models\PosMember;
use App\Models\PosRedemption;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;

/**
 * Members belong to the organization: one phone number (normalised to 628…) = one member
 * across all of its outlets, and the same number can be a different member elsewhere.
 * Cashier, self-order and the public register page all go through register(), so they
 * behave identically.
 */
final class MemberService
{
    public function findByPhone(Organization $organization, ?string $phone): ?PosMember
    {
        $normalized = PhoneNumber::normalize($phone);
        if (!$normalized) {
            return null;
        }

        return PosMember::query()
            ->forOrganization($organization->id)
            ->where('phone_normalized', $normalized)
            ->orderBy('id')
            ->first();
    }

    /**
     * Find-or-create by phone. Returns [member, created]. When the phone is already a
     * member the existing record is returned untouched (name is not overwritten).
     *
     * @return array{0: PosMember, 1: bool}
     */
    public function register(Organization $organization, string $name, ?string $phone, ?string $email = null, ?int $outletId = null, ?string $outletSlug = null): array
    {
        $normalized = PhoneNumber::normalize($phone);

        return DB::transaction(function () use ($organization, $name, $phone, $email, $outletId, $outletSlug, $normalized) {
            // Serialise registrations per organization so two devices can't create the same phone twice.
            Organization::query()->whereKey($organization->id)->lockForUpdate()->first();

            if ($normalized) {
                $existing = $this->findByPhone($organization, $normalized);
                if ($existing) {
                    return [$existing, false];
                }
            }

            $member = PosMember::query()->create([
                'organization_id' => $organization->id,
                'outlet_id' => $outletId,
                'tenant_id' => $outletSlug ?: LoyaltyService::organizationSlug($organization),
                'name' => trim($name),
                'phone' => $normalized ?? ($phone ? trim($phone) : null),
                'phone_normalized' => $normalized,
                'email' => $email ?: null,
                'total_points' => 0,
                'total_orders' => 0,
                'total_spent' => 0,
                'redeemable_points' => 0,
            ]);

            return [$member, true];
        });
    }

    /** Phone numbers shared by more than one active member (for the owner to merge manually). */
    public function duplicates(Organization $organization): array
    {
        $phones = PosMember::query()
            ->forOrganization($organization->id)
            ->whereNotNull('phone_normalized')
            ->select('phone_normalized', DB::raw('COUNT(*) as total'))
            ->groupBy('phone_normalized')
            ->having('total', '>', 1)
            ->pluck('phone_normalized');

        return $phones->map(fn ($phone) => [
            'phone' => $phone,
            'members' => PosMember::query()
                ->forOrganization($organization->id)
                ->where('phone_normalized', $phone)
                ->orderBy('id')
                ->get(['id', 'name', 'phone', 'redeemable_points', 'total_orders', 'total_spent', 'last_order_at', 'created_at'])
                ->toArray(),
        ])->values()->all();
    }

    /**
     * Merge $source into $target (owner action). Orders and redemptions move to the target,
     * the source's point balance is transferred through the ledger, and the source is kept
     * (marked merged) for history. Audit-logged.
     */
    public function merge(Organization $organization, PosMember $target, PosMember $source, int $userId, string $reason, ?string $ip = null, ?string $userAgent = null): PosMember
    {
        if ($target->id === $source->id) {
            throw new PricingException('Tidak bisa menggabungkan member dengan dirinya sendiri.', [], 'MEMBER_MERGE_SELF');
        }

        return DB::transaction(function () use ($organization, $target, $source, $userId, $reason, $ip, $userAgent) {
            $ids = [$target->id, $source->id];
            sort($ids);
            $locked = PosMember::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $keep = $locked[$target->id] ?? null;
            $drop = $locked[$source->id] ?? null;
            foreach ([$keep, $drop] as $member) {
                if (!$member || (int) $member->organization_id !== $organization->id || $member->merged_into_id) {
                    throw new PricingException('Member tidak ditemukan atau sudah digabung.', [], 'MEMBER_NOT_FOUND', 404);
                }
            }

            $before = ['target' => $keep->only(['id', 'name', 'phone', 'redeemable_points', 'total_orders', 'total_spent']),
                'source' => $drop->only(['id', 'name', 'phone', 'redeemable_points', 'total_orders', 'total_spent'])];

            $balance = (int) MemberPointTransaction::query()->where('member_id', $drop->id)->sum('points');
            if ($balance !== 0) {
                // Close the source's open lots, then move the balance as one ledger pair.
                MemberPointTransaction::query()->where('member_id', $drop->id)->where('remaining_points', '>', 0)->update(['remaining_points' => 0]);
                $out = MemberPointTransaction::query()->create([
                    'organization_id' => $organization->id, 'member_id' => $drop->id, 'type' => MemberPointTransaction::TYPE_ADJUST,
                    'points' => -$balance, 'balance_after' => 0, 'reason' => "Digabung ke member #{$keep->id}: {$reason}", 'user_id' => $userId,
                    'idempotency_key' => "merge:out:{$drop->id}",
                ]);
                $targetBalance = (int) MemberPointTransaction::query()->where('member_id', $keep->id)->sum('points') + $balance;
                MemberPointTransaction::query()->create([
                    'organization_id' => $organization->id, 'member_id' => $keep->id, 'type' => MemberPointTransaction::TYPE_ADJUST,
                    'points' => $balance, 'balance_after' => $targetBalance, 'remaining_points' => max(0, $balance),
                    'reason' => "Gabungan dari member #{$drop->id}: {$reason}", 'user_id' => $userId,
                    'reverses_id' => $out->id, 'idempotency_key' => "merge:in:{$drop->id}",
                ]);
                $keep->redeemable_points = $targetBalance;
            }

            Order::withoutGlobalScope('tenant')->where('member_id', $drop->id)->update(['member_id' => $keep->id]);
            PosRedemption::query()->where('member_id', $drop->id)->update(['member_id' => $keep->id]);

            $keep->total_points = (int) $keep->total_points + (int) $drop->total_points;
            $keep->total_orders = (int) $keep->total_orders + (int) $drop->total_orders;
            $keep->total_spent = (int) $keep->total_spent + (int) $drop->total_spent;
            $keep->last_order_at = max($keep->last_order_at, $drop->last_order_at);
            if (!$keep->email && $drop->email) {
                $keep->email = $drop->email;
            }
            $keep->save();

            $drop->forceFill(['merged_into_id' => $keep->id, 'merged_at' => now(), 'redeemable_points' => 0])->save();

            AuditLog::record('pos.member.merged', $userId, $organization->id, 'pos_member', $keep->id, $before,
                ['target' => $keep->fresh()->only(['id', 'name', 'phone', 'redeemable_points', 'total_orders', 'total_spent'])],
                ['source_member_id' => $drop->id, 'points_moved' => $balance, 'reason' => $reason], $ip, $userAgent);

            return $keep->fresh();
        });
    }
}
