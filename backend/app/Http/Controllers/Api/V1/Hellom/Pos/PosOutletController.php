<?php

namespace App\Http\Controllers\Api\V1\Hellom\Pos;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Models\Organization;
use App\Models\Outlet;
use App\Services\OutletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class PosOutletController extends BaseApiController
{
    public function __construct(private readonly OutletService $outlets)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $org = $this->org($request);
        if (!$org) {
            return $this->error('POS context not available', 'CONTEXT_MISSING');
        }

        $outlets = Outlet::query()
            ->where('organization_id', $org->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $max = $this->outlets->effectiveMaxOutlets($org);
        $activeId = $request->attributes->get('posOutletId');

        return $this->success([
            'outlets' => $outlets,
            'active_outlet_id' => $activeId,
            'meta' => [
                'used' => $outlets->count(),
                'max_outlets' => $max,
                'can_add' => $outlets->count() < $max,
            ],
        ], 'Outlets retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $org = $this->org($request);
        if (!$org) {
            return $this->error('POS context not available', 'CONTEXT_MISSING');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:150',
            'address' => 'nullable|string|max:500',
            'phone' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:120',
            'description' => 'nullable|string|max:1000',
        ]);

        try {
            $outlet = $this->outlets->createOutlet($org, $validated);
        } catch (RuntimeException $e) {
            if ($e->getMessage() === 'OUTLET_LIMIT_REACHED') {
                return $this->error(
                    'Batas jumlah outlet untuk paket Anda sudah tercapai. Upgrade paket untuk menambah outlet.',
                    'OUTLET_LIMIT_REACHED',
                    ['max_outlets' => $this->outlets->effectiveMaxOutlets($org)],
                    422
                );
            }
            throw $e;
        }

        return $this->success(['outlet' => $outlet], 'Outlet created');
    }

    public function update(Request $request, int $outletId): JsonResponse
    {
        $org = $this->org($request);
        if (!$org) {
            return $this->error('POS context not available', 'CONTEXT_MISSING');
        }

        $outlet = Outlet::query()
            ->where('organization_id', $org->id)
            ->findOrFail($outletId);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:150',
            'address' => 'nullable|string|max:500',
            'phone' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:120',
            'description' => 'nullable|string|max:1000',
            'is_active' => 'sometimes|boolean',
        ]);

        // The primary outlet cannot be deactivated (it is the safe fallback).
        if ($outlet->is_primary && array_key_exists('is_active', $validated)) {
            $validated['is_active'] = true;
        }

        $outlet->update($validated);

        return $this->success(['outlet' => $outlet->fresh()], 'Outlet updated');
    }

    public function destroy(Request $request, int $outletId): JsonResponse
    {
        $org = $this->org($request);
        if (!$org) {
            return $this->error('POS context not available', 'CONTEXT_MISSING');
        }

        $outlet = Outlet::query()
            ->where('organization_id', $org->id)
            ->findOrFail($outletId);

        if ($outlet->is_primary) {
            return $this->error('Outlet utama tidak bisa dihapus.', 'OUTLET_PRIMARY_PROTECTED', null, 422);
        }

        if (Outlet::query()->where('organization_id', $org->id)->count() <= 1) {
            return $this->error('Minimal harus ada satu outlet.', 'OUTLET_MINIMUM', null, 422);
        }

        $outlet->delete();

        return $this->success(null, 'Outlet deleted');
    }

    private function org(Request $request): ?Organization
    {
        return $request->user()?->currentOrganization;
    }
}
