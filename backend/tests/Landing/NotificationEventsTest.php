<?php

namespace Tests\Landing;

use App\Models\ConsumerNotification;
use App\Models\OrganizationPayoutProfile;
use App\Models\OwnerNotification;
use App\Models\SellerWithdrawal;
use App\Models\User;
use App\Services\Landing\RefundService;
use App\Services\SellerFinance\FinanceSettings;
use App\Services\SellerFinance\SellerLedger;
use App\Services\SellerFinance\WithdrawalService;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * In-app notifications for money events: the shop owners' bell (dana masuk, saldo cair, penarikan,
 * refund, KYC) and the super admin bell (dana masuk, penarikan, refund, KYC, SLA) — and super admin
 * items close by themselves once the work is done. Realtime goes to user_{id} / admins.
 */
class NotificationEventsTest extends SellerFinanceTestCase
{
    private function admin(): User
    {
        return User::query()->create(['name' => 'Super', 'email' => Str::lower(Str::random(10)) . '@example.test', 'password' => bcrypt(Str::random(16)), 'role' => 'super_admin']);
    }

    private function sellerBell(int $userId, string $type)
    {
        return ConsumerNotification::query()->where('user_id', $userId)->where('type', $type)->orderBy('id');
    }

    public function test_sale_rings_the_seller_and_the_super_admin(): void
    {
        $seller = $this->seller(100000);
        $order = $this->paidSale($seller);

        $mine = $this->sellerBell((int) $seller['user']->id, 'sale')->sole();
        $this->assertStringStartsWith('Penjualan baru · Rp', $mine->title);
        $this->assertStringContainsString('E-book Jualan', $mine->body);
        $this->assertSame('/dashboard/apps/landing-builder?tab=pesanan', $mine->action_url);
        $this->assertSame((string) $order->reference_id, $mine->data['order_reference']);

        $admin = OwnerNotification::query()->where('type', 'landing_sale')->where('reference_id', $order->id)->sole();
        $this->assertStringStartsWith('Dana masuk Hellom Page', $admin->title);
        $this->assertStringContainsString($seller['org']->name, $admin->message);

        // The seller sees it in the existing dashboard bell API.
        $this->withHeader('Authorization', "Bearer {$seller['token']}")->getJson('/api/v1/hellom/consumer/notifications')
            ->assertOk()->assertJsonPath('data.unread_count', 1)->assertJsonPath('data.notifications.0.type', 'sale');
    }

    public function test_withdrawal_status_reaches_the_seller_and_closes_the_admin_item(): void
    {
        $seller = $this->seller(100000);
        $this->paidSale($seller);
        $admin = $this->admin();
        $service = app(WithdrawalService::class);

        $withdrawal = $service->request($seller['org'], $seller['user'], 50000);
        $item = OwnerNotification::query()->where('reference_type', 'seller_withdrawal')->where('reference_id', $withdrawal->id)->sole();
        $this->assertSame(['withdrawal', 'pending'], [$item->type, $item->action_status]);
        $this->assertSame('Penarikan dana diajukan · Rp 50.000', $this->sellerBell((int) $seller['user']->id, 'withdrawal')->first()->title);

        $service->approve($withdrawal, $admin);
        $this->assertSame('done', $item->fresh()->action_status); // no longer "pending" in the super admin bell
        $service->markPaid($withdrawal->fresh(), $admin);

        $titles = $this->sellerBell((int) $seller['user']->id, 'withdrawal')->pluck('title')->all();
        $this->assertSame(['Penarikan dana diajukan · Rp 50.000', 'Penarikan dana sedang diproses · Rp 50.000', 'Penarikan dana berhasil · Rp 50.000'], $titles);
        $this->assertSame(SellerWithdrawal::STATUS_PAID, $withdrawal->fresh()->status);

        // A cancelled request is closed as "ignored".
        $this->paidSale($seller, 'trx-' . Str::random(12));
        $second =$service->request($seller['org'], $seller['user'], 50000);
        $service->cancel($second, $seller['user']);
        $this->assertSame('ignored', OwnerNotification::query()->where('reference_type', 'seller_withdrawal')->where('reference_id', $second->id)->value('action_status'));
    }

    public function test_balance_release_is_one_notification_per_shop_per_run(): void
    {
        app(FinanceSettings::class)->update(['hold_days' => 3, 'new_seller_hold_days' => 3]);
        $seller = $this->seller(100000);
        $this->paidSale($seller, 'trx-' . Str::random(12));
        $this->paidSale($seller, 'trx-' . Str::random(12));
        $this->assertSame(0, $this->sellerBell((int) $seller['user']->id, 'balance_released')->count());

        $this->travel(4)->days();
        $this->assertSame(2, app(SellerLedger::class)->releaseDue());
        $released = $this->sellerBell((int) $seller['user']->id, 'balance_released')->sole();
        $this->assertStringEndsWith('sudah bisa ditarik', $released->title);
        $this->assertSame(2, $released->data['orders']);
    }

    public function test_refund_and_kyc_flow_through_both_bells(): void
    {
        $seller = $this->seller(100000);
        $order = $this->paidSale($seller);
        $admin = $this->admin();

        $refund = app(RefundService::class)->request($order, $seller['user'], ['amount' => 20000, 'reason' => 'Salah kirim', 'destination_type' => 'bank',
            'bank_code' => 'bca', 'account_number' => '999', 'account_name' => 'Pembeli']);
        $item = OwnerNotification::query()->where('reference_type', 'landing_refund')->where('reference_id', $refund->id)->sole();
        $this->assertSame(['refund', 'pending'], [$item->type, $item->action_status]);
        app(RefundService::class)->markPaid($refund, $admin);
        $this->assertSame('done', $item->fresh()->action_status);
        $this->assertSame('Refund Rp 20.000 sudah ditransfer', $this->sellerBell((int) $seller['user']->id, 'refund')->sole()->title);

        // KYC: submitted → super admin; rejected → seller.
        $fresh = $this->seller(100000, verifiedPayout: false);
        $this->withHeader('Authorization', "Bearer {$fresh['token']}")->postJson('/api/v1/hellom/payout-profile', [
            'full_name' => 'Budi Santoso', 'nik' => '3201010101010001', 'bank_code' => 'BCA', 'account_number' => '123', 'account_name' => 'BUDI SANTOSO',
        ])->assertCreated();
        $profile = OrganizationPayoutProfile::query()->where('organization_id', $fresh['org']->id)->sole();
        $kyc = OwnerNotification::query()->where('reference_type', 'payout_profile')->where('reference_id', $profile->id)->sole();
        $this->assertSame(['kyc', 'pending'], [$kyc->type, $kyc->action_status]);

        $plain = Str::random(40);
        \App\Models\ApiToken::query()->create(['user_id' => $admin->id, 'name' => 't', 'token_hash' => hash('sha256', $plain)]);
        $this->withHeader('Authorization', "Bearer {$plain}")->postJson("/api/v1/hellom/admin/payout-profiles/{$profile->id}/reject", ['notes' => 'Foto KTP buram'])->assertOk();
        $this->assertSame('done', $kyc->fresh()->action_status);
        $this->assertStringContainsString('Foto KTP buram', $this->sellerBell((int) $fresh['user']->id, 'kyc')->sole()->body);
    }

    public function test_admin_list_filters_by_group_status_and_search_and_counts_open_withdrawals(): void
    {
        $seller = $this->seller(100000);
        $this->paidSale($seller);
        $withdrawal = app(WithdrawalService::class)->request($seller['org'], $seller['user'], 50000);
        $admin = $this->admin();
        $plain = Str::random(40);
        \App\Models\ApiToken::query()->create(['user_id' => $admin->id, 'name' => 't', 'token_hash' => hash('sha256', $plain)]);
        $api = fn (string $uri) => $this->withHeader('Authorization', "Bearer {$plain}")->getJson('/api/v1/hellom/admin/notifications' . $uri)->assertOk();

        $ids = fn ($response) => collect($response->json('data.data'))->pluck('id')->all();
        $withdrawalItem = OwnerNotification::query()->where('reference_type', 'seller_withdrawal')->where('reference_id', $withdrawal->id)->value('id');

        $group = $api('?per_page=100&type=withdrawal,refund,sla_warning');
        $this->assertContains($withdrawalItem, $ids($group));
        $this->assertSame([], array_values(array_diff(collect($group->json('data.data'))->pluck('type')->unique()->all(), ['withdrawal', 'refund', 'sla_warning'])));

        $this->assertContains($withdrawalItem, $ids($api('?per_page=100&status=pending')));
        $this->assertContains($withdrawalItem, $ids($api('?per_page=100&q=' . urlencode($seller['org']->name))));
        $this->assertNotContains($withdrawalItem, $ids($api('?per_page=100&q=tidak-ada-yang-cocok-%25')));

        $counts = $api('/unread-count');
        $this->assertGreaterThanOrEqual(1, $counts->json('data.open_withdrawals'));
        $this->assertGreaterThanOrEqual(1, $counts->json('data.pending'));
    }

    public function test_new_notifications_are_pushed_to_the_users_room_and_admins(): void
    {
        $seller = $this->seller(100000);
        $order = $this->pendingOrder($seller);
        $trx = 'trx-' . Str::random(12);
        $this->fakeIpaymuTransaction($trx, (string) $order->reference_id, (int) $order->amount);
        config(['realtime.enabled' => true, 'realtime.server_url' => 'http://realtime.test', 'realtime.secret' => 'rt-secret']);
        Http::fake(['*realtime.test/*' => Http::response(['ok' => true])]);

        $this->ipaymuWebhook($order, $trx)->assertOk();

        Http::assertSent(fn (HttpRequest $r) => str_starts_with($r->url(), 'http://realtime.test/emit') && $r['room'] === 'user_' . $seller['user']->id
            && $r['event'] === 'user.notification.created' && $r['data']['type'] === 'sale');
        Http::assertSent(fn (HttpRequest $r) => str_starts_with($r->url(), 'http://realtime.test/emit') && $r['room'] === 'admins'
            && $r['event'] === 'admin.notification.created' && $r['data']['type'] === 'landing_sale');
    }
}
