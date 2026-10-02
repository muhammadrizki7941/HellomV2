<?php

namespace Tests\Landing;

use App\Models\DigitalProduct;
use App\Models\ProductPurchase;
use App\Models\User;
use App\Services\Hellom\DokuSettingsService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** P1-3: DOKU billing notifications need DOKU's signature and are checked with DOKU's status API. */
class DokuBillingWebhookTest extends SellerFinanceTestCase
{
    use DatabaseTransactions;

    private const CLIENT_ID = 'client-test';
    private const SECRET = 'secret-test';

    protected function setUp(): void
    {
        parent::setUp();
        app(DokuSettingsService::class)->saveConfig(['client_id' => self::CLIENT_ID, 'secret_key' => self::SECRET, 'callback_token' => 'doku-token', 'is_production' => false]);
    }

    private function purchase(): ProductPurchase
    {
        $user = User::query()->create(['name' => 'Pembeli', 'email' => Str::lower(Str::random(10)) . '@example.test', 'password' => bcrypt('x'), 'role' => 'member']);
        $product = DigitalProduct::query()->create(['slug' => 'kelas-' . Str::lower(Str::random(6)), 'name' => 'Kelas', 'category' => 'course', 'type' => 'paid', 'price' => 150000, 'currency' => 'IDR', 'is_published' => true]);

        return ProductPurchase::query()->create(['user_id' => $user->id, 'product_id' => $product->id, 'transaction_code' => 'PUR-' . Str::upper(Str::random(10)), 'amount_paid' => 150000,
            'payment_method' => 'gateway', 'payment_status' => 'pending', 'payment_gateway' => 'doku']);
    }

    private function fakeDokuStatus(string $invoice, string $status, int $amount): void
    {
        Http::swap(new HttpFactory());
        Http::preventStrayRequests();
        Http::fake(['*/orders/v1/status/*' => Http::response(['order' => ['invoice_number' => $invoice, 'amount' => $amount], 'transaction' => ['status' => $status]])]);
    }

    private function notify(string $invoice, string $bodyStatus, bool $signed = true)
    {
        $body = json_encode(['order' => ['invoice_number' => $invoice, 'amount' => 150000, 'status' => $bodyStatus], 'transaction' => ['status' => $bodyStatus]]);
        $requestId = (string) Str::uuid();
        $timestamp = now()->utc()->format('Y-m-d\TH:i:s\Z');
        $target = '/api/v1/hellom/webhooks/doku';
        $signature = 'HMACSHA256=' . base64_encode(hash_hmac('sha256', implode("\n", [
            'Client-Id:' . self::CLIENT_ID, 'Request-Id:' . $requestId, 'Request-Timestamp:' . $timestamp,
            'Request-Target:' . $target, 'Digest:' . base64_encode(hash('sha256', $body, true)),
        ]), self::SECRET, true));

        return $this->call('POST', $target . '?token=doku-token', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_CLIENT_ID' => self::CLIENT_ID, 'HTTP_REQUEST_ID' => $requestId, 'HTTP_REQUEST_TIMESTAMP' => $timestamp,
            'HTTP_SIGNATURE' => $signed ? $signature : 'HMACSHA256=forged',
        ], $body);
    }

    public function test_unsigned_notification_is_refused(): void
    {
        $purchase = $this->purchase();
        $this->notify($purchase->transaction_code, 'SUCCESS', false)->assertStatus(401);
        $this->assertSame('pending', $purchase->fresh()->payment_status);
    }

    public function test_status_and_amount_come_from_doku_and_paid_is_never_downgraded(): void
    {
        $purchase = $this->purchase();

        // Body says SUCCESS, DOKU says the amount paid was lower.
        $this->fakeDokuStatus($purchase->transaction_code, 'SUCCESS', 1000);
        $this->notify($purchase->transaction_code, 'SUCCESS')->assertOk()->assertJsonPath('data.status', 'amount_mismatch');
        $this->assertSame('pending', $purchase->fresh()->payment_status);

        $this->fakeDokuStatus($purchase->transaction_code, 'SUCCESS', 150000);
        $this->notify($purchase->transaction_code, 'SUCCESS')->assertOk()->assertJsonPath('data.status', 'processed');
        $this->assertSame('paid', $purchase->fresh()->payment_status);

        // A late EXPIRED notification (DOKU now answers EXPIRED) changes nothing.
        $this->fakeDokuStatus($purchase->transaction_code, 'EXPIRED', 150000);
        $this->notify($purchase->transaction_code, 'EXPIRED')->assertOk();
        $this->assertSame('paid', $purchase->fresh()->payment_status);
    }
}
