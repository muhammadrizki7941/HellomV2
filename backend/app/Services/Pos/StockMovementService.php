<?php

namespace App\Services\Pos;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class StockMovementService
{
    private const TYPES = ['sale', 'purchase', 'adjustment', 'return', 'transfer', 'opening_balance'];

    public function setOnHand(
        Product $product,
        int $target,
        ?int $outletId,
        ?int $userId,
        string $note,
        ?ProductVariant $variant = null,
    ): ?StockMovement {
        return DB::transaction(function () use ($product, $target, $outletId, $userId, $note, $variant) {
            $lockedProduct = Product::withoutGlobalScope('tenant')
                ->where('tenant_id', $product->tenant_id)
                ->lockForUpdate()
                ->findOrFail($product->id);
            $lockedVariant = $variant
                ? ProductVariant::query()->where('tenant_id', $product->tenant_id)->lockForUpdate()->findOrFail($variant->id)
                : null;
            if ($lockedVariant && (int) $lockedVariant->product_id !== (int) $lockedProduct->id) {
                throw new InvalidArgumentException('Varian tidak sesuai dengan produk.');
            }
            if ($target < 0) {
                throw new InvalidArgumentException('Stok fisik tidak boleh kurang dari nol.');
            }
            $current = $this->onHand($lockedProduct, $outletId, $lockedVariant);
            $difference = $target - $current;
            if ($difference === 0) {
                if ($lockedVariant && $lockedVariant->track_stock && $lockedVariant->stock === null) {
                    $lockedVariant->forceFill(['stock' => 0])->save();
                } elseif (!$lockedVariant && $lockedProduct->track_stock && $lockedProduct->stock === null) {
                    $lockedProduct->forceFill(['stock' => 0])->save();
                }

                return null;
            }

            return $this->record(
                $lockedProduct,
                $difference,
                'adjustment',
                $outletId,
                $lockedVariant,
                note: $note,
                userId: $userId
            );
        });
    }

    public function record(
        Product $product,
        int $qty,
        string $type,
        ?int $outletId = null,
        ?ProductVariant $variant = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $note = null,
        ?int $userId = null,
        ?string $clientUuid = null,
        ?string $createdAtClient = null,
        bool $allowNegativeStock = false,
    ): ?StockMovement {
        if (!in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException('Jenis pergerakan stok tidak dikenal.');
        }
        if ($qty === 0) {
            return null;
        }
        if ($variant && (int) $variant->product_id !== (int) $product->id) {
            throw new InvalidArgumentException('Varian tidak sesuai dengan produk.');
        }

        return DB::transaction(function () use (
            $product, $qty, $type, $outletId, $variant, $referenceType, $referenceId, $note,
            $userId, $clientUuid, $createdAtClient, $allowNegativeStock
        ) {
            $lockedProduct = Product::withoutGlobalScope('tenant')
                ->where('tenant_id', $product->tenant_id)
                ->lockForUpdate()
                ->findOrFail($product->id);
            $lockedVariant = $variant
                ? ProductVariant::query()->where('tenant_id', $product->tenant_id)->lockForUpdate()->findOrFail($variant->id)
                : null;

            if ($clientUuid && StockMovement::query()
                ->where('tenant_id', $product->tenant_id)
                ->where('client_uuid', $clientUuid)
                ->exists()) {
                return StockMovement::query()
                    ->where('tenant_id', $product->tenant_id)
                    ->where('client_uuid', $clientUuid)
                    ->first();
            }

            $alreadyRecorded = $referenceType !== null && $referenceId !== null
                ? StockMovement::query()
                    ->where('tenant_id', $product->tenant_id)
                    ->where('reference_type', $referenceType)
                    ->where('reference_id', $referenceId)
                    ->where('product_id', $product->id)
                    ->where('product_variant_id', $lockedVariant?->id)
                    ->where('movement_type', $type)
                    ->exists()
                : false;
            if ($alreadyRecorded) {
                return null;
            }

            $this->seedLegacyOpeningBalance($lockedProduct, $outletId, $lockedVariant);
            $balanceBefore = $this->onHand($lockedProduct, $outletId, $lockedVariant);
            if (!$allowNegativeStock && ($lockedVariant?->track_stock || (!$lockedVariant && $lockedProduct->track_stock))
                && $balanceBefore + $qty < 0) {
                $name = $lockedVariant?->name ?? $lockedProduct->name;
                throw new PricingException("Stok {$name} tidak mencukupi.", [], 'STOCK_INSUFFICIENT', 409);
            }

            $movement = StockMovement::query()->create([
                'tenant_id' => $lockedProduct->tenant_id,
                'outlet_id' => $outletId,
                'product_id' => $lockedProduct->id,
                'product_variant_id' => $lockedVariant?->id,
                'movement_type' => $type,
                'qty' => $qty,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'note' => $note,
                'created_by' => $userId,
                'created_at_client' => $createdAtClient,
                'client_uuid' => $clientUuid ?: (string) Str::uuid(),
            ]);

            if ($lockedVariant && $lockedVariant->track_stock) {
                $lockedVariant->stock = max(0, (int) ($lockedVariant->stock ?? 0) + $qty);
                $lockedVariant->save();
            } elseif ($lockedProduct->track_stock) {
                $lockedProduct->stock = max(0, (int) ($lockedProduct->stock ?? 0) + $qty);
                $lockedProduct->save();
            }

            return $movement;
        });
    }

    private function seedLegacyOpeningBalance(
        Product $product,
        ?int $outletId,
        ?ProductVariant $variant,
    ): void {
        $movements = StockMovement::query()
            ->where('tenant_id', $product->tenant_id)
            ->where('product_id', $product->id)
            ->where('product_variant_id', $variant?->id);
        if ($movements->exists()) {
            return;
        }

        if ($outletId !== null && $product->outlet_id !== null && (int) $product->outlet_id !== $outletId) {
            return;
        }

        $legacyBalance = $variant ? (int) ($variant->stock ?? 0) : (int) ($product->stock ?? 0);
        if ($legacyBalance < 1) {
            return;
        }

        StockMovement::query()->create([
            'tenant_id' => $product->tenant_id,
            'outlet_id' => $outletId,
            'product_id' => $product->id,
            'product_variant_id' => $variant?->id,
            'movement_type' => 'opening_balance',
            'qty' => $legacyBalance,
            'note' => 'Saldo awal dari data stok sebelumnya',
            'client_uuid' => (string) Str::uuid(),
        ]);
    }

    public function onHand(Product $product, ?int $outletId, ?ProductVariant $variant = null): int
    {
        $query = StockMovement::query()
            ->where('tenant_id', $product->tenant_id)
            ->where('product_id', $product->id)
            ->where('product_variant_id', $variant?->id);
        $outletId === null ? $query->whereNull('outlet_id') : $query->where('outlet_id', $outletId);
        if ($query->exists()) {
            return (int) $query->sum('qty');
        }

        if (StockMovement::query()
            ->where('tenant_id', $product->tenant_id)
            ->where('product_id', $product->id)
            ->where('product_variant_id', $variant?->id)
            ->exists()) {
            return 0;
        }

        if (!$variant && $product->track_stock
            && ($outletId === null || $product->outlet_id === null || (int) $product->outlet_id === $outletId)) {
            return (int) ($product->stock ?? 0);
        }

        if ($variant && ($outletId === null || $product->outlet_id === null || (int) $product->outlet_id === $outletId)) {
            return (int) ($variant->stock ?? 0);
        }

        return 0;
    }
}
