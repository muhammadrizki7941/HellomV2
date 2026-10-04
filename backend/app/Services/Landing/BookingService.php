<?php

namespace App\Services\Landing;

use App\Jobs\SendPlatformMail;
use App\Models\LandingBooking;
use App\Models\LandingPageOrder;
use App\Models\LandingProduct;
use App\Models\Organization;
use App\Support\FrontendUrl;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Sewa / booking jadwal (product type "rental"). Two modes, chosen per product:
 *   daily — the buyer picks a start date and a number of days/nights (rental car, camera, villa);
 *   slot  — a date and a start time, in sessions of N minutes inside opening hours (studio, court).
 * Each product has a number of units that can be out at the same time. The order's time is held
 * while the buyer pays (held_until = order expiry), confirmed when paid, released when the order
 * expires/fails, cancelled on refund. Capacity is checked with the product row locked, so two
 * buyers can never take the last unit. All times are WIB wall-clock (Asia/Jakarta).
 */
final class BookingService
{
    public const TZ = 'Asia/Jakarta';

    private const DAYS = [1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'];

    /**
     * Clean settings for a rental product (also used to validate the seller's input).
     *
     * @return array{mode: string, units: int, unit_label: string, min_days: int, max_days: int, slot_minutes: int, max_slots: int,
     *               hours: array<string, ?array{0: string, 1: string}>, lead_hours: int, max_days_ahead: int, blocked_dates: list<string>}
     */
    public static function normalize(mixed $input): array
    {
        $in = is_array($input) ? $input : [];
        $mode = ($in['mode'] ?? 'daily') === 'slot' ? 'slot' : 'daily';
        $int = fn (string $key, int $default, int $min, int $max) => max($min, min($max, (int) ($in[$key] ?? $default)));
        $minDays = $int('min_days', 1, 1, 60);
        $hours = [];
        foreach (range(1, 7) as $day) {
            // A day the seller set to null is closed; only a day not sent at all gets the default.
            $given = is_array($in['hours'] ?? null) ? $in['hours'] : null;
            $value = $given !== null && array_key_exists((string) $day, $given) ? $given[(string) $day]
                : ($given !== null && array_key_exists($day, $given) ? $given[$day] : ($given === null ? ($day === 7 ? null : ['09:00', '17:00']) : null));
            $open = is_array($value) ? self::time($value[0] ?? null) : null;
            $close = is_array($value) ? self::time($value[1] ?? null) : null;
            $hours[(string) $day] = $open !== null && $close !== null && $open < $close ? [$open, $close] : null;
        }
        $blocked = [];
        foreach ((array) ($in['blocked_dates'] ?? []) as $date) {
            if (is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
                $blocked[$date] = true;
            }
        }
        $blocked = array_keys($blocked);
        sort($blocked);
        $slotMinutes = $int('slot_minutes', 60, 15, 480);

        return [
            'mode' => $mode,
            'units' => $int('units', 1, 1, 100),
            'unit_label' => ($in['unit_label'] ?? 'hari') === 'malam' ? 'malam' : 'hari',
            'min_days' => $minDays,
            'max_days' => max($minDays, $int('max_days', 14, 1, 90)),
            'slot_minutes' => (int) (round($slotMinutes / 15) * 15),
            'max_slots' => $int('max_slots', 4, 1, 12),
            'hours' => $hours,
            'lead_hours' => $int('lead_hours', $mode === 'slot' ? 2 : 0, 0, 168),
            'max_days_ahead' => $int('max_days_ahead', 90, 1, 365),
            'blocked_dates' => array_slice($blocked, 0, 400),
        ];
    }

    public function settings(LandingProduct $product): array
    {
        return self::normalize($product->booking_settings);
    }

    /**
     * What the buyer may pick (public).
     *
     * @return array<string, mixed>
     */
    public function publicSettings(LandingProduct $product): array
    {
        $s = $this->settings($product);

        return array_intersect_key($s, array_flip(['mode', 'units', 'unit_label', 'min_days', 'max_days', 'slot_minutes', 'max_slots', 'hours', 'max_days_ahead']));
    }

    /**
     * Free units per day of a month (daily mode) or per session of a day (slot mode).
     *
     * @return array<string, mixed>
     */
    public function availability(LandingProduct $product, string $month, ?string $date = null): array
    {
        $s = $this->settings($product);
        $now = CarbonImmutable::now(self::TZ);
        $earliest = $now->addHours($s['lead_hours']);
        $latest = $now->startOfDay()->addDays($s['max_days_ahead']);

        if ($s['mode'] === 'slot' && $date !== null) {
            $day = $this->parseDate($date);
            $slots = [];
            foreach ($this->sessions($s, $day) as [$start, $end]) {
                $bookable = $start >= $earliest && $start < $latest && !in_array($day->toDateString(), $s['blocked_dates'], true);
                $slots[] = ['start' => $start->format('H:i'), 'end' => $end->format('H:i'),
                    'free' => $bookable ? max(0, $s['units'] - $this->peak($product, $start, $end)) : 0];
            }

            return ['mode' => 'slot', 'date' => $day->toDateString(), 'closed' => $slots === [], 'slots' => $slots];
        }

        $first = $this->parseMonth($month);
        $last = $first->endOfMonth()->startOfDay();
        $bookings = $this->active($product, $first, $last->addDay());
        $days = [];
        for ($d = $first; $d <= $last; $d = $d->addDay()) {
            $key = $d->toDateString();
            $closed = in_array($key, $s['blocked_dates'], true) || $d < $earliest->startOfDay() || $d >= $latest
                || ($s['mode'] === 'slot' && $s['hours'][(string) $d->dayOfWeekIso] === null);
            if ($s['mode'] === 'slot') {
                // A day is "full" when no session has a free unit left.
                $free = 0;
                if (!$closed) {
                    foreach ($this->sessions($s, $d) as [$start, $end]) {
                        if ($start >= $earliest) {
                            $free = max($free, $s['units'] - $this->peakIn($bookings, $start, $end));
                        }
                    }
                }
            } else {
                $free = $closed ? 0 : max(0, $s['units'] - $this->peakIn($bookings, $d, $d->addDay()));
            }
            $days[$key] = $closed ? 0 : $free;
        }

        return ['mode' => $s['mode'], 'month' => $first->format('Y-m'), 'units' => $s['units'], 'days' => $days];
    }

    /**
     * Check the buyer's choice and turn it into a time range (throws a 422 with a clear message).
     *
     * @return array{starts_at: CarbonImmutable, ends_at: CarbonImmutable, duration: int, unit: string, label: string}
     */
    public function resolve(LandingProduct $product, mixed $input): array
    {
        $s = $this->settings($product);
        $in = is_array($input) ? $input : [];
        $now = CarbonImmutable::now(self::TZ);
        $earliest = $now->addHours($s['lead_hours']);
        $latest = $now->startOfDay()->addDays($s['max_days_ahead']);
        $fail = fn (string $message) => throw ValidationException::withMessages(['booking' => $message]);

        if ($s['mode'] === 'daily') {
            $start = $this->parseDate((string) ($in['start_date'] ?? ''), 'Pilih tanggal mulai sewa.');
            $days = (int) ($in['days'] ?? 0);
            if ($days < $s['min_days'] || $days > $s['max_days']) {
                $fail("Lama sewa {$s['min_days']}–{$s['max_days']} {$s['unit_label']}.");
            }
            if ($start < $earliest->startOfDay() || ($s['lead_hours'] > 0 && $start->endOfDay() < $earliest)) {
                $fail('Tanggal itu sudah lewat atau terlalu dekat. Pilih tanggal lain.');
            }
            $end = $start->addDays($days);
            if ($end > $latest->addDay()) {
                $fail("Booking paling jauh {$s['max_days_ahead']} hari dari sekarang.");
            }
            for ($d = $start; $d < $end; $d = $d->addDay()) {
                if (in_array($d->toDateString(), $s['blocked_dates'], true)) {
                    $fail('Tanggal ' . $this->dateLabel($d) . ' tidak tersedia. Pilih tanggal lain.');
                }
            }

            return ['starts_at' => $start, 'ends_at' => $end, 'duration' => $days, 'unit' => $s['unit_label'],
                'label' => $this->dateLabel($start) . ' – ' . $this->dateLabel($end) . " ({$days} {$s['unit_label']})"];
        }

        $day = $this->parseDate((string) ($in['date'] ?? ''), 'Pilih tanggal booking.');
        $time = self::time($in['start_time'] ?? null) ?? $fail('Pilih jam mulai.');
        $count = (int) ($in['slots'] ?? 1);
        if ($count < 1 || $count > $s['max_slots']) {
            $fail("Pilih 1–{$s['max_slots']} sesi.");
        }
        $starts = array_map(fn ($pair) => $pair[0]->format('H:i'), $this->sessions($s, $day));
        if (in_array($day->toDateString(), $s['blocked_dates'], true) || $starts === []) {
            $fail('Hari itu tutup. Pilih tanggal lain.');
        }
        if (!in_array($time, $starts, true)) {
            $fail('Jam mulai tidak tersedia.');
        }
        $start = $day->setTimeFromTimeString($time);
        $end = $start->addMinutes($count * $s['slot_minutes']);
        [, $close] = $s['hours'][(string) $day->dayOfWeekIso];
        if ($end > $day->setTimeFromTimeString($close)) {
            $fail('Durasi melewati jam tutup (' . str_replace(':', '.', $close) . ').');
        }
        if ($start < $earliest) {
            $fail('Jam itu sudah lewat atau terlalu dekat. Pilih jam lain.');
        }
        if ($start >= $latest) {
            $fail("Booking paling jauh {$s['max_days_ahead']} hari dari sekarang.");
        }

        return ['starts_at' => $start, 'ends_at' => $end, 'duration' => $count, 'unit' => 'sesi',
            'label' => $this->dateLabel($start, true) . ', ' . str_replace(':', '.', $start->format('H:i')) . '–' . str_replace(':', '.', $end->format('H:i')) . " WIB ({$count} sesi)"];
    }

    /** Hold the time for a new order. Call inside the order transaction, product row locked. */
    public function hold(LandingProduct $product, LandingPageOrder $order, array $selection, int $units): LandingBooking
    {
        $s = $this->settings($product);
        if ($this->peak($product, $selection['starts_at'], $selection['ends_at'], $s) + $units > $s['units']) {
            throw ValidationException::withMessages(['booking' => $s['units'] > 1
                ? 'Jadwal itu sudah penuh untuk ' . $units . ' unit. Pilih waktu lain atau kurangi jumlah.'
                : 'Jadwal itu baru saja dibooking orang lain. Pilih waktu lain.']);
        }

        return LandingBooking::query()->create([
            'organization_id' => $product->organization_id, 'product_id' => $product->id, 'order_id' => $order->id,
            'starts_at' => $selection['starts_at']->format('Y-m-d H:i:s'), 'ends_at' => $selection['ends_at']->format('Y-m-d H:i:s'),
            'units' => $units, 'status' => LandingBooking::STATUS_HELD, 'held_until' => $order->expires_at,
        ]);
    }

    /** Paid: lock the time. If it had been released (paid after expiry) and someone else took it, flag the order. */
    public function confirmForOrder(LandingPageOrder $order): void
    {
        $booking = LandingBooking::query()->where('order_id', $order->id)->lockForUpdate()->first();
        if (!$booking || $booking->status === LandingBooking::STATUS_CONFIRMED) {
            return;
        }
        if ($booking->status !== LandingBooking::STATUS_HELD) {
            $product = LandingProduct::withTrashed()->find($booking->product_id);
            $s = $product ? $this->settings($product) : ['units' => 1];
            $taken = $product ? $this->peak($product, CarbonImmutable::parse($booking->starts_at->format('Y-m-d H:i:s'), self::TZ),
                CarbonImmutable::parse($booking->ends_at->format('Y-m-d H:i:s'), self::TZ), $s) : 0;
            if ($taken + $booking->units > $s['units']) {
                $meta = is_array($order->metadata) ? $order->metadata : [];
                $meta['booking_conflict'] = true; // the seller sees it and contacts the buyer
                $order->forceFill(['metadata' => $meta]);
            }
        }
        $booking->forceFill(['status' => LandingBooking::STATUS_CONFIRMED, 'confirmed_at' => now(), 'held_until' => null])->save();
    }

    public function releaseForOrder(LandingPageOrder $order): void
    {
        LandingBooking::query()->where('order_id', $order->id)->where('status', LandingBooking::STATUS_HELD)
            ->update(['status' => LandingBooking::STATUS_RELEASED, 'held_until' => null, 'updated_at' => now()]);
    }

    public function cancelForOrder(LandingPageOrder $order): void
    {
        LandingBooking::query()->where('order_id', $order->id)->whereIn('status', [LandingBooking::STATUS_HELD, LandingBooking::STATUS_CONFIRMED])
            ->update(['status' => LandingBooking::STATUS_CANCELLED, 'held_until' => null, 'updated_at' => now()]);
    }

    /**
     * Seller's schedule (Jadwal tab): bookings in a date range, newest status first.
     *
     * @return list<array<string, mixed>>
     */
    public function forSeller(int $organizationId, string $from, string $to): array
    {
        $start = $this->parseDate($from);
        $end = $this->parseDate($to)->addDay();
        if ($end->diffInDays($start, true) > 62) {
            $end = $start->addDays(62);
        }

        return LandingBooking::query()->where('organization_id', $organizationId)
            ->whereIn('status', [LandingBooking::STATUS_CONFIRMED, LandingBooking::STATUS_HELD])
            ->where('starts_at', '<', $end->format('Y-m-d H:i:s'))->where('ends_at', '>', $start->format('Y-m-d H:i:s'))
            ->with(['order:id,reference_id,buyer_name,buyer_phone,buyer_email,quantity,amount,status,expires_at,metadata', 'product:id,name,booking_settings'])
            ->orderBy('starts_at')->limit(500)->get()
            ->filter(fn (LandingBooking $b) => $b->status === LandingBooking::STATUS_CONFIRMED || ($b->held_until && $b->held_until->isFuture()))
            ->map(fn (LandingBooking $b) => [
                'id' => $b->id,
                'status' => $b->status,
                'product' => $b->product?->name,
                'starts_at' => $b->starts_at->format('Y-m-d\TH:i'),
                'ends_at' => $b->ends_at->format('Y-m-d\TH:i'),
                'label' => (string) ($b->order ? data_get($b->order->metadata, 'booking.label', '') : ''),
                'units' => $b->units,
                'buyer_name' => $b->order?->buyer_name,
                'buyer_phone' => $b->order?->buyer_phone,
                'order_reference' => $b->order?->reference_id,
            ])->values()->all();
    }

    /** Email buyer + seller the day before (run hourly; each booking once). */
    public function remindDue(): int
    {
        $tomorrow = CarbonImmutable::now(self::TZ)->addDay()->startOfDay();
        $due = LandingBooking::query()->where('status', LandingBooking::STATUS_CONFIRMED)->whereNull('reminded_at')
            ->where('starts_at', '>=', $tomorrow->format('Y-m-d H:i:s'))->where('starts_at', '<', $tomorrow->addDay()->format('Y-m-d H:i:s'))
            ->with(['order', 'product:id,name'])->limit(200)->get();
        foreach ($due as $booking) {
            $order = $booking->order;
            $label = (string) data_get($order?->metadata, 'booking.label', $booking->starts_at->format('d M Y H:i'));
            if ($order && $order->buyer_email) {
                SendPlatformMail::dispatch([(string) $order->buyer_email], 'Pengingat: jadwal kamu besok — ' . $booking->product?->name, [
                    'headline' => 'Jadwal kamu besok',
                    'intro' => 'Ini pengingat untuk booking "' . $booking->product?->name . '".',
                    'details' => ['Jadwal' => $label, 'No. pesanan' => (string) $order->reference_id],
                    'cta_url' => FrontendUrl::to('/pesanan/' . $order->reference_id),
                    'cta_label' => 'Lihat pesanan',
                ]);
            }
            $sellerEmails = $this->sellerEmails((int) $booking->organization_id);
            if ($sellerEmails !== []) {
                SendPlatformMail::dispatch($sellerEmails, 'Besok: ' . $booking->product?->name . ' — ' . ($order?->buyer_name ?? ''), [
                    'headline' => 'Ada jadwal besok',
                    'intro' => 'Siapkan unit untuk booking ini.',
                    'details' => array_filter(['Jadwal' => $label, 'Produk' => (string) $booking->product?->name, 'Pembeli' => (string) $order?->buyer_name,
                        'WhatsApp' => (string) $order?->buyer_phone, 'Jumlah unit' => (string) $booking->units]),
                    'cta_url' => FrontendUrl::to('/dashboard/apps/landing-builder?tab=jadwal'),
                    'cta_label' => 'Buka Jadwal',
                ]);
            }
            $booking->forceFill(['reminded_at' => now()])->save();
        }

        return $due->count();
    }

    public function dateLabel(CarbonImmutable $date, bool $weekday = false): string
    {
        $months = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

        return ($weekday ? self::DAYS[$date->dayOfWeekIso] . ', ' : '') . $date->day . ' ' . $months[$date->month] . ' ' . $date->year;
    }

    // ── internals ──

    /** @return list<array{0: CarbonImmutable, 1: CarbonImmutable}> sessions of a day */
    private function sessions(array $s, CarbonImmutable $day): array
    {
        $hours = $s['hours'][(string) $day->dayOfWeekIso] ?? null;
        if ($hours === null) {
            return [];
        }
        $out = [];
        $close = $day->setTimeFromTimeString($hours[1]);
        for ($t = $day->setTimeFromTimeString($hours[0]); $t->addMinutes($s['slot_minutes']) <= $close; $t = $t->addMinutes($s['slot_minutes'])) {
            $out[] = [$t, $t->addMinutes($s['slot_minutes'])];
        }

        return $out;
    }

    /** Most units out at the same moment within [from, to). */
    private function peak(LandingProduct $product, CarbonImmutable $from, CarbonImmutable $to, ?array $s = null): int
    {
        return $this->peakIn($this->active($product, $from, $to), $from, $to);
    }

    /** @param Collection<int, LandingBooking> $bookings */
    private function peakIn(Collection $bookings, CarbonImmutable $from, CarbonImmutable $to): int
    {
        $points = [];
        foreach ($bookings as $b) {
            $start = max($b->starts_at->format('Y-m-d H:i:s'), $from->format('Y-m-d H:i:s'));
            $end = min($b->ends_at->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s'));
            if ($start < $end) {
                $points[] = [$start, $b->units];
                $points[] = [$end, -$b->units];
            }
        }
        usort($points, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]); // ends before starts at the same moment
        $current = $max = 0;
        foreach ($points as [, $delta]) {
            $current += $delta;
            $max = max($max, $current);
        }

        return $max;
    }

    /** @return Collection<int, LandingBooking> held (not yet expired) or confirmed bookings overlapping [from, to) */
    private function active(LandingProduct $product, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return LandingBooking::query()->where('product_id', $product->id)
            ->where('starts_at', '<', $to->format('Y-m-d H:i:s'))->where('ends_at', '>', $from->format('Y-m-d H:i:s'))
            ->where(fn ($q) => $q->where('status', LandingBooking::STATUS_CONFIRMED)
                ->orWhere(fn ($h) => $h->where('status', LandingBooking::STATUS_HELD)->where('held_until', '>', now())))
            ->get(['id', 'starts_at', 'ends_at', 'units']);
    }

    private function parseDate(string $value, string $message = 'Tanggal tidak valid.'): CarbonImmutable
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) || !checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
            throw ValidationException::withMessages(['booking' => $message]);
        }

        return CarbonImmutable::createFromFormat('Y-m-d', $value, self::TZ)->startOfDay();
    }

    private function parseMonth(string $value): CarbonImmutable
    {
        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value)
            ? CarbonImmutable::createFromFormat('Y-m-d', $value . '-01', self::TZ)->startOfDay()
            : CarbonImmutable::now(self::TZ)->startOfMonth();
    }

    private static function time(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) ? $value : null;
    }

    /** @return list<string> */
    private function sellerEmails(int $organizationId): array
    {
        $organization = Organization::query()->with('users')->find($organizationId);

        return $organization ? $organization->users->filter(fn ($u) => in_array((string) ($u->pivot->role ?? ''), ['owner', 'admin'], true))
            ->pluck('email')->filter()->unique()->values()->all() : [];
    }
}
