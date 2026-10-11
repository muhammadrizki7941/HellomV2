<?php

namespace App\Http\Controllers\Api\V1\Hellom\Pos;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Models\Organization;
use App\Models\Outlet;
use App\Models\PosStaff;
use App\Services\Pos\Audit\ActivityLogger;
use App\Services\Pos\Audit\OwnerPinException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

abstract class BasePosController extends BaseApiController
{
    protected function getOrg(Request $request): ?Organization
    {
        return $request->user()?->currentOrganization;
    }

    protected function getTenantSlug(Organization $org): string
    {
        return (string) ($org->pos_tenant_slug ?? $org->slug);
    }

    /**
     * Tenant slug of the active outlet (set by InjectPosContext), falling back to
     * the organization's primary slug. This is what per-outlet POS data scopes by.
     */
    protected function getActiveTenantSlug(Request $request, Organization $org): string
    {
        return (string) ($request->attributes->get('posTenantSlug') ?: $this->getTenantSlug($org));
    }

    /** Active outlet resolved by InjectPosContext. */
    protected function posOutlet(Request $request): ?Outlet
    {
        $outlet = $request->attributes->get('posOutlet');

        return $outlet instanceof Outlet ? $outlet : null;
    }

    /**
     * Owner/admin of the organization (no posStaff attribute), or POS staff whose owner/admin
     * granted this feature (App\Support\Pos\PosPermissions). Same rule as the
     * posPermission route middleware, for checks inside controllers.
     */
    protected function canPos(Request $request, string $permission): bool
    {
        $staff = $request->attributes->get('posStaff');

        return !$staff instanceof PosStaff || \App\Support\Pos\PosPermissions::allows($staff, $permission);
    }

    /** Organization of the active POS context (outlet's organization, else the current one). */
    protected function posOrganizationId(Request $request): ?int
    {
        $id = $this->posOutlet($request)?->organization_id ?? $request->attributes->get('currentOrganizationId') ?? $this->getOrg($request)?->id;

        return $id ? (int) $id : null;
    }

    /** Append to the POS audit trail (App\Services\Pos\Audit\ActivityLogger). */
    protected function recordActivity(Request $request, string $action, array $entry = []): void
    {
        $organizationId = $this->posOrganizationId($request);
        if ($organizationId) {
            app(ActivityLogger::class)->record($organizationId, $action, $entry);
        }
    }

    /** Owner PIN error → standard envelope; a wrong/badly formatted PIN also lands on the field. */
    protected function pinFailed(OwnerPinException $e, string $field = 'pin'): JsonResponse
    {
        $body = [
            'success' => false,
            'message' => $e->getMessage(),
            'data' => $e->data ?: null,
            'error' => ['code' => $e->errorCode, 'detail' => $e->getMessage()],
        ];
        if (in_array($e->errorCode, ['PIN_INVALID', 'PIN_FORMAT', 'PIN_WEAK', 'PIN_LOCKED'], true)) {
            $body['errors'] = [$field => [$e->getMessage()]];
        }

        return response()->json($body, $e->status);
    }

    /**
     * Whether the current user is the organization owner ("boss") — the only role
     * allowed to view aggregated, cross-outlet reports.
     */
    protected function isOrgOwner(Request $request, Organization $org): bool
    {
        $user = $request->user();
        if (!$user) {
            return false;
        }

        $membership = $user->organizations()
            ->where('organizations.id', $org->id)
            ->first();

        $role = (string) ($membership?->pivot?->role ?? '');

        return in_array($role, ['owner', 'admin'], true);
    }
}