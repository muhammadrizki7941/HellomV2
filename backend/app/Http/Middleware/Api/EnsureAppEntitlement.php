<?php

namespace App\Http\Middleware\Api;

use App\Models\AppCatalog;
use App\Models\Entitlement;
use App\Models\Organization;
use App\Models\User;
use App\Services\Hellom\PosProvisioningService;
use Closure;
use Illuminate\Http\Request;

class EnsureAppEntitlement
{
    public function handle(Request $request, Closure $next, string $slug)
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
                'data' => null,
                'error' => ['code' => 'UNAUTHORIZED'],
            ], 401);
        }

        $organizationId = (int) ($user->current_organization_id ?? 0);
        if ($organizationId <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'No active organization',
                'data' => null,
                'error' => ['code' => 'NO_ACTIVE_ORGANIZATION'],
            ], 403);
        }

        $organization = $user->currentOrganization;
        if ((!$organization instanceof Organization) || (int) $organization->id !== $organizationId) {
        $organization = Organization::query()
            ->select('id', 'name', 'slug', 'status', 'pos_tenant_slug')
            ->find($organizationId);
        }

        if ((!$organization instanceof Organization) || (string) $organization->status !== 'active') {
            return response()->json([
                'success' => false,
                'message' => 'Organization is not active',
                'data' => null,
                'error' => ['code' => 'ORG_INACTIVE'],
            ], 403);
        }

        $app = AppCatalog::query()->where('slug', $slug)->where('is_active', true)->first();
        if (!$app) {
            return response()->json([
                'success' => false,
                'message' => 'App not available',
                'data' => null,
                'error' => ['code' => 'APP_NOT_AVAILABLE'],
            ], 404);
        }

        $entitlement = Entitlement::query()
            ->where('organization_id', $organizationId)
            ->where('app_id', $app->id)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->first();

        $status = (string) ($entitlement->status ?? 'locked');
        $allowed = in_array($status, ['active', 'trialing'], true);

        if ($allowed && $entitlement?->ends_at && $entitlement->ends_at->isPast()) {
            $allowed = false;
            $status = 'expired';
        }

        if (!$allowed) {
            return response()->json([
                'success' => false,
                'message' => 'App is locked for current organization',
                'data' => [
                    'app' => $app->slug,
                    'status' => $status,
                ],
                'error' => ['code' => 'APP_LOCKED'],
            ], 403);
        }

        // Inject POS context if app is POS
        if ($app->slug === 'pos') {
            $organization = Organization::query()->find($organizationId);
            if ($organization) {
                if (empty($organization->pos_tenant_slug)) {
                    $organization = app(PosProvisioningService::class)->ensureProvisionedForPos($organizationId) ?: $organization;
                }

                $posTenantSlug = $organization->pos_tenant_slug;
                if (empty($posTenantSlug)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'POS belum siap untuk organisasi ini. Silakan klik Coba Lagi atau hubungi owner.',
                        'error' => ['code' => 'POS_NOT_PROVISIONED'],
                    ], 403);
                }
                $request->attributes->set('posTenantSlug', $posTenantSlug);
                $request->attributes->set('currentOrganizationId', $organizationId);
            }
        }

        $request->attributes->set('currentApp', $app);
        $request->attributes->set('currentEntitlement', $entitlement);

        return $next($request);
    }
}
