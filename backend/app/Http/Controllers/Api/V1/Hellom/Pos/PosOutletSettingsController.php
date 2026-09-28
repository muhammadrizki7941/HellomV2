<?php

namespace App\Http\Controllers\Api\V1\Hellom\Pos;

use App\Models\AuditLog;
use App\Services\Pos\OutletSettings;
use App\Services\Pos\SelfOrderGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Pricing (tax, service charge, rounding), self-order behaviour and opening hours of the
 * active outlet. Read by any POS user; changed by the owner/admin only (audit-logged).
 */
class PosOutletSettingsController extends BasePosController
{
    public function show(Request $request, SelfOrderGate $gate): JsonResponse
    {
        $outlet = $this->posOutlet($request);
        if (!$outlet) {
            return $this->error('Konteks POS tidak tersedia', 'CONTEXT_MISSING');
        }

        return $this->success([
            'outlet_id' => $outlet->id,
            'settings' => OutletSettings::for($outlet)->toArray(),
            'status' => $gate->status($outlet),
            'payment_methods' => $gate->paymentMethods($outlet),
        ], 'Pengaturan outlet');
    }

    public function update(Request $request, SelfOrderGate $gate): JsonResponse
    {
        $outlet = $this->posOutlet($request);
        $org = $this->getOrg($request);
        if (!$outlet || !$org) {
            return $this->error('Konteks POS tidak tersedia', 'CONTEXT_MISSING');
        }
        if (!$this->isOrgOwner($request, $org)) {
            return $this->error('Hanya owner/admin yang bisa mengubah pengaturan outlet', 'FORBIDDEN', null, 403);
        }

        $validated = $request->validate([
            'pricing' => 'sometimes|array',
            'pricing.tax_percent' => 'required_with:pricing|numeric|min:0|max:100',
            'pricing.service_percent' => 'required_with:pricing|numeric|min:0|max:100',
            'pricing.rounding' => ['required_with:pricing', 'integer', Rule::in(OutletSettings::ROUNDING_STEPS)],
            'self_order' => 'sometimes|array',
            'self_order.accept_orders' => 'required_with:self_order|boolean',
            'self_order.require_confirmation' => 'required_with:self_order|boolean',
            'self_order.max_pending_per_table' => 'required_with:self_order|integer|min:1|max:20',
            'opening_hours' => 'sometimes|nullable|array',
            'opening_hours.*' => 'array',
            'opening_hours.*.*.open' => ['required', 'regex:/^\d{2}:\d{2}$/'],
            'opening_hours.*.*.close' => ['required', 'regex:/^\d{2}:\d{2}$/'],
            'timezone' => 'sometimes|timezone',
        ]);

        $before = OutletSettings::for($outlet)->toArray();
        $outlet->forceFill(['settings' => OutletSettings::merge((array) ($outlet->settings ?? []), $validated)])->save();
        $after = OutletSettings::for($outlet)->toArray();

        AuditLog::record('pos.outlet.settings_updated', $request->user()?->id, $org->id, 'outlet', $outlet->id, $before, $after,
            null, $request->ip(), mb_substr((string) $request->userAgent(), 0, 255));

        return $this->success([
            'outlet_id' => $outlet->id,
            'settings' => $after,
            'status' => $gate->status($outlet),
            'payment_methods' => $gate->paymentMethods($outlet),
        ], 'Pengaturan outlet disimpan');
    }
}
