<?php

namespace Tests\Landing;

use App\Models\OrganizationWallet;
use App\Models\OrganizationWalletTransaction;

class CleanMockTopupsTest extends SellerFinanceTestCase
{
    private function wallet(int $orgId, int $available, array $mockTopups): OrganizationWallet
    {
        $wallet = OrganizationWallet::query()->create(['organization_id' => $orgId, 'currency' => 'IDR', 'available_balance' => $available, 'pending_balance' => 0, 'total_in' => $available, 'total_out' => 0, 'status' => 'active']);
        foreach ($mockTopups as $i => $amount) {
            OrganizationWalletTransaction::query()->create(['organization_id' => $orgId, 'wallet_id' => $wallet->id, 'type' => 'wallet_topup_mock', 'direction' => 'credit',
                'amount' => $amount, 'balance_after' => 0, 'reference_type' => 'billing_wallet_topup', 'reference_id' => "mock-$orgId-$i", 'description' => 'Wallet top-up (mock)']);
        }

        return $wallet;
    }

    public function test_reverses_mock_money_once_and_never_goes_negative(): void
    {
        $a = $this->seller();
        $b = $this->seller();
        $walletA = $this->wallet($a['org']->id, 300000, [100000, 50000]);   // real money stays
        $walletB = $this->wallet($b['org']->id, 20000, [150000]);           // mock money partly spent

        $this->artisan('wallet:clean-mock-topups')->assertSuccessful();
        $this->assertSame(300000, (int) $walletA->fresh()->available_balance, 'report mode writes nothing');

        foreach ([$a, $b] as $s) {
            $this->artisan('wallet:clean-mock-topups', ['--force' => true, '--organization' => $s['org']->id])->assertSuccessful();
        }
        $this->assertSame(150000, (int) $walletA->fresh()->available_balance);
        $this->assertSame(0, (int) $walletB->fresh()->available_balance);

        // Second run: nothing more for A; B only gets what came in since (none).
        $this->artisan('wallet:clean-mock-topups', ['--force' => true, '--organization' => $a['org']->id])->assertSuccessful();
        $this->assertSame(150000, (int) $walletA->fresh()->available_balance);
        $this->assertSame(1, OrganizationWalletTransaction::query()->where('organization_id', $a['org']->id)->where('type', 'wallet_topup_mock_reversal')->count());
        $this->assertSame(3, OrganizationWalletTransaction::query()->where('organization_id', $a['org']->id)->count(), 'mock rows are kept, not deleted');
    }
}
