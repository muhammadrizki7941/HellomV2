<?php

namespace App\Services\Billing;

use App\Http\Controllers\Api\V1\Hellom\PromoCampaignController;
use App\Models\CheckoutIntent;
use App\Models\PromoCampaign;
use App\Models\PromoRedemption;
class PromoCheckoutService
{
    public function preview(string $code, int $appId, int $planId, int $amount, ?int $organizationId = null): ?array
    {
        $campaign = PromoCampaignController::findValidCampaign($code, $appId, $planId);
        if (!$campaign instanceof PromoCampaign) {
            return null;
        }

        if ($organizationId && PromoRedemption::query()
            ->where('promo_campaign_id', $campaign->id)
            ->where('organization_id', $organizationId)
            ->exists()) {
            return null;
        }

        $discount = PromoCampaignController::calculateDiscount($campaign, $amount);

        return [
            'id' => (int) $campaign->id,
            'code' => (string) $campaign->code,
            'name' => (string) $campaign->name,
            'discount_amount' => $discount,
            'final_amount' => max(0, $amount - $discount),
        ];
    }

    public function reserveAndRedeem(CheckoutIntent $intent): void
    {
        $promo = data_get($intent->metadata, 'promo');
        if (!is_array($promo) || (int) ($promo['id'] ?? 0) <= 0) {
            return;
        }

        $campaign = PromoCampaign::query()->lockForUpdate()->find((int) $promo['id']);
        if (!$campaign instanceof PromoCampaign) {
            return;
        }

        $alreadyRedeemed = PromoRedemption::query()
            ->where('promo_campaign_id', $campaign->id)
            ->where('organization_id', $intent->organization_id)
            ->exists();
        if ($alreadyRedeemed) {
            return;
        }

        PromoRedemption::query()->create([
            'promo_campaign_id' => $campaign->id,
            'organization_id' => (int) $intent->organization_id,
            'user_id' => (int) $intent->user_id,
            'subscription_id' => $intent->subscription_id,
            'discount_amount' => (int) ($promo['discount_amount'] ?? 0),
        ]);

        if ($campaign->max_slots === null || (int) $campaign->used_slots < (int) $campaign->max_slots) {
            $campaign->increment('used_slots');
        }
    }
}
