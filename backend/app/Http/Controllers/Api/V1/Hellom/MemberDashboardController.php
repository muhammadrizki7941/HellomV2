<?php

namespace App\Http\Controllers\Api\V1\Hellom;

use App\Models\AppCatalog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MemberDashboardController extends BaseApiController
{
    public function cards(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        $organizationId = (int) ($user->current_organization_id ?? 0);
        if ($organizationId <= 0) {
            return $this->fail('No active organization', ['code' => 'NO_ACTIVE_ORGANIZATION'], 403);
        }

        $apps = AppCatalog::query()
            ->where('is_active', true)
            ->with(['entitlements' => function ($query) use ($organizationId) {
                $query->where('organization_id', $organizationId)->with('plan');
            }])
            ->orderBy('id')
            ->get();

        $cards = $apps->map(function (AppCatalog $app) {
            $entitlement = $app->entitlements->first();
            $status = (string) ($entitlement?->status ?? 'locked');
            $allowed = in_array($status, ['active', 'trialing'], true);

            if ($allowed && $entitlement?->ends_at && $entitlement->ends_at->isPast()) {
                $allowed = false;
                $status = 'expired';
            }

            $action = $allowed ? 'open' : 'upgrade';

            return [
                'app' => [
                    'slug' => $app->slug,
                    'name' => $app->name,
                ],
                'entitlement' => [
                    'status' => $status,
                    'allowed' => $allowed,
                    'plan' => $entitlement?->plan ? [
                        'slug' => (string) $entitlement->plan->slug,
                        'name' => (string) $entitlement->plan->name,
                        'type' => (string) $entitlement->plan->type,
                        'price' => (int) $entitlement->plan->price,
                    ] : null,
                ],
                'card' => [
                    'badge' => $allowed ? 'Active' : 'Locked',
                    'action' => $action,
                    'action_label' => $allowed ? 'Open App' : 'Upgrade',
                ],
                'access' => $app->slug === 'pos' ? [
                    'dashboard_path' => '/dashboard/apps/pos',
                    'legacy_admin_path' => '/admin',
                    'legacy_cashier_path' => '/cashier/login',
                    'legacy_customer_path' => '/pos',
                ] : null,
            ];
        })->values();

        return $this->ok([
            'organization_id' => $organizationId,
            'cards' => $cards,
        ], 'Member dashboard cards');
    }
}
