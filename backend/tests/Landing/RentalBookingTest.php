<?php

namespace Tests\Landing;

use App\Http\Middleware\Api\EnsureAppEntitlement;
use App\Jobs\SendPlatformMail;
use App\Models\LandingBooking;
use App\Models\LandingPageOrder;
use App\Models\LandingProduct;
use App\Services\Landing\BookingService;
use App\Services\Landing\ProductService;
use App\Services\SellerFinance\LandingPaymentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

/** Sewa / booking jadwal: per day and per session, units, no double booking, expiry, refund, reminders. */
class RentalBookingTest extends SellerFinanceTestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnsureAppEntitlement::class);
    }

    private function rental(array $seller, array $booking, int $price = 150000): LandingProduct
    {
        return app(ProductService::class)->save($seller['org'], ['type' => 'rental', 'name' => 'Sewa Kamera Sony A7', 'price' => $price,
            'delivery_note' => 'Ambil di toko, bawa KTP asli.', 'booking' => $booking]);
    }

    private function checkout(LandingProduct $product, array $booking, int $quantity = 1, string $email = 'sari@example.test'): \Illuminate\Testing\TestResponse
    {
        Http::swap(new HttpFactory());
        Http::preventStrayRequests();
        Http::fake(['*/api/v2/payment/direct' => Http::response(['Status' => 200, 'Data' => ['SessionId' => 'sess-q', 'TransactionId' => 'trx-q', 'QrString' => '000201']]),
            '*/api/v2/payment' => Http::response(['Status' => 200, 'Data' => ['SessionID' => 'sess-1', 'Url' => 'https://sandbox.ipaymu.com/pay/sess-1']])]);

        return $this->postJson("/api/v1/hellom/public/landing-products/{$product->public_id}/checkout", [
            'buyer_name' => 'Sari Penyewa', 'buyer_email' => $email, 'buyer_phone' => '081234567890', 'quantity' => $quantity, 'booking' => $booking,
        ]);
    }

    private function lastOrder(LandingProduct $product): LandingPageOrder
    {
        return LandingPageOrder::query()->where('product_id', $product->id)->latest('id')->firstOrFail();
    }

    private function pay(LandingPageOrder $order): void
    {
        $trx = 'trx-' . $order->id;
        $this->fakeIpaymuTransaction($trx, (string) $order->reference_id, (int) $order->amount);
        $this->ipaymuWebhook($order, $trx)->assertOk();
    }

    private function day(int $daysAhead): string
    {
        return CarbonImmutable::now(BookingService::TZ)->addDays($daysAhead)->toDateString();
    }

    public function test_daily_rental_prices_by_days_and_never_double_books_the_last_unit(): void
    {
        $seller = $this->seller();
        $product = $this->rental($seller, ['mode' => 'daily', 'units' => 1, 'min_days' => 1, 'max_days' => 7]);
        $this->assertNull($product->stock);

        // Price = per day × days; public payload has the booking rules, not the private note.
        $quote = $this->postJson("/api/v1/hellom/public/landing-products/{$product->public_id}/quote", ['booking' => ['start_date' => $this->day(5), 'days' => 3]])
            ->assertOk()->json('data');
        $this->assertSame([450000, 3, 'hari'], [$quote['subtotal'], $quote['booking']['duration'], $quote['booking']['unit']]);
        $public = $this->getJson("/api/v1/hellom/public/landing-products/{$product->public_id}")->assertOk()->json('data.product');
        $this->assertSame('daily', $public['booking']['mode']);
        $this->assertStringNotContainsString('KTP', json_encode($public));

        $this->checkout($product, ['start_date' => $this->day(5), 'days' => 3])->assertCreated();
        $first = $this->lastOrder($product);
        $this->assertSame(450000, (int) $first->amount);
        $this->assertSame(LandingBooking::STATUS_HELD, LandingBooking::query()->where('order_id', $first->id)->value('status'));

        // Overlapping dates while the first buyer is paying: refused. Days before/after are fine.
        $this->checkout($product, ['start_date' => $this->day(7), 'days' => 2], 1, 'budi@example.test')
            ->assertStatus(422)->assertJsonValidationErrors('booking');
        $this->checkout($product, ['start_date' => $this->day(8), 'days' => 1], 1, 'budi@example.test')->assertCreated(); // day 8 = return day of the first
        $days = $this->getJson("/api/v1/hellom/public/landing-products/{$product->public_id}/availability?month=" . substr($this->day(5), 0, 7))->json('data.days');
        if (isset($days[$this->day(6)])) {
            $this->assertSame(0, $days[$this->day(6)]);
        }

        // Paid → confirmed, and the email shows the schedule.
        Bus::fake([SendPlatformMail::class]);
        $this->pay($first);
        $this->assertSame(LandingBooking::STATUS_CONFIRMED, LandingBooking::query()->where('order_id', $first->id)->value('status'));
        $this->assertSame(LandingPageOrder::STATUS_PAID, $first->fresh()->status);
        $this->assertStringContainsString('(3 hari)', (string) data_get($first->fresh()->metadata, 'booking.label'));
    }

    public function test_units_unpaid_hold_expires_and_frees_the_time(): void
    {
        $seller = $this->seller();
        $product = $this->rental($seller, ['mode' => 'daily', 'units' => 2]);
        $this->checkout($product, ['start_date' => $this->day(3), 'days' => 2], 2)->assertCreated();
        $order = $this->lastOrder($product);
        $this->assertSame(600000, (int) $order->amount); // 2 units × 2 days × 150.000
        $this->checkout($product, ['start_date' => $this->day(3), 'days' => 1], 1, 'lain@example.test')->assertStatus(422);

        // Not paid in time → order expires → booking released → the dates can be booked again.
        $order->forceFill(['expires_at' => now()->subMinute()])->save();
        LandingBooking::query()->where('order_id', $order->id)->update(['held_until' => now()->subMinute()]);
        app(LandingPaymentService::class)->expireDue();
        $this->assertSame(LandingBooking::STATUS_RELEASED, LandingBooking::query()->where('order_id', $order->id)->value('status'));
        $this->checkout($product, ['start_date' => $this->day(3), 'days' => 1], 2, 'lain@example.test')->assertCreated();
    }

    public function test_session_bookings_follow_opening_hours(): void
    {
        $seller = $this->seller();
        $hours = array_fill_keys(['1', '2', '3', '4', '5', '6', '7'], ['09:00', '12:00']);
        $date = $this->day(4);
        $closedDay = (string) CarbonImmutable::parse($this->day(5), BookingService::TZ)->dayOfWeekIso;
        $hours[$closedDay] = null;
        $product = $this->rental($seller, ['mode' => 'slot', 'units' => 1, 'slot_minutes' => 60, 'max_slots' => 3, 'hours' => $hours], 100000);

        $slots = $this->getJson("/api/v1/hellom/public/landing-products/{$product->public_id}/availability?date={$date}")->assertOk()->json('data.slots');
        $this->assertSame(['09:00', '10:00', '11:00'], array_column($slots, 'start'));

        $quote = $this->postJson("/api/v1/hellom/public/landing-products/{$product->public_id}/quote", ['booking' => ['date' => $date, 'start_time' => '10:00', 'slots' => 2]])->json('data');
        $this->assertSame(200000, $quote['subtotal']);
        $this->assertStringContainsString('10.00–12.00 WIB (2 sesi)', $quote['booking']['label']);
        $this->assertSame('Durasi melewati jam tutup (12.00).', $this->postJson("/api/v1/hellom/public/landing-products/{$product->public_id}/quote", ['booking' => ['date' => $date, 'start_time' => '11:00', 'slots' => 2]])->json('data.booking_error'));
        $this->assertSame('Hari itu tutup. Pilih tanggal lain.', $this->postJson("/api/v1/hellom/public/landing-products/{$product->public_id}/quote", ['booking' => ['date' => $this->day(5), 'start_time' => '09:00', 'slots' => 1]])->json('data.booking_error'));

        $this->checkout($product, ['date' => $date, 'start_time' => '10:00', 'slots' => 2])->assertCreated();
        $free = array_column($this->getJson("/api/v1/hellom/public/landing-products/{$product->public_id}/availability?date={$date}")->json('data.slots'), 'free', 'start');
        $this->assertSame(['09:00' => 1, '10:00' => 0, '11:00' => 0], $free);
        $this->checkout($product, ['date' => $date, 'start_time' => '11:00', 'slots' => 1], 1, 'x@example.test')->assertStatus(422);
        $this->checkout($product, ['date' => $date, 'start_time' => '09:00', 'slots' => 1], 1, 'x@example.test')->assertCreated();
    }

    public function test_schedule_is_per_shop_refund_frees_time_and_reminder_goes_out_once(): void
    {
        $seller = $this->seller();
        $other = $this->seller();
        $product = $this->rental($seller, ['mode' => 'daily', 'units' => 1]);
        $this->checkout($product, ['start_date' => $this->day(1), 'days' => 1])->assertCreated();
        $order = $this->lastOrder($product);
        $this->pay($order);

        $range = ['from' => $this->day(0), 'to' => $this->day(10)];
        $items = $this->getJson('/api/v1/hellom/apps/landing-builder/bookings?' . http_build_query($range), ['Authorization' => 'Bearer ' . $seller['token']])->assertOk()->json('data.items');
        $this->assertCount(1, $items);
        $this->assertSame(['confirmed', 'Sari Penyewa', '081234567890'], [$items[0]['status'], $items[0]['buyer_name'], $items[0]['buyer_phone']]);
        $this->assertStringContainsString('(1 hari)', $items[0]['label']);
        $this->assertSame([], $this->getJson('/api/v1/hellom/apps/landing-builder/bookings?' . http_build_query($range), ['Authorization' => 'Bearer ' . $other['token']])->json('data.items'));
        $this->getJson('/api/v1/hellom/seller/orders', ['Authorization' => 'Bearer ' . $seller['token']])->assertOk()
            ->assertJsonPath('data.data.0.booking.units', 1)->assertJsonPath('data.data.0.needs_action', true);

        // H-1 reminder (booking starts tomorrow): buyer + seller, only once.
        Bus::fake([SendPlatformMail::class]);
        $this->assertSame(1, app(BookingService::class)->remindDue());
        $this->assertSame(0, app(BookingService::class)->remindDue());
        Bus::assertDispatched(SendPlatformMail::class, fn (SendPlatformMail $j) => $j->to === ['sari@example.test'] && str_starts_with($j->subject, 'Pengingat: jadwal kamu besok'));

        // Refunded → time free again.
        app(BookingService::class)->cancelForOrder($order);
        $this->assertSame(LandingBooking::STATUS_CANCELLED, LandingBooking::query()->where('order_id', $order->id)->value('status'));
        $this->checkout($product, ['start_date' => $this->day(1), 'days' => 1], 1, 'baru@example.test')->assertCreated();
    }
}
