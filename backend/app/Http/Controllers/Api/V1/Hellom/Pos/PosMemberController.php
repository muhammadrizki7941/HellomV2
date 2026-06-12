<?php

namespace App\Http\Controllers\Api\V1\Hellom\Pos;

use App\Models\PosMember;
use App\Models\PosPointTransaction;
use App\Models\PosRewardRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PosMemberController extends BasePosController
{
    public function index(Request $request): JsonResponse
    {
        $org = $this->getOrg($request);
        $tenantSlug = $this->getTenantSlug($org);

        $members = PosMember::where('tenant_id', $tenantSlug)
            ->orderByDesc('last_order_at')
            ->orderByDesc('created_at')
            ->paginate(20);

        return $this->success($members, 'Members retrieved');
    }

    public function search(Request $request): JsonResponse
    {
        $org = $this->getOrg($request);
        $tenantSlug = $this->getTenantSlug($org);
        $query = $request->input('q', '');

        if (strlen($query) < 2) {
            return response()->json([
                'success' => true,
                'data' => ['members' => []],
                'message' => 'Ketik minimal 2 karakter',
            ]);
        }

        $members = PosMember::where('tenant_id', $tenantSlug)
            ->where(function($q) use ($query) {
                $q->where('name', 'LIKE', "%{$query}%")
                  ->orWhere('phone', 'LIKE', "%{$query}%")
                  ->orWhere('email', 'LIKE', "%{$query}%");
            })
            ->limit(10)
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'members' => $members->map(fn($m) => [
                    'id'           => $m->id,
                    'name'         => $m->name,
                    'phone'        => $m->phone,
                    'email'        => $m->email,
                    'total_points' => $m->total_points,
                    'total_orders' => $m->total_orders,
                    'total_spent'  => $m->total_spent,
                ])
            ],
            'message' => 'Hasil pencarian',
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $org = $this->getOrg($request);
        $tenantSlug = $this->getTenantSlug($org);

        $validated = $request->validate([
            'name'  => 'required|string|max:100',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:100',
        ]);

        // Cek duplikat phone
        if (!empty($validated['phone'])) {
            $exists = PosMember::where('tenant_id', $tenantSlug)
                ->where('phone', $validated['phone'])
                ->exists();
            if ($exists) {
                return response()->json([
                    'success' => false,
                    'message' => 'Nomor HP sudah terdaftar sebagai member',
                ], 422);
            }
        }

        $member = PosMember::create([
            'tenant_id' => $tenantSlug,
            ...$validated,
        ]);

        return response()->json([
            'success' => true,
            'data'    => ['member' => $member],
            'message' => 'Member berhasil didaftarkan! 🎉',
        ], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $org = $this->getOrg($request);
        $tenantSlug = $this->getTenantSlug($org);

        $member = PosMember::where('tenant_id', $tenantSlug)
            ->findOrFail($id);

        // Cek reward yang tersedia untuk member ini
        $availableRewards = $this->getAvailableRewards($tenantSlug, $member);

        // Riwayat poin terbaru
        $recentPoints = PosPointTransaction::where('member_id', $id)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        // Progress menuju reward berikutnya
        $nextReward = $this->getNextRewardProgress($tenantSlug, $member);

        return response()->json([
            'success' => true,
            'data' => [
                'member'           => $member,
                'available_rewards'=> $availableRewards,
                'recent_points'    => $recentPoints,
                'next_reward'      => $nextReward,
            ],
            'message' => 'Data member berhasil dimuat',
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $org = $this->getOrg($request);
        $tenantSlug = $this->getTenantSlug($org);

        $member = PosMember::where('tenant_id', $tenantSlug)
            ->findOrFail($id);

        $validated = $request->validate([
            'name'  => 'required|string|max:100',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:100',
        ]);

        $member->update($validated);

        return $this->success(['member' => $member], 'Member updated');
    }

    public function pointHistory(Request $request, int $id): JsonResponse
    {
        $org = $this->getOrg($request);
        $tenantSlug = $this->getTenantSlug($org);

        // Verify member belongs to tenant
        PosMember::where('tenant_id', $tenantSlug)->findOrFail($id);

        $transactions = PosPointTransaction::where('member_id', $id)
            ->with(['order'])
            ->orderByDesc('created_at')
            ->paginate(20);

        return $this->success($transactions, 'Point history retrieved');
    }

    private function getAvailableRewards(string $tenantSlug, PosMember $member): array
    {
        $rules = PosRewardRule::where('tenant_id', $tenantSlug)
            ->where('is_active', true)
            ->get();

        $available = [];
        foreach ($rules as $rule) {
            $qualified = false;

            switch ($rule->trigger_type) {
                case 'points_threshold':
                    $qualified = $member->total_points >= $rule->trigger_value;
                    break;
                case 'orders_threshold':
                    $qualified = $member->total_orders >= $rule->trigger_value;
                    break;
                case 'spend_threshold':
                    $qualified = $member->total_spent >= $rule->trigger_value;
                    break;
            }

            if ($qualified) {
                $available[] = [
                    'id'           => $rule->id,
                    'name'         => $rule->name,
                    'description'  => $rule->description,
                    'reward_type'  => $rule->reward_type,
                    'reward_value' => $rule->reward_value,
                    'product'      => $rule->reward_product_id
                        ? \App\Models\Product::find($rule->reward_product_id)?->name
                        : null,
                ];
            }
        }

        return $available;
    }

    private function getNextRewardProgress(string $tenantSlug, PosMember $member): ?array
    {
        // Cari reward terdekat yang belum tercapai
        $rules = PosRewardRule::where('tenant_id', $tenantSlug)
            ->where('is_active', true)
            ->get();

        $closest = null;
        $closestPct = 0;

        foreach ($rules as $rule) {
            $current = match($rule->trigger_type) {
                'points_threshold' => $member->total_points,
                'orders_threshold' => $member->total_orders,
                'spend_threshold'  => $member->total_spent,
                default => 0,
            };

            if ($current >= $rule->trigger_value) continue;

            $pct = ($current / $rule->trigger_value) * 100;
            if ($pct > $closestPct) {
                $closestPct = $pct;
                $closest = [
                    'reward_name'  => $rule->name,
                    'trigger_type' => $rule->trigger_type,
                    'current'      => $current,
                    'target'       => $rule->trigger_value,
                    'progress_pct' => round($pct),
                    'remaining'    => $rule->trigger_value - $current,
                ];
            }
        }

        return $closest;
    }
}