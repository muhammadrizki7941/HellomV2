<?php

namespace App\Http\Controllers\Api\V1\Hellom\Pos;

use App\Models\PosLoyaltySetting;
use App\Models\PosRewardRule;
use App\Models\PosMember;
use App\Services\OutletService;
use App\Services\Pos\LoyaltyService;
use App\Services\Pos\OrderService;
use App\Services\Pos\PricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PosLoyaltyController extends BasePosController
{
    public function calculatePoints(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'total_amount' => 'required|integer|min:0',
            'member_id'    => 'nullable|integer',
        ]);

        // Estimate only: points are awarded when the order is paid (LoyaltyService).
        $org = $this->getOrg($request);
        $loyalty = app(LoyaltyService::class);
        $pointsEarned = $loyalty->pointsForSpend($loyalty->settingsFor($org), (int) $validated['total_amount']);

        $availableRewards = [];
        $outlet = $this->posOutlet($request);
        if (!empty($validated['member_id']) && $outlet) {
            $member = PosMember::query()->forOrganization($org->id)->find($validated['member_id']);
            if ($member) {
                $availableRewards = app(OrderService::class)->eligibleRewardRules($outlet, $member)
                    ->map(fn (PosRewardRule $rule) => [
                        'id' => $rule->id,
                        'name' => $rule->name,
                        'description' => $rule->description,
                        'reward_type' => $rule->reward_type,
                        'reward_value' => $rule->reward_value,
                        'product' => $rule->scopedRewardProduct()?->name,
                    ])->values()->all();
            }
        }

        return $this->success([
            'points_to_earn' => $pointsEarned,
            'available_rewards' => $availableRewards,
        ], 'Kalkulasi poin berhasil');
    }

    public function applyReward(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'member_id'      => 'required|integer',
            'reward_rule_id' => 'required|integer',
            'total_amount'   => 'required|integer',
        ]);

        // Preview only: the order is priced again (and eligibility re-checked) by OrderService.
        $org = $this->getOrg($request);
        $outlet = $this->posOutlet($request);
        if (!$org || !$outlet) {
            return $this->error('Konteks POS tidak tersedia', 'CONTEXT_MISSING');
        }
        $member = PosMember::query()->forOrganization($org->id)->findOrFail($validated['member_id']);
        $rule = app(OrderService::class)->eligibleRewardRules($outlet, $member)->firstWhere('id', (int) $validated['reward_rule_id']);
        if (!$rule) {
            return $this->error('Reward tidak tersedia untuk member ini', 'REWARD_NOT_ELIGIBLE', null, 422);
        }

        $discountAmount = app(PricingService::class)->rewardDiscount($rule, (int) $validated['total_amount']);
        $freeProductId = $rule->reward_type === 'free_product' ? $rule->scopedRewardProduct()?->id : null;
        $finalAmount = max(0, (int) $validated['total_amount'] - $discountAmount);

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
            // Redemption: Rp per point (0 = off), limits per transaction, expiry in months (null = never).
            'redeem_value_per_point' => 'sometimes|integer|min:0|max:1000000',
            'min_redeem_points' => 'sometimes|integer|min:0|max:1000000',
            'max_redeem_points_per_order' => 'sometimes|nullable|integer|min:1|max:1000000',
            'points_expire_months' => 'sometimes|nullable|integer|min:1|max:120',
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
            'redeem_value_per_point' => $validated['redeem_value_per_point'] ?? $currentSettings->redeem_value_per_point ?? 0,
            'min_redeem_points' => $validated['min_redeem_points'] ?? $currentSettings->min_redeem_points ?? 0,
            'max_redeem_points_per_order' => array_key_exists('max_redeem_points_per_order', $validated)
                ? $validated['max_redeem_points_per_order']
                : $currentSettings->max_redeem_points_per_order,
            'points_expire_months' => array_key_exists('points_expire_months', $validated)
                ? $validated['points_expire_months']
                : $currentSettings->points_expire_months,
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
            'reward_product_id' => [
                'nullable',
                'integer',
                Rule::exists('products', 'id')->whereIn('tenant_id', app(OutletService::class)->tenantSlugs($org)),
            ],
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
            'reward_product_id' => [
                'nullable',
                'integer',
                Rule::exists('products', 'id')->whereIn('tenant_id', app(OutletService::class)->tenantSlugs($org)),
            ],
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
}
