<?php

namespace App\Http\Controllers\Api\V1\Hellom;

use App\Http\Controllers\Api\V1\Hellom\Concerns\ResolvesSellerOrganization;
use App\Models\LandingCoupon;
use App\Models\LandingProduct;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Discount codes of the seller's shop (owner/admin). */
class SellerCouponController extends BaseApiController
{
    use ResolvesSellerOrganization;

    public function index(Request $request): JsonResponse
    {
        [$organization, $error] = $this->sellerOrganization($request);
        if ($error) {
            return $error;
        }
        $items = LandingCoupon::query()->where('organization_id', $organization->id)->orderByDesc('id')->get()
            ->map(fn (LandingCoupon $c) => $this->payload($c))->values();

        return $this->ok(['items' => $items], 'Kupon');
    }

    public function store(Request $request): JsonResponse
    {
        [$organization, $error] = $this->sellerOrganization($request);
        if ($error) {
            return $error;
        }
        $data = $this->validated($request, $organization);
        $coupon = LandingCoupon::query()->create($data + ['organization_id' => $organization->id]);

        return $this->ok($this->payload($coupon), 'Kupon dibuat', 201);
    }

    public function update(Request $request, int $couponId): JsonResponse
    {
        [$organization, $error] = $this->sellerOrganization($request);
        if ($error) {
            return $error;
        }
        $coupon = LandingCoupon::query()->where('organization_id', $organization->id)->find($couponId);
        if (!$coupon) {
            return $this->fail('Kupon tidak ditemukan', ['code' => 'COUPON_NOT_FOUND'], 404);
        }
        $coupon->update($this->validated($request, $organization, $coupon));

        return $this->ok($this->payload($coupon), 'Kupon disimpan');
    }

    public function destroy(Request $request, int $couponId): JsonResponse
    {
        [$organization, $error] = $this->sellerOrganization($request);
        if ($error) {
            return $error;
        }
        $coupon = LandingCoupon::query()->where('organization_id', $organization->id)->find($couponId);
        if (!$coupon) {
            return $this->fail('Kupon tidak ditemukan', ['code' => 'COUPON_NOT_FOUND'], 404);
        }
        // Free the code for reuse (unique per shop) while orders keep their coupon_code copy.
        $coupon->forceFill(['is_active' => false, 'code' => substr($coupon->code, 0, 30) . '~' . $coupon->id])->save();
        $coupon->delete();

        return $this->ok(['deleted' => true], 'Kupon dihapus');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, Organization $organization, ?LandingCoupon $coupon = null): array
    {
        if ($request->has('code')) {
            $request->merge(['code' => LandingCoupon::normalizeCode((string) $request->input('code'))]);
        }
        if ($request->has('is_active') && is_string($request->input('is_active'))) {
            $request->merge(['is_active' => filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN)]);
        }
        $data = $request->validate([
            'code' => ['required', 'string', 'min:3', 'max:40', Rule::unique('landing_coupons', 'code')->where('organization_id', $organization->id)->ignore($coupon?->id)],
            'type' => ['required', 'in:percent,fixed'],
            'value' => ['required', 'integer', 'min:1', 'max:100000000'],
            'max_discount' => ['nullable', 'integer', 'min:1', 'max:100000000'],
            'min_purchase' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'max_uses' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'product_ids' => ['nullable', 'array', 'max:200'],
            'product_ids.*' => ['integer'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'is_active' => ['sometimes', 'boolean'],
        ], ['code.unique' => 'Kode kupon sudah dipakai di toko kamu.'], ['code' => 'kode kupon', 'value' => 'nilai diskon']);

        if ($data['type'] === LandingCoupon::TYPE_PERCENT && (int) $data['value'] > 100) {
            throw ValidationException::withMessages(['value' => 'Diskon persen maksimal 100.']);
        }
        if (!empty($data['product_ids'])) {
            // Only this seller's products.
            $data['product_ids'] = LandingProduct::query()->where('organization_id', $organization->id)
                ->whereIn('id', $data['product_ids'])->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        }
        $data['min_purchase'] = (int) ($data['min_purchase'] ?? 0);
        $data['product_ids'] = !empty($data['product_ids']) ? $data['product_ids'] : null;

        return $data;
    }

    /** @return array<string, mixed> */
    private function payload(LandingCoupon $c): array
    {
        return [
            'id' => $c->id,
            'code' => $c->code,
            'type' => $c->type,
            'value' => (int) $c->value,
            'max_discount' => $c->max_discount,
            'min_purchase' => (int) $c->min_purchase,
            'max_uses' => $c->max_uses,
            'used_count' => (int) $c->used_count,
            'product_ids' => $c->product_ids ?? [],
            'starts_at' => optional($c->starts_at)->toIso8601String(),
            'ends_at' => optional($c->ends_at)->toIso8601String(),
            'is_active' => (bool) $c->is_active,
            'status' => !$c->is_active ? 'inactive' : ($c->ends_at && $c->ends_at->isPast() ? 'ended'
                : ($c->max_uses !== null && $c->used_count >= $c->max_uses ? 'used_up' : ($c->starts_at && $c->starts_at->isFuture() ? 'scheduled' : 'active'))),
        ];
    }
}
