<?php

namespace App\Services\Reservations;

use App\Models\Reservation;
use App\Models\ReservationSpace;
use App\Models\Product;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ReservationBookingService
{
    public function ensureAvailableOrFail(ReservationSpace $space, Carbon $start, int $durationMinutes): void
    {
        $end = (clone $start)->addMinutes($durationMinutes);

        $hasConflict = Reservation::query()
            ->where('reservation_space_id', $space->id)
            ->whereIn('status', ['pending', 'confirmed'])
            ->where('scheduled_at', '<', $end)
            ->whereRaw('DATE_ADD(scheduled_at, INTERVAL duration_minutes MINUTE) > ?', [$start->toDateTimeString()])
            ->exists();

        if ($hasConflict) {
            throw new \RuntimeException('Jadwal bentrok. Silakan pilih waktu lain.');
        }
    }

    /**
     * @param array<string,mixed> $validated
     */
    public function createReservation(?User $user, ReservationSpace $space, array $validated): Reservation
    {
        if (!$space->is_active) {
            throw new \RuntimeException('Space tidak tersedia.');
        }

        $scheduledAt = Carbon::parse($validated['scheduled_at']);
        $durationMinutes = (int) $validated['duration_minutes'];

        $this->ensureAvailableOrFail($space, $scheduledAt, $durationMinutes);

        $space->load(['items']);

        $itemsSnapshot = [];
        $itemsTotal = 0;
        $hasSelectedSpaceItems = array_key_exists('selected_space_items', $validated);

        if ($hasSelectedSpaceItems) {
            $selectedItems = is_array($validated['selected_space_items'] ?? null)
                ? $validated['selected_space_items']
                : [];
            $selectedItemMap = [];

            foreach ($selectedItems as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $itemId = (int) ($row['item_id'] ?? 0);
                $qty = max(0, min(99, (int) ($row['qty'] ?? 0)));

                if ($itemId <= 0 || $qty <= 0) {
                    continue;
                }

                $selectedItemMap[$itemId] = $qty;
            }

            foreach ($space->items as $it) {
                $selectedQty = $selectedItemMap[$it->id] ?? null;
                if ($it->is_required && ($selectedQty === null || $selectedQty <= 0)) {
                    $selectedQty = max(1, (int) $it->qty);
                }

                if ($selectedQty === null || $selectedQty <= 0) {
                    continue;
                }

                $unitPrice = (int) $it->unit_price;
                $lineTotal = $unitPrice * $selectedQty;
                $itemsTotal += $lineTotal;

                $itemsSnapshot[] = [
                    'reservation_space_item_id' => (int) $it->id,
                    'product_id' => $it->product_id,
                    'product_name' => $it->product_name,
                    'unit_price' => $unitPrice,
                    'qty' => $selectedQty,
                    'line_total' => $lineTotal,
                    'is_required' => (bool) $it->is_required,
                ];
            }
        } else {
            $itemsSnapshot = $space->items->map(fn ($it) => [
                'reservation_space_item_id' => (int) $it->id,
                'product_id' => $it->product_id,
                'product_name' => $it->product_name,
                'unit_price' => (int) $it->unit_price,
                'qty' => (int) $it->qty,
                'line_total' => (int) $it->line_total,
                'is_required' => (bool) $it->is_required,
            ])->values()->all();

            $itemsTotal = (int) $space->items_total;
        }
        $rentPrice = $space->rent_enabled ? (int) $space->rent_price : 0;
        $menuItems = $validated['menu_items'] ?? [];

        /** @var array<int, array{product_id:int,qty:int}> $menuItems */
        $menuItems = is_array($menuItems) ? $menuItems : [];

        $menuMap = [];
        foreach ($menuItems as $row) {
            $pid = (int) ($row['product_id'] ?? 0);
            $qty = (int) ($row['qty'] ?? 0);
            if ($pid <= 0 || $qty <= 0) continue;
            $menuMap[$pid] = ($menuMap[$pid] ?? 0) + $qty;
        }

        $menuProductIds = array_keys($menuMap);
        $menuProducts = collect();
        if (!empty($menuProductIds)) {
            $menuProducts = Product::query()
                ->whereIn('id', $menuProductIds)
                ->where('is_available', true)
                ->get()
                ->keyBy('id');
        }

        $menuOrderSnapshot = [];
        $menuCommitmentTotal = 0;

        foreach ($menuProductIds as $pid) {
            $p = $menuProducts->get($pid);
            if (!$p) {
                throw new \RuntimeException('Ada menu yang tidak tersedia. Silakan refresh halaman dan pilih ulang menu.');
            }

            $qty = (int) ($menuMap[$pid] ?? 0);
            if ($qty <= 0) continue;

            if ((bool) ($p->track_stock ?? false)) {
                $stock = (int) ($p->stock ?? 0);
                if ($stock <= 0) {
                    throw new \RuntimeException('Stok habis untuk: '.(string) $p->name);
                }
                if ($qty > $stock) {
                    throw new \RuntimeException('Stok tidak cukup untuk: '.(string) $p->name.' (maks '.number_format($stock, 0, ',', '.').')');
                }
            }

            $unit = (int) ($p->price ?? 0);
            $line = $unit * $qty;
            $menuCommitmentTotal += $line;

            $menuOrderSnapshot[] = [
                'product_id' => (int) $p->id,
                'product_name' => (string) $p->name,
                'unit_price' => $unit,
                'qty' => $qty,
                'line_total' => $line,
            ];
        }

        $minMenu = (int) ($space->min_menu_total ?? 0);
        if ($minMenu > 0 && $menuCommitmentTotal < $minMenu) {
            throw new \RuntimeException('Minimum order for this space is Rp '.number_format($minMenu, 0, ',', '.').'.');
        }

        $totalPrice = $rentPrice + $itemsTotal + $menuCommitmentTotal;

        return DB::transaction(function () use ($user, $space, $validated, $scheduledAt, $durationMinutes, $itemsSnapshot, $menuOrderSnapshot, $rentPrice, $itemsTotal, $menuCommitmentTotal, $totalPrice) {
            return Reservation::query()->create([
                'tenant_id' => $space->tenant_id,
                'user_id' => $user?->id,
                'reservation_space_id' => $space->id,
                'space_name' => $space->name,
                'items_snapshot' => $itemsSnapshot,
                'menu_order_snapshot' => $menuOrderSnapshot,

                'customer_name' => (string) (($validated['customer_name'] ?? null) ?: ($user?->name ?? '')),
                'customer_phone' => (string) (($validated['customer_phone'] ?? null) ?: ($user?->phone ?? '')),
                'customer_email' => (($validated['customer_email'] ?? null) ?: ($user?->email ?? null)) ?: null,

                'scheduled_at' => $scheduledAt,
                'duration_minutes' => $durationMinutes,
                'guests_count' => isset($validated['guests_count']) ? (int) $validated['guests_count'] : null,

                'notes' => ($validated['notes'] ?? null) ?: null,

                'rent_price' => $rentPrice,
                'items_total' => $itemsTotal,
                'menu_commitment_total' => $menuCommitmentTotal,
                'total_price' => $totalPrice,

                'status' => 'pending',
            ]);
        });
    }
}
