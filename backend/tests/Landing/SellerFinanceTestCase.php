<?php

namespace Tests\Landing;

use App\Models\ApiToken;
use App\Models\LandingBlock;
use App\Models\LandingPageOrder;
use App\Models\Organization;
use App\Models\OrganizationLandingPage;
use App\Models\OrganizationPayoutProfile;
use App\Models\User;
use App\Services\Hellom\IpaymuSettingsService;
use App\Services\Hellom\LandingSaleService;
use App\Services\Hellom\PaymentGatewaySettingsService;
use App\Services\Hellom\PlatformMailService;
use App\Services\SellerFinance\FinanceSettings;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Pos\PosTestCase;
use Tests\Support\NoopPlatformMailService;

/** Fixtures for Fase 2 money tests: a seller with a published paid product, fake iPaymu. */
abstract class SellerFinanceTestCase extends PosTestCase
{
    protected const IPAYMU_TOKEN = 'test-ipaymu-callback-token';

    protected NoopPlatformMailService $mail;

    protected function setUp(): void
    {
        parent::setUp();
        // Fresh HTTP fake without the base catch-all: only stubs a test registers answer,
        // anything else (a real gateway call) fails the test.
        Http::swap(new HttpFactory());
        Http::preventStrayRequests();
        $this->mail = new NoopPlatformMailService();
        $this->app->instance(PlatformMailService::class, $this->mail);

        app(IpaymuSettingsService::class)->saveConfig([
            'va' => '0000001234567890', 'api_key' => 'test-api-key', 'callback_token' => self::IPAYMU_TOKEN,
            'is_production' => false, 'payment_methods' => ['va', 'qris'],
        ]);
        app(PaymentGatewaySettingsService::class)->saveRuntimeConfig(['active_provider' => 'ipaymu', 'sale_commission_percent' => 5]);
        app(FinanceSettings::class)->update(['hold_days' => 0, 'new_seller_hold_days' => 0, 'min_withdrawal' => 50000, 'withdrawal_fee_flat' => 0, 'min_margin_flat' => 0]);
    }

    /** @return array{org: Organization, user: User, token: string, page: OrganizationLandingPage, block: LandingBlock} */
    protected function seller(int $price = 100000, bool $verifiedPayout = true): array
    {
        $org = Organization::query()->create(['name' => 'Toko ' . Str::random(4), 'slug' => 'toko-' . Str::lower(Str::random(8)), 'status' => 'active']);
        $user = User::query()->create(['name' => 'Budi Santoso', 'email' => Str::lower(Str::random(10)) . '@example.test', 'password' => bcrypt(Str::random(16)), 'role' => 'member', 'current_organization_id' => $org->id]);
        $org->users()->attach($user->id, ['role' => 'owner']);
        $plain = Str::random(40);
        ApiToken::query()->create(['user_id' => $user->id, 'name' => 't', 'token_hash' => hash('sha256', $plain)]);
        $page = OrganizationLandingPage::query()->create(['organization_id' => $org->id, 'title' => 'Toko', 'slug' => 'toko', 'status' => 'published', 'published_at' => now()]);
        $block = LandingBlock::query()->create(['organization_id' => $org->id, 'landing_page_id' => $page->id, 'block_key' => 'p1', 'block_type' => 'product', 'sort_order' => 1, 'is_visible' => true,
            'content' => ['name' => 'E-book Jualan', 'price' => 'Rp ' . number_format($price, 0, ',', '.'), 'fileUrl' => 'https://drive.google.com/file/d/rahasia/view']]);

        if ($verifiedPayout) {
            OrganizationPayoutProfile::query()->create([
                'organization_id' => $org->id, 'submitted_by_user_id' => $user->id, 'full_name' => 'Budi Santoso', 'nik' => '3201010101010001',
                'bank_code' => 'BCA', 'bank_name' => 'BCA', 'account_number' => '1234567890', 'account_name' => 'BUDI SANTOSO',
                'status' => OrganizationPayoutProfile::STATUS_VERIFIED, 'submitted_at' => now(), 'reviewed_at' => now(),
            ]);
        }

        return ['org' => $org, 'user' => $user, 'token' => $plain, 'page' => $page, 'block' => $block];
    }

    protected function pendingOrder(array $seller): LandingPageOrder
    {
        $order = app(LandingSaleService::class)->createPendingOrder($seller['page'], $seller['block'], ['name' => 'Pembeli', 'email' => 'pembeli@example.test']);
        $order->forceFill(['provider' => 'ipaymu', 'gateway_ref' => 'sess-' . $order->id])->save();

        return $order;
    }

    /** iPaymu transaction API answers with this payment. */
    protected function fakeIpaymuTransaction(string $trxId, string $reference, int $amount, int $status = 1, int $fee = 4000): void
    {
        Http::swap(new HttpFactory());
        Http::preventStrayRequests();
        Http::fake([
            '*/api/v2/transaction' => Http::response(['Status' => 200, 'Success' => true, 'Data' => [
                'TransactionId' => $trxId, 'ReferenceId' => $reference, 'Amount' => $amount, 'Fee' => $fee,
                'Status' => $status, 'StatusDesc' => $status === 1 ? 'Berhasil' : 'Pending', 'PaymentMethod' => 'va', 'PaymentChannel' => 'bca',
            ]]),
        ]);
    }

    protected function ipaymuWebhook(LandingPageOrder $order, string $trxId, string $token = self::IPAYMU_TOKEN, array $body = [])
    {
        $query = http_build_query(['purpose' => 'landing_sale', 'organization_id' => $order->organization_id, 'reference_id' => $order->reference_id, 'token' => $token]);

        return $this->post('/api/v1/hellom/webhooks/ipaymu?' . $query, $body + [
            'trx_id' => $trxId, 'status' => 'berhasil', 'status_code' => 1, 'reference_id' => $order->reference_id, 'amount' => $order->amount,
        ], ['Accept' => 'application/json']);
    }

    /** Book a paid sale for the seller (full webhook path). */
    protected function paidSale(array $seller, string $trxId = null): LandingPageOrder
    {
        $order = $this->pendingOrder($seller);
        $trxId ??= 'trx-' . $order->id;
        $this->fakeIpaymuTransaction($trxId, (string) $order->reference_id, (int) $order->amount);
        $this->ipaymuWebhook($order, $trxId)->assertOk();

        return $order->fresh();
    }
}
