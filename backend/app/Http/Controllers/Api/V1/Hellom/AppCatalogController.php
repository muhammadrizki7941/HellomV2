<?php

namespace App\Http\Controllers\Api\V1\Hellom;

use App\Models\AppCatalog;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppCatalogController extends BaseApiController
{
    public function index(Request $request): JsonResponse
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
            ->orderBy('id')
            ->get(['id', 'slug', 'name', 'type', 'price']);

        $apps = AppCatalog::query()
            ->where('is_active', true)
            ->with(['entitlements' => function ($query) use ($organizationId) {
                $query->where('organization_id', $organizationId)->with('plan');
            }])
            ->orderBy('id')
            ->get();

        $items = $apps->map(function (AppCatalog $app) use ($plans) {
            $entitlement = $app->entitlements->first();
            $status = (string) ($entitlement?->status ?? 'locked');
            $allowed = in_array($status, ['active', 'trialing'], true);

            $recommendedPlan = $this->recommendedPlanSlug($app->slug);
            $plan = $plans->firstWhere('slug', $recommendedPlan);

            return [
                'app' => [
                    'slug' => $app->slug,
                    'name' => $app->name,
                ],
                'entitlement' => [
                    'status' => $status,
                    'allowed' => $allowed,
                    'current_plan' => $entitlement?->plan ? [
                        'slug' => (string) $entitlement->plan->slug,
                        'name' => (string) $entitlement->plan->name,
                        'type' => (string) $entitlement->plan->type,
                        'price' => (int) $entitlement->plan->price,
                    ] : null,
                ],
                'cta' => [
                    'type' => $allowed ? 'open' : 'upgrade',
                    'label' => $allowed ? 'Open App' : 'Upgrade',
                    'target' => $allowed
                        ? '/apps/' . $app->slug
                        : '/pricing?app=' . $app->slug,
                    'recommended_plan' => $plan ? [
                        'slug' => (string) $plan->slug,
                        'name' => (string) $plan->name,
                        'type' => (string) $plan->type,
                        'price' => (int) $plan->price,
                    ] : null,
                ],
            ];
        })->values();

        return $this->ok([
            'organization_id' => $organizationId,
            'items' => $items,
        ], 'App catalog');
    }

    public function show(Request $request, string $slug): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        $organizationId = (int) ($user->current_organization_id ?? 0);
        if ($organizationId <= 0) {
            return $this->fail('No active organization', ['code' => 'NO_ACTIVE_ORGANIZATION'], 403);
        }

        $app = AppCatalog::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->with(['entitlements' => function ($query) use ($organizationId) {
                $query->where('organization_id', $organizationId)->with('plan');
            }])
            ->first();

        if (!$app) {
            return $this->fail('App not found', ['code' => 'APP_NOT_FOUND'], 404);
        }

        $entitlement = $app->entitlements->first();
        $status = (string) ($entitlement?->status ?? 'locked');
        $allowed = in_array($status, ['active', 'trialing'], true);

        $recommendedPlanSlug = $this->recommendedPlanSlug($app->slug);
        $recommendedPlan = Plan::query()
            ->where('slug', $recommendedPlanSlug)
            ->where('is_active', true)
            ->first();

        return $this->ok([
            'app' => [
                'slug' => $app->slug,
                'name' => $app->name,
                'is_active' => (bool) $app->is_active,
            ],
            'entitlement' => [
                'status' => $status,
                'allowed' => $allowed,
                'current_plan' => $entitlement?->plan ? [
                    'slug' => (string) $entitlement->plan->slug,
                    'name' => (string) $entitlement->plan->name,
                    'type' => (string) $entitlement->plan->type,
                    'price' => (int) $entitlement->plan->price,
                ] : null,
            ],
            'cta' => [
                'type' => $allowed ? 'open' : 'upgrade',
                'label' => $allowed ? 'Open App' : 'Upgrade',
                'target' => $allowed
                    ? '/apps/' . $app->slug
                    : '/pricing?app=' . $app->slug,
                'recommended_plan' => $recommendedPlan ? [
                    'slug' => (string) $recommendedPlan->slug,
                    'name' => (string) $recommendedPlan->name,
                    'type' => (string) $recommendedPlan->type,
                    'price' => (int) $recommendedPlan->price,
                ] : null,
            ],
        ], 'App detail');
    }

    private function recommendedPlanSlug(string $appSlug): string
    {
        return match ($appSlug) {
            'landing_builder' => 'free',
            'pos' => 'pos_starter_monthly',
            default => 'free',
        };
    }
}
