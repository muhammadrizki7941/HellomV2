<?php

namespace App\Http\Controllers\Api\V1\Hellom;

use App\Models\ProductPurchaseSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductPurchaseSettingController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        $organizationId = (int) ($user->current_organization_id ?? 0);
        if ($organizationId <= 0) {
            return $this->fail('No active organization', ['code' => 'NO_ACTIVE_ORGANIZATION'], 403);
        }

        $settings = ProductPurchaseSetting::query()
            ->where('organization_id', $organizationId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return $this->ok($settings->map(fn($s) => $this->formatSetting($s)), 'Purchase settings retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        $organizationId = (int) ($user->current_organization_id ?? 0);
        if ($organizationId <= 0) {
            return $this->fail('No active organization', ['code' => 'NO_ACTIVE_ORGANIZATION'], 403);
        }

        $validated = $request->validate([
            'service_type' => ['required', 'string', 'in:dine_in,take_away,delivery,pre_order'],
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:500'],
            'enabled' => ['nullable', 'boolean'],
            'order_timing' => ['nullable', 'string', 'in:immediate,scheduled,reservation'],
            'lead_time_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'available_days' => ['nullable', 'array'],
            'available_days.*' => ['string', 'in:mon,tue,wed,thu,fri,sat,sun'],
            'start_time' => ['nullable', 'date_format:H:i:s'],
            'end_time' => ['nullable', 'date_format:H:i:s'],
            'require_payment_first' => ['nullable', 'boolean'],
            'require_table' => ['nullable', 'boolean'],
            'require_reservation' => ['nullable', 'boolean'],
            'max_order_per_day' => ['nullable', 'integer', 'min:1'],
            'min_order_amount' => ['nullable', 'integer', 'min:0'],
            'max_order_amount' => ['nullable', 'integer', 'min:1'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_default' => ['nullable', 'boolean'],
        ]);

        // Check if service_type already exists
        $existing = ProductPurchaseSetting::query()
            ->where('organization_id', $organizationId)
            ->where('service_type', $validated['service_type'])
            ->first();

        if ($existing) {
            return $this->fail('Service type already exists', ['code' => 'SERVICE_TYPE_EXISTS'], 422);
        }

        // If setting as default, unset other defaults
        if (!empty($validated['is_default'])) {
            ProductPurchaseSetting::query()
                ->where('organization_id', $organizationId)
                ->update(['is_default' => false]);
        }

        $setting = ProductPurchaseSetting::query()->create([
            ...$validated,
            'organization_id' => $organizationId,
        ]);

        return $this->ok($this->formatSetting($setting), 'Purchase setting created', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        $organizationId = (int) ($user->current_organization_id ?? 0);
        if ($organizationId <= 0) {
            return $this->fail('No active organization', ['code' => 'NO_ACTIVE_ORGANIZATION'], 403);
        }

        $setting = ProductPurchaseSetting::query()
            ->where('id', $id)
            ->where('organization_id', $organizationId)
            ->first();

        if (!$setting) {
            return $this->fail('Setting not found', ['code' => 'SETTING_NOT_FOUND'], 404);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:500'],
            'enabled' => ['nullable', 'boolean'],
            'order_timing' => ['nullable', 'string', 'in:immediate,scheduled,reservation'],
            'lead_time_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'available_days' => ['nullable', 'array'],
            'available_days.*' => ['string', 'in:mon,tue,wed,thu,fri,sat,sun'],
            'start_time' => ['nullable', 'date_format:H:i:s'],
            'end_time' => ['nullable', 'date_format:H:i:s'],
            'require_payment_first' => ['nullable', 'boolean'],
            'require_table' => ['nullable', 'boolean'],
            'require_reservation' => ['nullable', 'boolean'],
            'max_order_per_day' => ['nullable', 'integer', 'min:1'],
            'min_order_amount' => ['nullable', 'integer', 'min:0'],
            'max_order_amount' => ['nullable', 'integer', 'min:1'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_default' => ['nullable', 'boolean'],
        ]);

        // If setting as default, unset other defaults
        if (!empty($validated['is_default'])) {
            ProductPurchaseSetting::query()
                ->where('organization_id', $organizationId)
                ->where('id', '!=', $id)
                ->update(['is_default' => false]);
        }

        $setting->forceFill($validated)->save();

        return $this->ok($this->formatSetting($setting), 'Purchase setting updated');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        $organizationId = (int) ($user->current_organization_id ?? 0);
        if ($organizationId <= 0) {
            return $this->fail('No active organization', ['code' => 'NO_ACTIVE_ORGANIZATION'], 403);
        }

        $setting = ProductPurchaseSetting::query()
            ->where('id', $id)
            ->where('organization_id', $organizationId)
            ->first();

        if (!$setting) {
            return $this->fail('Setting not found', ['code' => 'SETTING_NOT_FOUND'], 404);
        }

        // If deleted setting was default, make another one default
        if ($setting->is_default) {
            $nextDefault = ProductPurchaseSetting::query()
                ->where('organization_id', $organizationId)
                ->where('id', '!=', $id)
                ->orderBy('sort_order')
                ->first();

            if ($nextDefault) {
                $nextDefault->forceFill(['is_default' => true])->save();
            }
        }

        $setting->delete();

        return $this->ok(null, 'Purchase setting deleted');
    }

    public function getActive(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        $organizationId = (int) ($user->current_organization_id ?? 0);
        if ($organizationId <= 0) {
            return $this->fail('No active organization', ['code' => 'NO_ACTIVE_ORGANIZATION'], 403);
        }

        $serviceType = $request->query('service_type');

        $query = ProductPurchaseSetting::query()
            ->where('organization_id', $organizationId)
            ->where('enabled', true);

        if ($serviceType) {
            $query->where('service_type', $serviceType);
        }

        $settings = $query->orderBy('is_default', 'desc')
            ->orderBy('sort_order')
            ->get();

        return $this->ok($settings->map(fn($s) => $this->formatSetting($s)), 'Active purchase settings retrieved');
    }

    private function formatSetting(ProductPurchaseSetting $setting): array
    {
        return [
            'id' => $setting->id,
            'service_type' => $setting->service_type,
            'name' => $setting->name,
            'description' => $setting->description,
            'enabled' => $setting->enabled,
            'order_timing' => $setting->order_timing,
            'lead_time_minutes' => $setting->lead_time_minutes,
            'available_days' => $setting->available_days,
            'start_time' => $setting->start_time,
            'end_time' => $setting->end_time,
            'require_payment_first' => $setting->require_payment_first,
            'require_table' => $setting->require_table,
            'require_reservation' => $setting->require_reservation,
            'max_order_per_day' => $setting->max_order_per_day,
            'min_order_amount' => $setting->min_order_amount,
            'max_order_amount' => $setting->max_order_amount,
            'sort_order' => $setting->sort_order,
            'is_default' => $setting->is_default,
            'is_available_now' => $setting->isAvailableNow(),
            'next_available_time' => $setting->getNextAvailableTime()?->toISOString(),
        ];
    }
}