<?php

namespace App\Services\Pos;

use App\Models\DiningTable;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Outlet;
use App\Models\PosPaymentSetting;

/**
 * Rules in front of the public self-order page:
 *  - a QR token opens exactly one table of one active outlet (tenant + outlet + table);
 *  - the shop link (no table) orders through the outlet's "counter" pseudo-table;
 *  - the outlet must accept self-orders and be open;
 *  - a table may have at most N orders waiting for confirmation;
 *  - payment methods and prices come from that outlet only.
 */
final class SelfOrderGate
{
    /** @return array{0: DiningTable, 1: Outlet}|null */
    public function resolveTable(string $token): ?array
    {
        $token = trim($token);
        if ($token === '' || strlen($token) > 64) {
            return null;
        }
        $table = DiningTable::withoutGlobalScope('tenant')
            ->where('public_id', $token)
            ->where('is_active', true)
            ->first();
        if (!$table) {
            return null;
        }
        $outlet = $this->outletOf($table);

        return $outlet ? [$table, $outlet] : null;
    }

    /** The outlet a table belongs to — must be active and match the table's tenant slug. */
    public function outletOf(DiningTable $table): ?Outlet
    {
        $outlet = $table->outlet_id
            ? Outlet::query()->find($table->outlet_id)
            : Outlet::query()->where('tenant_slug', $table->tenant_id)->first();

        if (!$outlet || !$outlet->is_active || (string) $outlet->tenant_slug !== (string) $table->tenant_id) {
            return null;
        }
        if (!$table->outlet_id) {
            $table->forceFill(['outlet_id' => $outlet->id])->save();
        }

        return $outlet;
    }

    /** The outlet's pseudo-table for orders placed from the shop link (created on first use). */
    public function counterTable(Outlet $outlet): DiningTable
    {
        $existing = DiningTable::withoutGlobalScope('tenant')
            ->where('outlet_id', $outlet->id)
            ->where('kind', DiningTable::KIND_COUNTER)
            ->first();
        if ($existing) {
            if (!$existing->is_active) {
                $existing->forceFill(['is_active' => true])->save();
            }

            return $existing;
        }

        return DiningTable::withoutGlobalScope('tenant')->create([
            'tenant_id' => $outlet->tenant_slug,
            'outlet_id' => $outlet->id,
            'code' => 'ONLINE-' . $outlet->id,
            'name' => 'Pesanan Online',
            'kind' => DiningTable::KIND_COUNTER,
            'is_active' => true,
        ]);
    }

    /** @return array{accepts_orders: bool, is_open: bool, can_order: bool, message: ?string, today_hours: ?string} */
    public function status(Outlet $outlet): array
    {
        $settings = OutletSettings::for($outlet);
        $accepts = $settings->acceptsSelfOrders();
        $open = $settings->isOpen();
        $message = match (true) {
            !$accepts => 'Maaf, pemesanan mandiri sedang tidak tersedia di outlet ini. Silakan pesan langsung ke kasir.',
            !$open => 'Maaf, outlet sedang tutup. ' . ($settings->todayText() ?? ''),
            default => null,
        };

        return [
            'accepts_orders' => $accepts,
            'is_open' => $open,
            'can_order' => $accepts && $open,
            'message' => $message ? trim($message) : null,
            'today_hours' => $settings->todayText(),
        ];
    }

    /** @throws PricingException */
    public function assertCanOrder(Outlet $outlet, DiningTable $table): void
    {
        $status = $this->status($outlet);
        if (!$status['can_order']) {
            throw new PricingException((string) $status['message'], [], 'OUTLET_CLOSED', 423);
        }

        // The counter is shared by every online customer; the rate limiter guards it instead.
        if (($table->kind ?? DiningTable::KIND_TABLE) === DiningTable::KIND_TABLE) {
            $max = OutletSettings::for($outlet)->maxPendingPerTable();
            $pending = Order::withoutGlobalScope('tenant')
                ->where('dining_table_id', $table->id)
                ->where('status', Order::STATUS_NEW)
                ->count();
            if ($pending >= $max) {
                throw new PricingException('Masih ada pesanan di meja ini yang menunggu konfirmasi kasir. Tunggu sebentar sebelum memesan lagi.', [], 'TOO_MANY_PENDING', 429);
            }
        }
    }

    /**
     * Payment choices the customer can pick (the actual payment happens at the cashier).
     * The outlet's own settings win; otherwise the organization's primary outlet settings.
     *
     * @return list<string>
     */
    public function paymentMethods(Outlet $outlet): array
    {
        $setting = $this->paymentSetting($outlet);
        if (!$setting) {
            return ['cash'];
        }
        $methods = [];
        foreach (['cash', 'transfer', 'gopay', 'dana', 'qris'] as $method) {
            if ($method === 'cash' ? ($setting->cash_enabled ?? true) : $setting->{$method . '_enabled'}) {
                $methods[] = $method;
            }
        }

        return $methods ?: ['cash'];
    }

    public function paymentSetting(Outlet $outlet): ?PosPaymentSetting
    {
        $own = PosPaymentSetting::query()
            ->where(fn ($q) => $q->where('outlet_id', $outlet->id)->orWhere('tenant_id', $outlet->tenant_slug))
            ->first();
        if ($own) {
            return $own;
        }
        $organization = Organization::query()->find($outlet->organization_id);

        return $organization
            ? PosPaymentSetting::query()->where('tenant_id', LoyaltyService::organizationSlug($organization))->first()
            : null;
    }
}
