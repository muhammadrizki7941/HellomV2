<?php

namespace App\Http\Controllers\Api\V1\Hellom;

use App\Models\Entitlement;
use App\Models\User;
use App\Services\Hellom\PosProvisioningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class EntitlementController extends BaseApiController
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

        $organization = $user->currentOrganization;
        if (!$organization || (int) $organization->id !== $organizationId || (string) $organization->status !== 'active') {
            return $this->fail('Organization is not active', ['code' => 'ORG_INACTIVE'], 403);
        }

        $items = Entitlement::query()
            ->with(['app:id,slug,name,is_active', 'plan:id,slug,name,type,price'])
            ->where('organization_id', $organizationId)
            ->orderBy('id')
            ->get()
            ->map(function (Entitlement $entitlement): array {
                return [
                    'app' => [
                        'slug' => (string) ($entitlement->app->slug ?? ''),
                        'name' => (string) ($entitlement->app->name ?? ''),
                        'is_active' => (bool) ($entitlement->app->is_active ?? false),
                    ],
                    'plan' => $entitlement->plan ? [
                        'slug' => (string) $entitlement->plan->slug,
                        'name' => (string) $entitlement->plan->name,
                        'type' => (string) $entitlement->plan->type,
                        'price' => (int) $entitlement->plan->price,
                    ] : null,
                    'status' => (string) $entitlement->status,
                    'starts_at' => $entitlement->starts_at,
                    'ends_at' => $entitlement->ends_at,
                ];
            })
            ->values();

        return $this->ok($items, 'Entitlements');
    }

    public function check(Request $request, string $slug): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        $organizationId = (int) ($user->current_organization_id ?? 0);
        if ($organizationId <= 0) {
            return $this->fail('No active organization', ['code' => 'NO_ACTIVE_ORGANIZATION'], 403);
        }

        $entitlement = Entitlement::query()
            ->with(['app:id,slug,name', 'plan:id,slug,name,type'])
            ->where('organization_id', $organizationId)
            ->whereHas('app', fn($query) => $query->where('slug', $slug))
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->first();

        if (!$entitlement) {
            return $this->ok([
                'app' => $slug,
                'status' => 'locked',
                'allowed' => false,
            ], 'Entitlement status');
        }

        $status = (string) $entitlement->status;
        $allowed = in_array($status, ['active', 'trialing'], true);

        if ($allowed && $entitlement->ends_at && $entitlement->ends_at->isPast()) {
            $allowed = false;
            $status = 'expired';
        }

        return $this->ok([
            'app' => (string) ($entitlement->app->slug ?? $slug),
            'status' => $status,
            'allowed' => $allowed,
            'plan' => $entitlement->plan ? [
                'slug' => (string) $entitlement->plan->slug,
                'name' => (string) $entitlement->plan->name,
                'type' => (string) $entitlement->plan->type,
            ] : null,
        ], 'Entitlement status');
    }

    public function probeAllowed(Request $request): JsonResponse
    {
        return $this->ok([
            'app' => 'landing_builder',
            'allowed' => true,
        ], 'Access granted by canUseApp middleware');
    }

    public function probeLocked(Request $request): JsonResponse
    {
        return $this->ok([
            'app' => 'pos',
            'allowed' => true,
        ], 'If you see this, POS is unlocked');
    }

    public function posAccess(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        $organization = $user->currentOrganization;
        $organizationId = (int) ($organization?->id ?? 0);
        if ($organizationId <= 0 || (string) ($organization?->status ?? '') !== 'active') {
            return $this->fail('Organization is not active', ['code' => 'ORG_INACTIVE'], 403);
        }

        $provisionedOrganization = app(PosProvisioningService::class)->ensureProvisionedForPos($organizationId);
        if ($provisionedOrganization) {
            $organization = $provisionedOrganization;
        }

        // Generate SSO token for seamless login
        $ssoToken = Str::random(64);
        $expiresAt = now()->addHours(1);

        // Store SSO token (in cache or DB for POS app to verify)
        cache()->put("pos_sso:{$ssoToken}", [
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'role' => $user->role,
            'expires_at' => $expiresAt,
        ], $expiresAt);

        return $this->ok([
            'app' => 'pos',
            'organization' => [
                'id' => (int) $organization->id,
                'name' => (string) $organization->name,
                'slug' => (string) $organization->slug,
                'pos_tenant_slug' => (string) ($organization->pos_tenant_slug ?? ''),
                'pos_tenant_name' => (string) ($organization->pos_tenant_name ?? $organization->name),
                'pos_provisioned_at' => $organization->pos_provisioned_at,
            ],
            'access' => [
                'admin_url' => config('app.pos_base_url', 'http://127.0.0.1:3000') . "/pos/admin?sso_token={$ssoToken}",
                'cashier_url' => config('app.pos_base_url', 'http://127.0.0.1:3000') . "/pos/cashier?sso_token={$ssoToken}",
                'customer_url' => config('app.pos_base_url', 'http://127.0.0.1:3000') . "/pos",
                'order_url' => config('app.pos_base_url', 'http://127.0.0.1:3000') . "/order",
                'requires_legacy_admin_auth' => false, // SSO enabled
            ],
        ], 'POS access prepared with SSO');
    }
}
