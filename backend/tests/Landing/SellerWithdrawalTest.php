<?php

namespace Tests\Landing;

use App\Models\ApiToken;
use App\Models\OrganizationPayoutProfile;
use App\Models\SellerBalance;
use App\Models\SellerWithdrawal;
use App\Models\User;
use App\Services\SellerFinance\SellerLedger;
use App\Services\SellerFinance\WithdrawalService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;

/** Fase 2 withdrawals: minimum, isolation between sellers, failure refunds, bank-change hold. */
class SellerWithdrawalTest extends SellerFinanceTestCase
{
    use DatabaseTransactions;

    private function adminToken(): string
    {
        $admin = User::query()->create(['name' => 'SA', 'email' => Str::lower(Str::random(10)) . '@example.test', 'password' => bcrypt(Str::random(16)), 'role' => 'super_admin']);
        $plain = Str::random(40);
        ApiToken::query()->create(['user_id' => $admin->id, 'name' => 't', 'token_hash' => hash('sha256', $plain)]);

        return $plain;
    }

    public function test_withdrawal_below_minimum_is_rejected(): void
    {
        $seller = $this->seller(100000);
        $this->paidSale($seller);

        $this->withHeader('Authorization', "Bearer {$seller['token']}")
            ->postJson('/api/v1/hellom/seller/finance/withdrawals', ['amount' => 49999])
            ->assertStatus(422)->assertJsonPath('error.code', 'WITHDRAWAL_BELOW_MINIMUM');

        $this->withHeader('Authorization', "Bearer {$seller['token']}")
            ->postJson('/api/v1/hellom/seller/finance/withdrawals', ['amount' => 50000])
            ->assertCreated()->assertJsonPath('data.withdrawal.status', 'requested');

        $balance = SellerBalance::query()->find($seller['org']->id);
        $this->assertSame(45000, (int) $balance->available);
        $this->assertSame(50000, (int) $balance->processing);
    }

    public function test_seller_cannot_see_or_touch_another_sellers_money(): void
    {
        $a = $this->seller(100000);
        $b = $this->seller(200000);
        $this->paidSale($b);
        $bWithdrawal = app(WithdrawalService::class)->request($b['org'], $b['user'], 60000);

        // A sees only A's (empty) balance.
        $this->withHeader('Authorization', "Bearer {$a['token']}")
            ->getJson('/api/v1/hellom/seller/finance/summary')->assertOk()->assertJsonPath('data.balance.available', 0);
        // A cannot cancel B's withdrawal.
        $this->withHeader('Authorization', "Bearer {$a['token']}")
            ->postJson("/api/v1/hellom/seller/finance/withdrawals/{$bWithdrawal->id}/cancel")->assertNotFound();
        // A switching to B's organization without membership is refused.
        $a['user']->forceFill(['current_organization_id' => $b['org']->id])->save();
        $this->withHeader('Authorization', "Bearer {$a['token']}")
            ->getJson('/api/v1/hellom/seller/finance/summary')->assertForbidden();
        $this->withHeader('Authorization', "Bearer {$a['token']}")
            ->postJson('/api/v1/hellom/seller/finance/withdrawals', ['amount' => 50000])->assertForbidden();

        $this->assertSame(SellerWithdrawal::STATUS_REQUESTED, $bWithdrawal->fresh()->status);
    }

    public function test_failed_withdrawal_returns_the_money(): void
    {
        $seller = $this->seller(100000);
        $this->paidSale($seller);
        $withdrawal = app(WithdrawalService::class)->request($seller['org'], $seller['user'], 80000);
        $admin = $this->adminToken();

        $this->withHeader('Authorization', "Bearer {$admin}")
            ->postJson("/api/v1/hellom/admin/seller-finance/withdrawals/{$withdrawal->id}/approve")->assertOk()->assertJsonPath('data.withdrawal.status', 'processing');
        $this->withHeader('Authorization', "Bearer {$admin}")
            ->postJson("/api/v1/hellom/admin/seller-finance/withdrawals/{$withdrawal->id}/mark-failed", ['reason' => 'Rekening tidak aktif'])->assertOk();

        $balance = SellerBalance::query()->find($seller['org']->id);
        $this->assertSame(95000, (int) $balance->available);
        $this->assertSame(0, (int) $balance->processing);
        $this->assertTrue(app(SellerLedger::class)->reconcile($seller['org']->id)['ok']);
        // Seller got an email for each change (requested, processing, failed).
        $this->assertGreaterThanOrEqual(3, count($this->mail->sent));
    }

    public function test_paid_withdrawal_moves_money_to_withdrawn_and_admin_only(): void
    {
        $seller = $this->seller(100000);
        $this->paidSale($seller);
        $withdrawal = app(WithdrawalService::class)->request($seller['org'], $seller['user'], 90000);

        // A seller cannot use admin endpoints.
        $this->withHeader('Authorization', "Bearer {$seller['token']}")
            ->postJson("/api/v1/hellom/admin/seller-finance/withdrawals/{$withdrawal->id}/mark-paid")->assertForbidden();

        $this->withHeader('Authorization', "Bearer {$this->adminToken()}")
            ->post("/api/v1/hellom/admin/seller-finance/withdrawals/{$withdrawal->id}/mark-paid", [
                'proof' => \Illuminate\Http\UploadedFile::fake()->image('bukti.jpg'),
            ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.withdrawal.status', 'paid');

        $balance = SellerBalance::query()->find($seller['org']->id);
        $this->assertSame(5000, (int) $balance->available);
        $this->assertSame(0, (int) $balance->processing);
        $this->assertSame(90000, (int) $balance->withdrawn);
        $this->assertTrue(app(SellerLedger::class)->reconcile($seller['org']->id)['ok']);
        \Illuminate\Support\Facades\Storage::disk('local')->delete((string) $withdrawal->fresh()->proof_path);
    }

    public function test_unverified_payout_account_and_recent_bank_change_block_withdrawal(): void
    {
        $seller = $this->seller(100000, verifiedPayout: false);
        $this->paidSale($seller);
        $this->withHeader('Authorization', "Bearer {$seller['token']}")
            ->postJson('/api/v1/hellom/seller/finance/withdrawals', ['amount' => 50000])->assertStatus(422)->assertJsonPath('error.code', 'WITHDRAWAL_BLOCKED');

        OrganizationPayoutProfile::query()->create([
            'organization_id' => $seller['org']->id, 'submitted_by_user_id' => $seller['user']->id, 'full_name' => 'Budi Santoso', 'nik' => '3201010101010001',
            'bank_code' => 'BCA', 'account_number' => '999', 'account_name' => 'ORANG LAIN', 'status' => OrganizationPayoutProfile::STATUS_VERIFIED,
        ]);
        $summary = $this->withHeader('Authorization', "Bearer {$seller['token']}")->getJson('/api/v1/hellom/seller/finance/summary')->json('data.payout_account');
        $this->assertFalse($summary['can_withdraw']);
        $this->assertStringContainsString('Nama pemilik rekening', (string) $summary['blocked_reason']);

        OrganizationPayoutProfile::query()->where('organization_id', $seller['org']->id)->update(['account_name' => 'BUDI SANTOSO', 'bank_changed_at' => now()]);
        $this->withHeader('Authorization', "Bearer {$seller['token']}")
            ->postJson('/api/v1/hellom/seller/finance/withdrawals', ['amount' => 50000])->assertStatus(422)->assertJsonPath('error.code', 'WITHDRAWAL_BLOCKED');
        $this->travel(25)->hours();
        $this->withHeader('Authorization', "Bearer {$seller['token']}")
            ->postJson('/api/v1/hellom/seller/finance/withdrawals', ['amount' => 50000])->assertCreated();
    }

    public function test_top_up_wallet_is_no_longer_withdrawable(): void
    {
        $seller = $this->seller(100000);
        $this->withHeader('Authorization', "Bearer {$seller['token']}")
            ->postJson('/api/v1/hellom/wallet/withdrawals', ['amount' => 100000])
            ->assertStatus(422)->assertJsonPath('error.code', 'WITHDRAWAL_MOVED_TO_SELLER_BALANCE');
    }
}
