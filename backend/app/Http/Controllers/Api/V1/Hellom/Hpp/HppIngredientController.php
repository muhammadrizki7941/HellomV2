<?php

namespace App\Http\Controllers\Api\V1\Hellom\Hpp;

use App\Models\Hpp\HppIngredient;
use App\Models\Hpp\HppRecipeItem;
use App\Support\Hpp\Units;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Master bahan baku & kemasan. The user enters what they buy (1 kg = Rp180.000); the cost per use
 * unit (Rp180/gram) is derived. Yield: 1 kg chicken → 800 g usable makes each usable gram dearer.
 */
class HppIngredientController extends HppBaseController
{
    public function index(Request $request): JsonResponse
    {
        [$org, $denied] = $this->organization($request);
        if ($denied) {
            return $denied;
        }
        $usage = HppRecipeItem::query()->where('component_type', 'ingredient')
            ->whereIn('component_id', HppIngredient::query()->where('organization_id', $org->id)->select('id'))
            ->selectRaw('component_id, count(*) as uses')->groupBy('component_id')->pluck('uses', 'component_id');
        $ingredients = HppIngredient::query()->where('organization_id', $org->id)
            ->when(!$request->boolean('with_archived'), fn ($q) => $q->where('is_archived', false))
            ->orderBy('kind')->orderBy('name')->get();

        return $this->ok([
            'ingredients' => $ingredients->map(fn (HppIngredient $i) => $this->present($i) + ['used_in' => (int) ($usage[$i->id] ?? 0)])->values(),
        ], 'Bahan');
    }

    public function store(Request $request): JsonResponse
    {
        [$org, $denied] = $this->organization($request);
        if ($denied) {
            return $denied;
        }
        $data = $this->validated($request);
        $ingredient = HppIngredient::query()->create(['organization_id' => $org->id] + $data);

        return $this->ok(['ingredient' => $this->present($ingredient)], 'Bahan ditambahkan', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        [$org, $denied] = $this->organization($request);
        if ($denied) {
            return $denied;
        }
        $ingredient = HppIngredient::query()->where('organization_id', $org->id)->find($id);
        if (!$ingredient) {
            return $this->notFound('Bahan');
        }
        $ingredient->fill($this->validated($request))->save();

        return $this->ok(['ingredient' => $this->present($ingredient)], 'Bahan disimpan');
    }

    /** Used in a recipe → archived (recipes keep their cost); otherwise deleted. */
    public function destroy(Request $request, int $id): JsonResponse
    {
        [$org, $denied] = $this->organization($request);
        if ($denied) {
            return $denied;
        }
        $ingredient = HppIngredient::query()->where('organization_id', $org->id)->find($id);
        if (!$ingredient) {
            return $this->notFound('Bahan');
        }
        $used = HppRecipeItem::query()->where('component_type', 'ingredient')->where('component_id', $ingredient->id)->exists();
        $used ? $ingredient->forceFill(['is_archived' => true])->save() : $ingredient->delete();

        return $this->ok(['archived' => $used], $used ? 'Bahan diarsipkan (masih dipakai di resep)' : 'Bahan dihapus');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'name' => 'required|string|max:120',
            'kind' => ['required', Rule::in(['ingredient', 'packaging'])],
            'category' => 'nullable|string|max:40',
            'use_unit' => ['required', Rule::in(array_keys(Units::USE))],
            'buy_unit' => 'required|string|max:20',
            'buy_factor' => 'nullable|numeric|min:0.0001|max:100000000',
            'buy_price' => 'required|integer|min:0|max:10000000000',
            'yield_pct' => 'nullable|numeric|min:1|max:100',
            'is_archived' => 'sometimes|boolean',
        ], [
            'name.required' => 'Nama bahan wajib diisi.',
            'buy_price.required' => 'Isi harga beli.',
            'yield_pct.min' => 'Yield minimal 1%.',
        ]);
        $factor = Units::factor((string) $validated['buy_unit'], (string) $validated['use_unit']) ?? (float) ($validated['buy_factor'] ?? 0);
        if ($factor <= 0) {
            throw \Illuminate\Validation\ValidationException::withMessages(['buy_factor' => "Isi berapa {$validated['use_unit']} dalam 1 {$validated['buy_unit']}."]);
        }

        return [
            'name' => trim($validated['name']),
            'kind' => $validated['kind'],
            'category' => $validated['category'] ?? null,
            'use_unit' => $validated['use_unit'],
            'buy_unit' => $validated['buy_unit'],
            'buy_factor' => $factor,
            'last_buy_price' => (int) $validated['buy_price'],
            'cost_per_unit' => round((int) $validated['buy_price'] / $factor, 4),
            'yield_pct' => (float) ($validated['yield_pct'] ?? 100),
        ] + (isset($validated['is_archived']) ? ['is_archived' => (bool) $validated['is_archived']] : []);
    }

    /** @return array<string, mixed> */
    private function present(HppIngredient $i): array
    {
        return [
            'id' => (int) $i->id,
            'name' => (string) $i->name,
            'kind' => (string) $i->kind,
            'category' => $i->category,
            'use_unit' => (string) $i->use_unit,
            'buy_unit' => (string) $i->buy_unit,
            'buy_factor' => (float) $i->buy_factor,
            'buy_price' => (int) $i->last_buy_price,
            'cost_per_unit' => round((float) $i->cost_per_unit, 4),
            'yield_pct' => (float) $i->yield_pct,
            'effective_cost' => round($i->effectiveCost(), 4),
            'is_archived' => (bool) $i->is_archived,
        ];
    }
}
