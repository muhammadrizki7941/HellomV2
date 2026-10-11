<?php

namespace App\Http\Controllers\Api\V1\Hellom\Hpp;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Controllers\Api\V1\Hellom\Concerns\ResolvesSellerOrganization;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Kalkulator HPP (dashboard app "hpp"): the user's current organization, owners/admins only.
 * Data is keyed by organization_id (works without POS).
 */
abstract class HppBaseController extends BaseApiController
{
    use ResolvesSellerOrganization;

    /** @return array{0: ?Organization, 1: ?JsonResponse} */
    protected function organization(Request $request): array
    {
        return $this->sellerOrganization($request, 'Hanya pemilik/admin toko yang bisa membuka Kalkulator HPP');
    }

    protected function notFound(string $what = 'Data'): JsonResponse
    {
        return $this->fail("{$what} tidak ditemukan", ['code' => 'NOT_FOUND'], 404);
    }
}
