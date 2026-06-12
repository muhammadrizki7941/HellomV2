<?php

namespace App\Http\Controllers\Api\V1\Hellom\Pos;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Models\DiningTable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PosTableController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $tenantSlug = $request->attributes->get('posTenantSlug');
        if (!$tenantSlug) {
            return $this->error('POS context not available', 'CONTEXT_MISSING');
        }

        $tables = DiningTable::query()
            ->where('tenant_id', $tenantSlug)
            ->orderBy('code')
            ->get();

        return $this->success(['tables' => $tables], 'Tables retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $tenantSlug = $request->attributes->get('posTenantSlug');
        if (!$tenantSlug) {
            return $this->error('POS context not available', 'CONTEXT_MISSING');
        }

        $validated = $request->validate([
            'code' => [
                'required',
                'string',
                'max:16',
                Rule::unique('dining_tables', 'code')
                    ->where(fn ($query) => $query->where('tenant_id', $tenantSlug)),
            ],
            'name' => 'nullable|string|max:80',
            'is_active' => 'boolean',
        ]);

        $table = DiningTable::create([
            'tenant_id' => $tenantSlug,
            'public_id' => \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(12)),
            'code' => trim($validated['code']),
            'name' => filled($validated['name'] ?? null) ? trim($validated['name']) : null,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return $this->success(['table' => $table], 'Table created');
    }

    public function update(Request $request, int $tableId): JsonResponse
    {
        $tenantSlug = $request->attributes->get('posTenantSlug');
        if (!$tenantSlug) {
            return $this->error('POS context not available', 'CONTEXT_MISSING');
        }

        $table = DiningTable::where('tenant_id', $tenantSlug)->findOrFail($tableId);

        $validated = $request->validate([
            'code' => [
                'required',
                'string',
                'max:16',
                Rule::unique('dining_tables', 'code')
                    ->ignore($tableId)
                    ->where(fn ($query) => $query->where('tenant_id', $tenantSlug)),
            ],
            'name' => 'nullable|string|max:80',
            'is_active' => 'boolean',
        ]);

        $table->update([
            'code' => trim($validated['code']),
            'name' => filled($validated['name'] ?? null) ? trim($validated['name']) : null,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return $this->success(['table' => $table], 'Table updated');
    }

    public function destroy(Request $request, int $tableId): JsonResponse
    {
        $tenantSlug = $request->attributes->get('posTenantSlug');
        if (!$tenantSlug) {
            return $this->error('POS context not available', 'CONTEXT_MISSING');
        }

        $table = DiningTable::where('tenant_id', $tenantSlug)->findOrFail($tableId);
        $table->delete();

        return $this->success(null, 'Table deleted');
    }
}
