<?php

namespace App\Http\Controllers\Api\V1\Hellom\Hpp;

use App\Jobs\RecalculateHppCosts;
use App\Models\Hpp\HppChannel;
use App\Models\Hpp\HppCostSnapshot;
use App\Models\Hpp\HppIngredient;
use App\Models\Hpp\HppProduct;
use App\Models\Hpp\HppRecipeItem;
use App\Models\Organization;
use App\Models\Product;
use App\Services\Hpp\CostEngine;
use App\Services\Hpp\ProductCosting;
use App\Services\OutletService;
use App\Services\Pos\Audit\ActivityLogger;
use App\Services\Pos\Audit\Snapshots;
use App\Support\Pos\ActivityActions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Products and their cost: Mode Cepat (batch spend ÷ yield) or a recipe of ingredients, packaging
 * and sub-recipes. A product can be linked to a POS menu item: its price is read from POS, and the
 * suggested price can be written back to POS (logged in POS › Log Audit).
 */
class HppProductController extends HppBaseController
{
    public function index(Request $request): JsonResponse
    {
        [$org, $denied] = $this->organization($request);
        if ($denied) {
            return $denied;
        }
        $costing = new ProductCosting(CostEngine::for($org->id));
        $products = collect($costing->engine()->products())
            ->filter(fn (HppProduct $p) => $request->boolean('with_archived') || !$p->is_archived)
            ->sortBy(fn (HppProduct $p) => mb_strtolower((string) $p->name))
            ->map(fn (HppProduct $p) => $costing->summary($p))->values();

        return $this->ok(['products' => $products], 'Produk');
    }

    public function show(Request $request, int $id): JsonResponse
    {
        [$org, $denied] = $this->organization($request);
        if ($denied) {
            return $denied;
        }
        $costing = new ProductCosting(CostEngine::for($org->id));
        $product = $costing->engine()->product($id);
        if (!$product) {
            return $this->notFound('Produk');
        }

        return $this->ok(['product' => $this->detail($costing, $product)], 'Produk');
    }

    public function store(Request $request): JsonResponse
    {
        [$org, $denied] = $this->organization($request);
        if ($denied) {
            return $denied;
        }
        $product = DB::transaction(function () use ($request, $org) {
            $product = HppProduct::query()->create(['organization_id' => $org->id, 'name' => '…']);
            $this->save($request, $org, $product);

            return $product;
        });

        return $this->ok(['product' => $this->detail(new ProductCosting(CostEngine::for($org->id)), $product->fresh())], 'Produk ditambahkan', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        [$org, $denied] = $this->organization($request);
        if ($denied) {
            return $denied;
        }
        $product = HppProduct::query()->where('organization_id', $org->id)->find($id);
        if (!$product) {
            return $this->notFound('Produk');
        }
        DB::transaction(fn () => $this->save($request, $org, $product));

        return $this->ok(['product' => $this->detail(new ProductCosting(CostEngine::for($org->id)), $product->fresh())], 'Produk disimpan');
    }

    /** Used as a sub-recipe somewhere → archived; otherwise deleted with its recipe. */
    public function destroy(Request $request, int $id): JsonResponse
    {
        [$org, $denied] = $this->organization($request);
        if ($denied) {
            return $denied;
        }
        $product = HppProduct::query()->where('organization_id', $org->id)->find($id);
        if (!$product) {
            return $this->notFound('Produk');
        }
        $used = HppRecipeItem::query()->where('component_type', 'product')->where('component_id', $product->id)->exists();
        DB::transaction(function () use ($product, $used) {
            if ($used) {
                $product->forceFill(['is_archived' => true])->save();

                return;
            }
            HppRecipeItem::query()->where('product_id', $product->id)->delete();
            $product->delete();
        });

        return $this->ok(['archived' => $used], $used ? 'Diarsipkan (masih dipakai sebagai sub-resep)' : 'Produk dihapus');
    }

    /** POS menu of this organization, for linking (marks the ones already linked). */
    public function posProducts(Request $request): JsonResponse
    {
        [$org, $denied] = $this->organization($request);
        if ($denied) {
            return $denied;
        }
        $linked = HppProduct::query()->where('organization_id', $org->id)->whereNotNull('pos_product_id')->pluck('id', 'pos_product_id');

        return $this->ok([
            'products' => $this->posQuery($org)->orderBy('name')->get(['id', 'name', 'price'])->map(fn (Product $p) => [
                'id' => (int) $p->id, 'name' => (string) $p->name, 'price' => (int) round((float) $p->price),
                'hpp_product_id' => isset($linked[$p->id]) ? (int) $linked[$p->id] : null,
            ])->values(),
        ], 'Menu POS');
    }

    /** Create an HPP product (Mode Resep, empty recipe) for every POS menu item not linked yet. */
    public function importPos(Request $request): JsonResponse
    {
        [$org, $denied] = $this->organization($request);
        if ($denied) {
            return $denied;
        }
        $linked = HppProduct::query()->where('organization_id', $org->id)->whereNotNull('pos_product_id')->pluck('pos_product_id')->all();
        $created = 0;
        DB::transaction(function () use ($org, $linked, &$created) {
            foreach ($this->posQuery($org)->whereNotIn('id', $linked ?: [0])->orderBy('name')->get(['id', 'name', 'price']) as $pos) {
                HppProduct::query()->create([
                    'organization_id' => $org->id, 'name' => (string) $pos->name, 'mode' => 'recipe', 'pos_product_id' => $pos->id,
                    'selling_price' => (int) round((float) $pos->price), 'yield_qty' => 1, 'yield_unit' => 'porsi',
                ]);
                $created++;
            }
        });

        return $this->ok(['created' => $created], $created ? "{$created} menu POS diimpor" : 'Semua menu POS sudah ada');
    }

    /** Write a price to the linked POS menu item (logged in POS › Log Audit). */
    public function applyPrice(Request $request, int $id): JsonResponse
    {
        [$org, $denied] = $this->organization($request);
        if ($denied) {
            return $denied;
        }
        $validated = $request->validate(['price' => 'required|integer|min:1|max:100000000'], ['price.required' => 'Isi harga jualnya.']);
        $product = HppProduct::query()->where('organization_id', $org->id)->find($id);
        if (!$product) {
            return $this->notFound('Produk');
        }
        $price = (int) $validated['price'];
        DB::transaction(function () use ($org, $product, $price) {
            if ($product->pos_product_id) {
                $pos = $this->posQuery($org)->lockForUpdate()->find($product->pos_product_id);
                if ($pos) {
                    $before = Snapshots::product($pos);
                    $pos->forceFill(['price' => $price])->save();
                    app(ActivityLogger::class)->record($org->id, ActivityActions::PRODUCT_UPDATED, [
                        'target_type' => 'product', 'target_id' => $pos->id, 'target_label' => (string) $pos->name,
                        'before' => $before, 'after' => Snapshots::product($pos->fresh()), 'reason' => 'Harga dari Kalkulator HPP',
                        'severity' => 'warning',
                    ]);
                }
            }
            $product->forceFill(['selling_price' => $price])->save();
        });

        return $this->ok(['product' => $this->detail(new ProductCosting(CostEngine::for($org->id)), $product->fresh())],
            $product->pos_product_id ? 'Harga jual diperbarui di POS' : 'Harga jual disimpan');
    }

    private function save(Request $request, Organization $org, HppProduct $product): void
    {
        $validated = $request->validate([
            'name' => 'required|string|max:160',
            'mode' => ['required', Rule::in(['quick', 'recipe'])],
            'is_subrecipe' => 'sometimes|boolean',
            'pos_product_id' => 'nullable|integer',
            'selling_price' => 'nullable|integer|min:0|max:100000000',
            'target_margin_pct' => 'nullable|numeric|min:0|max:90',
            'yield_qty' => 'required|numeric|min:0.001|max:1000000',
            'yield_unit' => 'required|string|max:12',
            'quick_batch_cost' => 'nullable|integer|min:0|max:10000000000',
            'labor_minutes' => 'nullable|integer|min:0|max:100000',
            'other_cost' => 'nullable|integer|min:0|max:1000000000',
            'items' => 'nullable|array|max:100',
            'items.*.component_type' => ['required', Rule::in(['ingredient', 'product'])],
            'items.*.component_id' => 'required|integer',
            'items.*.qty' => 'required|numeric|min:0.001|max:10000000',
            'items.*.note' => 'nullable|string|max:120',
            'channel_prices' => 'nullable|array|max:30',
            'channel_prices.*' => 'nullable|integer|min:0|max:100000000',
        ], [
            'name.required' => 'Nama produk wajib diisi.',
            'yield_qty.required' => 'Isi jumlah hasil (porsi/pcs).',
            'yield_qty.min' => 'Jumlah hasil harus lebih dari 0.',
            'items.*.qty.min' => 'Takaran harus lebih dari 0.',
        ]);

        $posId = $validated['pos_product_id'] ?? null;
        if ($posId) {
            if (!$this->posQuery($org)->whereKey($posId)->exists()) {
                throw ValidationException::withMessages(['pos_product_id' => 'Menu POS tidak ditemukan di toko ini.']);
            }
            if (HppProduct::query()->where('organization_id', $org->id)->where('pos_product_id', $posId)->whereKeyNot($product->id)->exists()) {
                throw ValidationException::withMessages(['pos_product_id' => 'Menu POS ini sudah ditautkan ke produk lain.']);
            }
        }
        if ($validated['mode'] === 'quick' && (int) ($validated['quick_batch_cost'] ?? 0) <= 0) {
            throw ValidationException::withMessages(['quick_batch_cost' => 'Isi total belanja untuk satu kali produksi.']);
        }

        $items = $validated['mode'] === 'recipe' ? array_values($validated['items'] ?? []) : [];
        $engine = CostEngine::for($org->id);
        $ingredientIds = HppIngredient::query()->where('organization_id', $org->id)->pluck('id')->flip();
        foreach ($items as $i => $item) {
            $id = (int) $item['component_id'];
            if ($item['component_type'] === 'ingredient' && !isset($ingredientIds[$id])) {
                throw ValidationException::withMessages(["items.{$i}.component_id" => 'Bahan tidak ditemukan.']);
            }
            if ($item['component_type'] === 'product') {
                $sub = $engine->product($id);
                if (!$sub || !$sub->is_subrecipe) {
                    throw ValidationException::withMessages(["items.{$i}.component_id" => 'Sub-resep tidak ditemukan.']);
                }
                if ($engine->wouldLoop((int) $product->id, $id)) {
                    throw ValidationException::withMessages(["items.{$i}.component_id" => "Sub-resep \"{$sub->name}\" memakai produk ini, jadi tidak bisa dipakai di sini."]);
                }
            }
        }

        $product->forceFill([
            'name' => trim($validated['name']),
            'mode' => $validated['mode'],
            'is_subrecipe' => (bool) ($validated['is_subrecipe'] ?? false),
            'pos_product_id' => ($validated['is_subrecipe'] ?? false) ? null : $posId,
            'selling_price' => (int) ($validated['selling_price'] ?? 0),
            'target_margin_pct' => $validated['target_margin_pct'] ?? null,
            'yield_qty' => (float) $validated['yield_qty'],
            'yield_unit' => $validated['yield_unit'],
            'quick_batch_cost' => (int) ($validated['quick_batch_cost'] ?? 0),
            'labor_minutes' => (int) ($validated['labor_minutes'] ?? 0),
            'other_cost' => (int) ($validated['other_cost'] ?? 0),
            'channel_prices' => $this->channelPrices($org, (array) ($validated['channel_prices'] ?? [])),
        ])->save();
        RecalculateHppCosts::dispatch($org->id);

        HppRecipeItem::query()->where('product_id', $product->id)->delete();
        foreach ($items as $index => $item) {
            HppRecipeItem::query()->create([
                'product_id' => $product->id, 'component_type' => $item['component_type'], 'component_id' => (int) $item['component_id'],
                'qty' => (float) $item['qty'], 'note' => $item['note'] ?? null, 'sort_order' => $index,
            ]);
        }
    }

    /** Own price per channel (ojol menus are often priced higher); only this shop's channels, > 0. */
    private function channelPrices(Organization $org, array $prices): ?array
    {
        $valid = HppChannel::query()->where('organization_id', $org->id)->pluck('id')->map(fn ($id) => (string) $id)->all();
        $clean = [];
        foreach ($prices as $channelId => $price) {
            if (in_array((string) $channelId, $valid, true) && (int) $price > 0) {
                $clean[(string) $channelId] = (int) $price;
            }
        }

        return $clean ?: null;
    }

    /** @return array<string, mixed> */
    private function detail(ProductCosting $costing, HppProduct $product): array
    {
        return $costing->summary($product) + [
            'quick_batch_cost' => (int) $product->quick_batch_cost,
            'labor_minutes' => (int) $product->labor_minutes,
            'other_cost' => (int) $product->other_cost,
            'own_target_margin_pct' => $product->target_margin_pct,
            'lines' => $costing->engine()->lines((int) $product->id),
            'channel_prices' => (object) ((array) ($product->channel_prices ?? [])),
            'trend' => HppCostSnapshot::query()->where('product_id', $product->id)->where('snapshot_date', '>=', now()->subDays(60)->toDateString())
                ->orderBy('snapshot_date')->get(['snapshot_date', 'cost_full', 'selling_price', 'margin_pct'])
                ->map(fn ($s) => ['date' => $s->snapshot_date->toDateString(), 'cost' => (float) $s->cost_full, 'price' => (int) $s->selling_price, 'margin_pct' => $s->margin_pct])->values(),
        ];
    }

    private function posQuery(Organization $org)
    {
        return Product::withoutGlobalScope('tenant')->whereIn('tenant_id', app(OutletService::class)->tenantSlugs($org));
    }
}
