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
}