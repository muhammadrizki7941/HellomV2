<?php

namespace App\Http\Controllers\Api\V1\Hellom;

use App\Models\AppCatalog;
use App\Models\Entitlement;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PricingController extends BaseApiController
{
    public function matrix(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        $organizationId = (int) ($user->current_organization_id ?? 0);
        if ($organizationId <= 0) {
            return $this->fail('No active organization', ['code' => 'NO_ACTIVE_ORGANIZATION'], 403);
        }

        $plans = Plan::query()
            ->where('is_active', true)
            ->where('is_visible', true)
            ->orderBy('sort_order')
            ->orderBy('price')
            ->get();

        $apps = AppCatalog::query()
            ->where('is_active', true)
            ->with(['entitlements' => function ($query) use ($organizationId) {
                $query->where('organization_id', $organizationId)->with('plan');
            }])
            ->orderBy('id')
            ->get();

        $items = $apps->map(function (AppCatalog $app) use ($plans) {
            $entitlement = $app->entitlements->first();
            $currentStatus = (string) ($entitlement?->status ?? 'locked');

            if (in_array($currentStatus, ['active', 'trialing'], true) && $entitlement?->ends_at && $entitlement->ends_at->isPast()) {
                $currentStatus = 'expired';
            }

            $isCurrentPlanActive = in_array($currentStatus, ['active', 'trialing'], true);
            $currentPlanSlug = $isCurrentPlanActive ? (string) ($entitlement?->plan?->slug ?? '') : '';

            $availablePlans = $plans
                ->filter(fn (Plan $plan) => $this->planEligibleForApp($plan->slug, $app->slug))
                ->map(fn (Plan $plan) => [
                    'id' => (int) $plan->id,
                    'slug' => (string) $plan->slug,
                    'name' => (string) $plan->name,
                    'type' => (string) $plan->type,
                    'price' => (int) $plan->price,
                    'description' => (string) ($plan->description ?? ''),
                    'features' => $plan->features ?? [],
                    'billing_cycles' => $plan->billing_cycles ?? [],
                    'duration_days' => $plan->duration_days,
                    'is_recommended' => (bool) ($plan->is_recommended ?? false),
                    'sort_order' => (int) ($plan->sort_order ?? 0),
                    'is_current' => $plan->slug === $currentPlanSlug,
                ])
                ->values();

            return [
                'app' => [
                    'id' => (int) $app->id,
                    'slug' => (string) $app->slug,
                    'name' => (string) $app->name,
                ],
                'current' => [
                    'status' => $currentStatus,
                    'plan_slug' => $currentPlanSlug !== '' ? $currentPlanSlug : null,
                ],
                'plans' => $availablePlans,
            ];
        })->values();

        return $this->ok([
            'organization_id' => $organizationId,
            'items' => $items,
        ], 'Pricing matrix');
    }

    public function previewUpgrade(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        $organizationId = (int) ($user->current_organization_id ?? 0);
        if ($organizationId <= 0) {
            return $this->fail('No active organization', ['code' => 'NO_ACTIVE_ORGANIZATION'], 403);
        }

        $validated = $request->validate([
            'app_slug' => ['required', 'string'],
            'plan_slug' => ['required', 'string'],
        ]);

        $app = AppCatalog::query()
            ->where('slug', (string) $validated['app_slug'])
            ->where('is_active', true)
            ->first();

        if (!$app) {
            return $this->fail('App not found', ['code' => 'APP_NOT_FOUND'], 404);
        }

        $plan = Plan::query()
            ->where('slug', (string) $validated['plan_slug'])
            ->where('is_active', true)
            ->first();

        if (!$plan) {
            return $this->fail('Plan not found', ['code' => 'PLAN_NOT_FOUND'], 404);
        }

        if (!$this->planEligibleForApp((string) $plan->slug, (string) $app->slug)) {
            return $this->fail('Plan is not eligible for selected app', ['code' => 'PLAN_NOT_ELIGIBLE'], 422);
        }

        $entitlement = Entitlement::query()
            ->with('plan')
            ->where('organization_id', $organizationId)
            ->where('app_id', $app->id)
            ->first();

        $currentStatus = (string) ($entitlement?->status ?? 'locked');
        $currentPlanSlug = (string) ($entitlement?->plan?->slug ?? '');
        $targetStatus = in_array((string) $plan->type, ['free', 'subscription', 'one_time'], true) ? 'active' : 'locked';

        return $this->ok([
            'organization_id' => $organizationId,
            'app' => [
                'slug' => (string) $app->slug,
                'name' => (string) $app->name,
            ],
            'current' => [
                'status' => $currentStatus,
                'plan_slug' => $currentPlanSlug !== '' ? $currentPlanSlug : null,
            ],
            'target' => [
                'plan_slug' => (string) $plan->slug,
                'plan_name' => (string) $plan->name,
                'plan_type' => (string) $plan->type,
                'price' => (int) $plan->price,
                'result_status' => $targetStatus,
            ],
            'diff' => [
                'will_change_plan' => $currentPlanSlug !== (string) $plan->slug,
                'will_unlock_app' => in_array($currentStatus, ['locked', 'expired', 'cancelled'], true) && $targetStatus === 'active',
            ],
            'next_step' => [
                'action' => 'proceed_checkout',
                'target' => '/checkout?app='.(string) $app->slug.'&plan='.(string) $plan->slug,
            ],
        ], 'Upgrade preview');
    }

    private function planEligibleForApp(string $planSlug, string $appSlug): bool
    {
        return match ($appSlug) {
            'landing_builder' => $planSlug === 'free',
            'pos' => str_starts_with($planSlug, 'pos_'),
            default => false,
        };
    }
}
