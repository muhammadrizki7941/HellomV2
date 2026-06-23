<?php

namespace App\Http\Controllers\Api\V1\Hellom\Pos;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Models\Organization;
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

        if (method_exists($user, 'isAdmin') && $user->isAdmin()) {
            return true;
        }

        $membership = $user->organizations()
            ->where('organizations.id', $org->id)
            ->first();

        $role = (string) ($membership?->pivot?->role ?? '');

        return in_array($role, ['owner', 'admin'], true);
    }
}