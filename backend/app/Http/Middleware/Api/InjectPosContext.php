<?php

namespace App\Http\Middleware\Api;

use App\Models\Outlet;
use App\Models\PosStaff;
use App\Models\User;
use App\Services\Hellom\PosProvisioningService;
use App\Services\OutletService;
use App\Support\Pos\PosRoles;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class InjectPosContext
{
    /**
     * Org roles allowed to manage every outlet and freely switch between them.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $organization = $user->currentOrganization;
        if (!$organization) {
            return response()->json(['success' => false, 'message' => 'No organization'], 403);
        }

        $posTenantSlug = $organization->pos_tenant_slug;
        if (empty($posTenantSlug)) {
            $organization = app(PosProvisioningService::class)->ensureProvisionedForPos((int) $organization->id) ?: $organization;
            $posTenantSlug = $organization->pos_tenant_slug;
        }

        if (empty($posTenantSlug)) {
            return response()->json([
                'success' => false,
                'message' => 'POS belum siap untuk organisasi ini. Silakan klik Coba Lagi atau hubungi owner.',
                'error' => ['code' => 'POS_NOT_PROVISIONED'],
            ], 403);
        }

        $outletService = app(OutletService::class);

        // POS staff may switch only among outlets explicitly assigned to their staff record.
        if (!$this->isManager($user, $organization)) {
            $staff = PosStaff::query()
                ->forOrganization((int) $organization->id)
                ->where('linked_user_id', (int) $user->id)
                ->where('employment_status', 'active')
                ->orderByDesc('id')
                ->first();

            if (!$staff instanceof PosStaff) {
                return response()->json([
                    'success' => false,
                    'message' => 'Akun ini belum diberi akses outlet POS. Hubungi owner/admin organisasi.',
                    'error' => ['code' => 'POS_NO_OUTLET_ASSIGNMENT'],
                ], 403);
            }

            $requestedOutletId = $request->header('X-Outlet-Id') ?? $request->query('outlet_id');
            $outlet = $staff->resolveBoundOutlet(
                $requestedOutletId !== null && $requestedOutletId !== '' ? (int) $requestedOutletId : null
            );
            if (!$outlet instanceof Outlet) {
                return response()->json([
                    'success' => false,
                    'message' => $requestedOutletId
                        ? 'Akun ini tidak ditugaskan ke outlet yang dipilih.'
                        : 'Outlet yang ditugaskan ke akun ini tidak aktif. Hubungi owner/admin.',
                    'error' => ['code' => $requestedOutletId ? 'POS_OUTLET_NOT_ASSIGNED' : 'POS_OUTLET_UNAVAILABLE'],
                ], 403);
            }

            $request->attributes->set('posTenantSlug', (string) ($outlet->tenant_slug ?: $staff->tenant_id));
            $request->attributes->set('currentOrganizationId', $organization->id);
            $request->attributes->set('posOutletId', $outlet->id);
            $request->attributes->set('posOutlet', $outlet);
            $request->attributes->set('posStaff', $staff);
            $request->attributes->set('posAssignedOutletIds', $staff->assignedOutletIds());

            return $next($request);
        }

        // Owner/admin: resolve the active outlet from the request, free to switch.
        $requestedOutletId = $request->header('X-Outlet-Id')
            ?? $request->query('outlet_id');
        $outlet = $outletService->resolveActiveOutlet($organization, $requestedOutletId);

        if (!empty($outlet->tenant_slug)) {
            $posTenantSlug = $outlet->tenant_slug;
        }

        $request->attributes->set('posTenantSlug', $posTenantSlug);
        $request->attributes->set('currentOrganizationId', $organization->id);
        $request->attributes->set('posOutletId', $outlet->id);
        $request->attributes->set('posOutlet', $outlet);

        return $next($request);
    }

    /**
     * Whether the user can manage all outlets of this organization.
     */
    private function isManager(User $user, $organization): bool
    {
        if (PosRoles::isPlatformManager((string) $user->role)) {
            return true;
        }

        $membership = $user->organizations()
            ->where('organizations.id', (int) $organization->id)
            ->first();

        $pivotRole = (string) ($membership?->pivot?->role ?? '');

        return PosRoles::isOrganizationManagerMembership($pivotRole);
    }
}
