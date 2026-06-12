<?php

namespace App\Http\Controllers\Api\V1\Hellom\Pos;

use App\Models\PosLoyaltySetting;
use App\Models\PosRewardRule;
use App\Models\PosMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PosLoyaltyController extends BasePosController
{
    public function calculatePoints(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'total_amount' => 'required|integer|min:0',
            'member_id'    => 'nullable|integer',
        ]);

        $org = $this->getOrg($request);
        $tenantSlug = $this->getTenantSlug($org);

        // Aturan poin: default 1 poin per Rp 1.000
        $settings = PosLoyaltySetting::currentForTenant($tenantSlug);
        $pointsEarned = $this->calculatePointsToEarn($settings, (int) $validated['total_amount']);

        // Cek reward yang tersedia jika ada member
        $availableRewards = [];
        if (!empty($validated['member_id'])) {
            $member = PosMember::where('tenant_id', $tenantSlug)
                ->find($validated['member_id']);
            if ($member) {
                $availableRewards = $this->getAvailableRewards($tenantSlug, $member);
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'points_to_earn'    => $pointsEarned,
                'available_rewards' => $availableRewards,
            ],
            'message' => 'Kalkulasi poin berhasil',
        ]);
    }

    public function applyReward(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'member_id'      => 'required|integer',
            'reward_rule_id' => 'required|integer',
            'total_amount'   => 'required|integer',
        ]);

        $org = $this->getOrg($request);
        $tenantSlug = $this->getTenantSlug($org);

        $member = PosMember::where('tenant_id', $tenantSlug)
            ->findOrFail($validated['member_id']);

        $rule = PosRewardRule::where('tenant_id', $tenantSlug)
            ->where('is_active', true)
            ->findOrFail($validated['reward_rule_id']);

        // Hitung diskon
        $discountAmount = 0;
        $freeProductId = null;

        switch ($rule->reward_type) {
            case 'discount_percent':
                $discountAmount = (int) round(
                    $validated['total_amount'] * $rule->reward_value / 100
                );
                break;
            case 'discount_fixed':
                $discountAmount = min($rule->reward_value, $validated['total_amount']);
                break;
            case 'free_product':
                $freeProductId = $rule->reward_product_id;
                $product = \App\Models\Product::find($freeProductId);
                $discountAmount = $product?->price ?? 0;
                break;
        }

        $finalAmount = max(0, $validated['total_amount'] - $discountAmount);

        return response()->json([
            'success' => true,
            'data' => [
                'reward'          => [
                    'id'          => $rule->id,
                    'name'        => $rule->name,
                    'type'        => $rule->reward_type,
                ],
                'discount_amount' => $discountAmount,
                'final_amount'    => $finalAmount,
                'free_product_id' => $freeProductId,
            ],
            'message' => "Reward '{$rule->name}' berhasil diterapkan!",
        ]);
    }

    public function getSettings(Request $request): JsonResponse
    {
        $org = $this->getOrg($request);
        $tenantSlug = $this->getTenantSlug($org);

        $settings = PosLoyaltySetting::currentForTenant($tenantSlug)->toPosPayload();

        return $this->success($settings, 'Loyalty settings retrieved');
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'points_per_amount' => 'integer|min:1|max:100000',
            'enabled' => 'boolean',
            'min_spend_amount' => 'nullable|integer|min:0|max:2000000000',
            'max_points_per_order' => 'nullable|integer|min:1|max:1000000',
        ]);

        $org = $this->getOrg($request);
        $tenantSlug = $this->getTenantSlug($org);

        $currentSettings = PosLoyaltySetting::currentForTenant($tenantSlug);

        $settings = PosLoyaltySetting::persistForTenant($tenantSlug, [
            'enabled' => $validated['enabled'] ?? $currentSettings->enabled,
            'points_per_amount' => $validated['points_per_amount'] ?? $currentSettings->points_per_amount,
            'min_spend_amount' => $validated['min_spend_amount'] ?? $currentSettings->min_spend_amount,
            'max_points_per_order' => array_key_exists('max_points_per_order', $validated)
                ? $validated['max_points_per_order']
                : $currentSettings->max_points_per_order,
        ]);

        return $this->success($settings->toPosPayload(), 'Settings updated');
    }

    public function rewardRules(Request $request): JsonResponse
    {
        $org = $this->getOrg($request);
        $tenantSlug = $this->getTenantSlug($org);

        $rules = PosRewardRule::where('tenant_id', $tenantSlug)
            ->orderBy('created_at')
            ->get();

        return $this->success($rules, 'Reward rules retrieved');
    }

    public function storeRewardRule(Request $request): JsonResponse
    {
        $org = $this->getOrg($request);
        $tenantSlug = $this->getTenantSlug($org);

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'trigger_type' => 'required|in:points_threshold,orders_threshold,spend_threshold',
            'trigger_value' => 'required|integer|min:1',
            'reward_type' => 'required|in:free_product,discount_percent,discount_fixed,bonus_points',
            'reward_value' => 'required|integer|min:1',
            'reward_product_id' => 'nullable|integer',
            'description' => 'nullable|string|max:255',
        ]);

        $rule = PosRewardRule::create([
            'tenant_id' => $tenantSlug,
            ...$validated,
        ]);

        return $this->success($rule, 'Reward rule created', 201);
    }

    public function updateRewardRule(Request $request, int $id): JsonResponse
    {
        $org = $this->getOrg($request);
        $tenantSlug = $this->getTenantSlug($org);

        $rule = PosRewardRule::where('tenant_id', $tenantSlug)
            ->findOrFail($id);

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'trigger_type' => 'required|in:points_threshold,orders_threshold,spend_threshold',
            'trigger_value' => 'required|integer|min:1',
            'reward_type' => 'required|in:free_product,discount_percent,discount_fixed,bonus_points',
            'reward_value' => 'required|integer|min:1',
            'reward_product_id' => 'nullable|integer',
            'description' => 'nullable|string|max:255',
            'is_active' => 'boolean',
        ]);

        $rule->update($validated);

        return $this->success($rule, 'Reward rule updated');
    }

    public function deleteRewardRule(Request $request, int $id): JsonResponse
    {
        $org = $this->getOrg($request);
        $tenantSlug = $this->getTenantSlug($org);

        $rule = PosRewardRule::where('tenant_id', $tenantSlug)
            ->findOrFail($id);

        $rule->delete();

        return $this->success(null, 'Reward rule deleted');
    }

    private function calculatePointsToEarn(PosLoyaltySetting $settings, int $amount): int
    {
        if (!$settings->enabled) {
            return 0;
        }

        if ($amount < (int) $settings->min_spend_amount) {
            return 0;
        }

        $pointsPerAmount = max(1, (int) $settings->points_per_amount);
        $points = (int) floor($amount / $pointsPerAmount);

        if ($settings->max_points_per_order !== null) {
            $points = min($points, (int) $settings->max_points_per_order);
        }

        return max(0, $points);
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
}
