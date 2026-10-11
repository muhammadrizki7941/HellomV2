<?php

namespace App\Http\Controllers\Api\V1\Hellom\Hpp;

use App\Jobs\RecalculateHppCosts;
use App\Models\Hpp\HppChannel;
use App\Models\Hpp\HppOverhead;
use App\Models\Hpp\HppSetting;
use App\Models\Outlet;
use App\Support\Hpp\Templates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Biaya tetap (overhead & tenaga kerja) and sales channels (QRIS, payment gateway, Hellom, ojol).
 * Overhead is spread over products by the shop's method (per estimated unit, % of revenue, per
 * labour hour); channel fees decide the safe price per channel.
 */
class HppCostController extends HppBaseController
{
    public function index(Request $request): JsonResponse
    {
        [$org, $denied] = $this->organization($request);
        if ($denied) {
            return $denied;
        }
        HppChannel::ensureDefaults($org->id);

        return $this->ok($this->payload($org->id), 'Biaya');
    }

    public function storeOverhead(Request $request): JsonResponse
    {
        return $this->saveOverhead($request, null);
    }

    public function updateOverhead(Request $request, int $id): JsonResponse
    {
        return $this->saveOverhead($request, $id);
    }

    public function destroyOverhead(Request $request, int $id): JsonResponse
    {
        [$org, $denied] = $this->organization($request);
        if ($denied) {
            return $denied;
        }
        $deleted = HppOverhead::query()->where('organization_id', $org->id)->whereKey($id)->delete();
        if (!$deleted) {
            return $this->notFound('Biaya');
        }
        RecalculateHppCosts::dispatch($org->id);

        return $this->ok($this->payload($org->id), 'Biaya dihapus');
    }

    /** Fill the usual fixed costs of the business type (only when there are none yet). */
    public function loadTemplate(Request $request): JsonResponse
    {
        [$org, $denied] = $this->organization($request);
        if ($denied) {
            return $denied;
        }
        if (!HppOverhead::query()->where('organization_id', $org->id)->exists()) {
            DB::transaction(function () use ($org) {
                foreach (Templates::overheads((string) HppSetting::for($org->id)->business_type) as $row) {
                    HppOverhead::query()->create(['organization_id' => $org->id, 'name' => $row['name'], 'category' => $row['category'], 'amount' => $row['amount']]);
                }
            });
            RecalculateHppCosts::dispatch($org->id);
        }

        return $this->ok($this->payload($org->id), 'Contoh biaya dimuat');
    }

    public function storeChannel(Request $request): JsonResponse
    {
        return $this->saveChannel($request, null);
    }

    public function updateChannel(Request $request, int $id): JsonResponse
    {
        return $this->saveChannel($request, $id);
    }

    public function destroyChannel(Request $request, int $id): JsonResponse
    {
        [$org, $denied] = $this->organization($request);
        if ($denied) {
            return $denied;
        }
        $deleted = HppChannel::query()->where('organization_id', $org->id)->whereKey($id)->delete();
        if (!$deleted) {
            return $this->notFound('Channel');
        }
        HppSetting::query()->where('organization_id', $org->id)->where('main_channel_id', $id)->update(['main_channel_id' => null]);
        RecalculateHppCosts::dispatch($org->id);

        return $this->ok($this->payload($org->id), 'Channel dihapus');
    }

    private function saveOverhead(Request $request, ?int $id): JsonResponse
    {
        [$org, $denied] = $this->organization($request);
        if ($denied) {
            return $denied;
        }
        $validated = $request->validate([
            'name' => 'required|string|max:120',
            'category' => ['required', Rule::in(HppOverhead::CATEGORIES)],
            'amount' => 'required|integer|min:0|max:100000000000',
            'outlet_id' => ['nullable', 'integer', Rule::exists('outlets', 'id')->where('organization_id', $org->id)],
            'is_active' => 'sometimes|boolean',
        ], ['name.required' => 'Nama biaya wajib diisi.', 'amount.required' => 'Isi nominal per bulan.']);
        $overhead = $id ? HppOverhead::query()->where('organization_id', $org->id)->find($id) : new HppOverhead(['organization_id' => $org->id]);
        if (!$overhead) {
            return $this->notFound('Biaya');
        }
        $overhead->forceFill([
            'organization_id' => $org->id, 'name' => trim($validated['name']), 'category' => $validated['category'], 'amount' => (int) $validated['amount'],
            'outlet_id' => (int) ($validated['outlet_id'] ?? 0), 'is_active' => (bool) ($validated['is_active'] ?? true),
        ])->save();
        RecalculateHppCosts::dispatch($org->id);

        return $this->ok($this->payload($org->id), 'Biaya disimpan', $id ? 200 : 201);
    }

    private function saveChannel(Request $request, ?int $id): JsonResponse
    {
        [$org, $denied] = $this->organization($request);
        if ($denied) {
            return $denied;
        }
        $validated = $request->validate([
            'name' => 'required|string|max:80',
            'kind' => ['required', Rule::in(['cash', 'qris', 'hellom', 'gofood', 'grabfood', 'shopeefood', 'custom'])],
            'fee_pct' => 'required|numeric|min:0|max:60',
            'fee_flat' => 'nullable|integer|min:0|max:10000000',
            'is_active' => 'sometimes|boolean',
        ], ['name.required' => 'Nama channel wajib diisi.', 'fee_pct.max' => 'Fee maksimal 60%.']);
        $channel = $id ? HppChannel::query()->where('organization_id', $org->id)->find($id) : new HppChannel();
        if (!$channel) {
            return $this->notFound('Channel');
        }
        $channel->forceFill([
            'organization_id' => $org->id, 'name' => trim($validated['name']), 'kind' => $validated['kind'], 'fee_pct' => (float) $validated['fee_pct'],
            'fee_flat' => (int) ($validated['fee_flat'] ?? 0), 'is_active' => (bool) ($validated['is_active'] ?? true),
            'sort_order' => $channel->exists ? $channel->sort_order : (int) HppChannel::query()->where('organization_id', $org->id)->max('sort_order') + 1,
        ])->save();
        RecalculateHppCosts::dispatch($org->id);

        return $this->ok($this->payload($org->id), 'Channel disimpan', $id ? 200 : 201);
    }

    /** @return array<string, mixed> */
    private function payload(int $organizationId): array
    {
        $overheads = HppOverhead::query()->where('organization_id', $organizationId)->orderBy('id')->get();
        $outlets = Outlet::query()->where('organization_id', $organizationId)->orderBy('id')->get(['id', 'name']);

        return [
            'overheads' => $overheads->map(fn (HppOverhead $o) => [
                'id' => (int) $o->id, 'name' => (string) $o->name, 'category' => (string) $o->category, 'amount' => (int) $o->amount,
                'outlet_id' => (int) $o->outlet_id ?: null, 'is_active' => (bool) $o->is_active,
            ])->values(),
            'overhead_total' => (int) $overheads->where('is_active', true)->sum('amount'),
            'channels' => HppChannel::query()->where('organization_id', $organizationId)->orderBy('sort_order')->orderBy('id')->get()
                ->map(fn (HppChannel $c) => ['id' => (int) $c->id, 'name' => (string) $c->name, 'kind' => (string) $c->kind,
                    'fee_pct' => (float) $c->fee_pct, 'fee_flat' => (int) $c->fee_flat, 'is_active' => (bool) $c->is_active])->values(),
            'outlets' => $outlets->map(fn ($o) => ['id' => (int) $o->id, 'name' => (string) $o->name])->values(),
        ];
    }
}
