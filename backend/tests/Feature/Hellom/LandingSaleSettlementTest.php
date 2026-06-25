<?php

namespace Tests\Feature\Hellom;

use App\Models\LandingPageOrder;
use App\Models\Organization;
use App\Models\OrganizationPayoutProfile;
use App\Models\OrganizationWallet;
use App\Models\OrganizationWalletTransaction;
use App\Models\PlatformFinanceLedger;
use App\Models\User;
use App\Services\Hellom\LandingSaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LandingSaleSettlementTest extends TestCase
{
    use RefreshDatabase;
    use HellomTestHelpers;

    private function makeOrg(): Organization
    {
        return Organization::query()->create([
            'name' => 'Seller Org',
            'slug' => 'seller-org',
            'status' => 'active',
        ]);
    }

    private function makePaidPendingOrder(Organization $org, int $amount, int $commission): LandingPageOrder
    {
        return LandingPageOrder::query()->create([
            'organization_id' => $org->id,
            'landing_page_id' => null,
            'block_id' => '1',
            'product_kind' => 'product',
            'product_name' => 'Ebook Premium',
            'amount' => $amount,
            'commission_amount' => $commission,
            'net_amount' => $amount - $commission,
            'buyer_name' => 'Budi',
            'buyer_email' => 'budi@example.com',
            'status' => LandingPageOrder::STATUS_PENDING,
            'reference_id' => 'lps_TEST123',
        ]);
    }

    public function test_settlement_credits_seller_pending_and_records_commission_idempotently(): void
    {
        $org = $this->makeOrg();
        // amount 200k, 5% commission = 10k, net = 190k
        $this->makePaidPendingOrder($org, 200000, 10000);

        $service = app(LandingSaleService::class);

        $service->settlePaidOrderByReference('lps_TEST123', ['provider' => 'ipaymu', 'gateway_ref' => 'TRX1']);
        // call again — must be idempotent
        $service->settlePaidOrderByReference('lps_TEST123', ['provider' => 'ipaymu', 'gateway_ref' => 'TRX1']);

        $order = LandingPageOrder::query()->where('reference_id', 'lps_TEST123')->first();
        $this->assertSame('paid', $order->status);
        $this->assertNotNull($order->download_token);
        $this->assertNotNull($order->settlement_eta);

        $wallet = OrganizationWallet::query()->where('organization_id', $org->id)->first();
        $this->assertSame(190000, (int) $wallet->pending_balance);
        $this->assertSame(0, (int) $wallet->available_balance);
        $this->assertSame(190000, (int) $wallet->total_in);

        // Only one pending credit transaction despite two settle calls
        $this->assertSame(1, OrganizationWalletTransaction::query()
            ->where('organization_id', $org->id)
            ->where('type', 'payment_credit_pending')
            ->count());

        // Commission recorded once as platform revenue
        $this->assertSame(1, PlatformFinanceLedger::query()
            ->where('category', 'landing_commission')
            ->where('reference_id', $order->id)
            ->count());
    }

    public function test_pending_balance_releases_to_available_after_eta(): void
    {
        $org = $this->makeOrg();
        $this->makePaidPendingOrder($org, 100000, 5000);
        app(LandingSaleService::class)->settlePaidOrderByReference('lps_TEST123', ['provider' => 'ipaymu']);

        // Force the settlement ETA into the past so the release command picks it up.
        OrganizationWalletTransaction::query()
            ->where('type', 'payment_credit_pending')
            ->update(['metadata' => ['settlement_eta' => now()->subDay()->toISOString()]]);

        $this->artisan('hellom:wallet:release-pending-settlements')->assertExitCode(0);

        $wallet = OrganizationWallet::query()->where('organization_id', $org->id)->first();
        $this->assertSame(0, (int) $wallet->pending_balance);
        $this->assertSame(95000, (int) $wallet->available_balance);
    }

    public function test_withdrawal_blocked_without_verified_kyc_and_below_min(): void
    {
        $org = $this->makeOrg();
        [$user, $token] = $this->createHellomUser();
        $org->users()->attach($user->id, ['role' => 'owner']);
        $user->forceFill(['current_organization_id' => $org->id])->save();

        OrganizationWallet::query()->create([
            'organization_id' => $org->id,
            'currency' => 'IDR',
            'available_balance' => 500000,
            'pending_balance' => 0,
            'total_in' => 500000,
            'total_out' => 0,
            'status' => 'active',
        ]);

        // No KYC profile yet → blocked
        $this->withToken($token)
            ->postJson('/api/v1/hellom/wallet/withdrawals', ['amount' => 100000])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'KYC_NOT_VERIFIED');

        // Verified KYC, but amount below minimum (100k) → validation error
        OrganizationPayoutProfile::query()->create([
            'organization_id' => $org->id,
            'full_name' => 'Budi',
            'nik' => '1234567890123456',
            'bank_code' => 'BCA',
            'account_number' => '1234567890',
            'account_name' => 'Budi',
            'status' => OrganizationPayoutProfile::STATUS_VERIFIED,
        ]);

        $this->withToken($token)
            ->postJson('/api/v1/hellom/wallet/withdrawals', ['amount' => 50000])
            ->assertStatus(422);

        // Verified + at minimum → success
        $this->withToken($token)
            ->postJson('/api/v1/hellom/wallet/withdrawals', ['amount' => 100000])
            ->assertStatus(201);
    }
}
