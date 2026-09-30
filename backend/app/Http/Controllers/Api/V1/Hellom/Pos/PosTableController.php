<?php

namespace App\Http\Controllers\Api\V1\Hellom\Pos;

use App\Models\AuditLog;
use App\Models\DiningTable;
use App\Models\TableBill;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Tables of the active outlet. Each table's QR token (public_id) is random and bound
 * to this outlet; regenerating it invalidates the printed QR immediately.
 * The outlet's "counter" pseudo-table (shop-link orders) is not listed here.
 */
class PosTableController extends BasePosController
{
    public function index(Request $request): JsonResponse
    {
        $tenantSlug = $request->attributes->get('posTenantSlug');
        if (!$tenantSlug) {
            return $this->error('POS context not available', 'CONTEXT_MISSING');
        }

        $tables = $this->tables($tenantSlug)->orderBy('code')->get();
        $openBills = TableBill::query()
            ->whereIn('dining_table_id', $tables->pluck('id'))
            ->where('status', TableBill::STATUS_OPEN)
            ->pluck('id', 'dining_table_id');

        return $this->success([
            'tables' => $tables->map(fn (DiningTable $table) => $this->present($table) + [
                'open_bill_id' => $openBills[$table->id] ?? null,
            ])->values(),
        ], 'Tables retrieved');
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

        $table = DiningTable::withoutGlobalScope('tenant')->create([
            'tenant_id' => $tenantSlug,
            'outlet_id' => $request->attributes->get('posOutletId'),
            'code' => trim($validated['code']),
            'name' => filled($validated['name'] ?? null) ? trim($validated['name']) : null,
            'kind' => DiningTable::KIND_TABLE,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return $this->success(['table' => $this->present($table)], 'Table created');
    }

    public function update(Request $request, int $tableId): JsonResponse
    {
        $tenantSlug = $request->attributes->get('posTenantSlug');
        if (!$tenantSlug) {
            return $this->error('POS context not available', 'CONTEXT_MISSING');
        }

        $table = $this->tables($tenantSlug)->findOrFail($tableId);

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

        return $this->success(['table' => $this->present($table)], 'Table updated');
    }

    public function destroy(Request $request, int $tableId): JsonResponse
    {
        $tenantSlug = $request->attributes->get('posTenantSlug');
        if (!$tenantSlug) {
            return $this->error('POS context not available', 'CONTEXT_MISSING');
        }

        $table = $this->tables($tenantSlug)->findOrFail($tableId);
        if (TableBill::query()->where('dining_table_id', $table->id)->where('status', TableBill::STATUS_OPEN)->exists()) {
            return $this->error('Meja masih punya tagihan terbuka. Selesaikan dulu tagihannya.', 'TABLE_HAS_OPEN_BILL', null, 422);
        }
        $table->delete();

        return $this->success(null, 'Table deleted');
    }

    /** New random QR token; the old QR stops working at once. */
    public function regenerateToken(Request $request, int $tableId): JsonResponse
    {
        $tenantSlug = $request->attributes->get('posTenantSlug');
        $org = $this->getOrg($request);
        if (!$tenantSlug || !$org) {
            return $this->error('POS context not available', 'CONTEXT_MISSING');
        }
        if (!$this->canPos($request, 'tables')) {
            return $this->error('Akun kamu belum punya akses mengelola meja & QR', 'FORBIDDEN', null, 403);
        }

        $table = $this->tables($tenantSlug)->findOrFail($tableId);
        $table->forceFill([
            'public_id' => DiningTable::newPublicToken(),
            'token_rotated_at' => now(),
            'outlet_id' => $table->outlet_id ?: $request->attributes->get('posOutletId'),
        ])->save();

        AuditLog::record('pos.table.qr_regenerated', $request->user()?->id, $org->id, 'dining_table', $table->id, null,
            ['code' => $table->code], null, $request->ip(), mb_substr((string) $request->userAgent(), 0, 255));

        return $this->success(['table' => $this->present($table)], 'QR meja diganti. Cetak ulang QR untuk meja ini.');
    }

    /** Every active table of the outlet for bulk QR printing. */
    public function qrSheet(Request $request): JsonResponse
    {
        $tenantSlug = $request->attributes->get('posTenantSlug');
        $org = $this->getOrg($request);
        if (!$tenantSlug || !$org) {
            return $this->error('POS context not available', 'CONTEXT_MISSING');
        }
        $outlet = $this->posOutlet($request);

        return $this->success([
            'organization' => ['name' => $org->name, 'slug' => $org->slug],
            'outlet' => $outlet ? ['id' => $outlet->id, 'name' => $outlet->name, 'address' => $outlet->address] : null,
            'tables' => $this->tables($tenantSlug)->where('is_active', true)->orderBy('code')->get()
                ->map(fn (DiningTable $table) => $this->present($table))->values(),
        ], 'QR sheet');
    }

    private function tables(string $tenantSlug)
    {
        return DiningTable::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantSlug)
            ->where(fn ($q) => $q->whereNull('kind')->orWhere('kind', DiningTable::KIND_TABLE));
    }

    /** @return array<string, mixed> */
    private function present(DiningTable $table): array
    {
        return [
            'id' => $table->id,
            'outlet_id' => $table->outlet_id,
            'tenant_id' => $table->tenant_id,
            'public_id' => $table->public_id,
            'code' => $table->code,
            'name' => $table->name,
            'is_active' => (bool) $table->is_active,
            'token_rotated_at' => optional($table->token_rotated_at)->toIso8601String(),
            'has_weak_token' => $table->hasWeakToken(),
            'created_at' => $table->created_at,
            'updated_at' => $table->updated_at,
        ];
    }
}
