<?php

namespace App\Http\Controllers\Api\V1\Hellom;

use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\PromoCampaign;
use App\Models\PromoRedemption;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PromoCampaignController extends BaseApiController
{
    // ─── Admin CRUD (super admin only) ───

    public function index(Request $request): JsonResponse
    {
        $limit = max(1, min((int) ($request->query('limit') ?: 30), 100));

        $items = PromoCampaign::query()
            ->with(['app', 'plan'])
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return $this->ok(['items' => $items], 'Promo campaigns');
    }

    public function show(int $id): JsonResponse
    {
        $campaign = PromoCampaign::query()->with(['app', 'plan', 'redemptions'])->find($id);

        if (!$campaign) {
            return $this->fail('Promo campaign not found', ['code' => 'PROMO_NOT_FOUND'], 404);
        }

        return $this->ok($campaign, 'Promo campaign detail');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50', 'unique:promo_campaigns,code'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'type' => ['required', 'in:percentage,fixed'],
            'value' => ['required', 'integer', 'min:1'],
            'max_slots' => ['nullable', 'integer', 'min:1'],
            'app_id' => ['nullable', 'integer', 'exists:apps,id'],
            'plan_id' => ['nullable', 'integer', 'exists:plans,id'],
            'is_active' => ['boolean'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ]);

        $validated['code'] = strtoupper(trim($validated['code']));
        $validated['used_slots'] = 0;

        $campaign = PromoCampaign::query()->create($validated);

        $this->auditLog($request, 'promo_campaign.created', $campaign->id);

        return $this->ok($campaign->fresh(['app', 'plan']), __('hellom.promo_created'), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $campaign = PromoCampaign::query()->find($id);
        if (!$campaign) {
            return $this->fail('Promo campaign not found', ['code' => 'PROMO_NOT_FOUND'], 404);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'type' => ['sometimes', 'in:percentage,fixed'],
            'value' => ['sometimes', 'integer', 'min:1'],
            'max_slots' => ['nullable', 'integer', 'min:1'],
            'app_id' => ['nullable', 'integer', 'exists:apps,id'],
            'plan_id' => ['nullable', 'integer', 'exists:plans,id'],
            'is_active' => ['boolean'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
        ]);

        $campaign->update($validated);

        $this->auditLog($request, 'promo_campaign.updated', $campaign->id);

        return $this->ok($campaign->fresh(['app', 'plan']), __('hellom.promo_updated'));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $campaign = PromoCampaign::query()->find($id);
        if (!$campaign) {
            return $this->fail('Promo campaign not found', ['code' => 'PROMO_NOT_FOUND'], 404);
        }

        $campaign->delete();

        $this->auditLog($request, 'promo_campaign.deleted', $id);

        return $this->ok(null, __('hellom.promo_deleted'));
    }

    // ─── Public: Validate Promo Code ───

    public function validateCode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50'],
            'app_id' => ['nullable', 'integer'],
            'plan_id' => ['nullable', 'integer'],
            'amount' => ['required', 'integer', 'min:0'],
        ]);

        $campaign = $this->findValidCampaign(
            strtoupper(trim($validated['code'])),
            $validated['app_id'] ?? null,
            $validated['plan_id'] ?? null,
        );

        if (!$campaign) {
            return $this->fail(__('hellom.promo_invalid'), ['code' => 'PROMO_INVALID'], 422);
        }

        $discount = $this->calculateDiscount($campaign, (int) $validated['amount']);

        return $this->ok([
            'code' => $campaign->code,
            'name' => $campaign->name,
            'type' => $campaign->type,
            'value' => (int) $campaign->value,
            'discount_amount' => $discount,
            'final_amount' => max(0, (int) $validated['amount'] - $discount),
        ], 'Promo code valid');
    }

    // ─── Helpers ───

    public static function findValidCampaign(string $code, ?int $appId = null, ?int $planId = null): ?PromoCampaign
    {
        $query = PromoCampaign::query()
            ->where('code', $code)
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', now());
            })
            ->where(function ($q) {
                $q->whereNull('max_slots')
                    ->orWhereColumn('used_slots', '<', 'max_slots');
            });

        if ($appId) {
            $query->where(function ($q) use ($appId) {
                $q->whereNull('app_id')->orWhere('app_id', $appId);
            });
        }

        if ($planId) {
            $query->where(function ($q) use ($planId) {
                $q->whereNull('plan_id')->orWhere('plan_id', $planId);
            });
        }

        return $query->first();
    }

    public static function calculateDiscount(PromoCampaign $campaign, int $amount): int
    {
        if ($campaign->type === 'percentage') {
            return (int) floor($amount * min((int) $campaign->value, 100) / 100);
        }

        // fixed discount
        return min((int) $campaign->value, $amount);
    }

    private function auditLog(Request $request, string $action, int|string $targetId): void
    {
        $user = $request->user();
        if ($user instanceof User) {
            AuditLog::query()->create([
                'user_id' => $user->id,
                'action' => $action,
                'target_type' => 'promo_campaign',
                'target_id' => (string) $targetId,
                'metadata' => ['ip' => $request->ip()],
            ]);
        }
    }
}
