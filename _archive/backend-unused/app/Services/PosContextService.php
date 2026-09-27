<?php

namespace App\Services;

use Illuminate\Http\Request;

class PosContextService
{
    public function getTenantSlug(Request $request): ?string
    {
        return $request->attributes->get('posTenantSlug');
    }

    public function assertTenantSlug(Request $request): string
    {
        $slug = $this->getTenantSlug($request);
        if (!$slug) {
            throw new \Exception('POS tenant context not available');
        }
        return $slug;
    }

    public function getOrganizationId(Request $request): ?int
    {
        return $request->attributes->get('currentOrganizationId');
    }
}