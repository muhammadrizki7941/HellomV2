<?php

namespace Tests\Landing;

use App\Jobs\SendWebPush;
use App\Models\ApiToken;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\Notifications\Notifier;
use App\Services\Notifications\PushSender;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * Web push: off without VAPID keys; a browser saves/removes its subscription (endpoint moves to whoever
 * is logged in there, removed on logout); notifications queue SendWebPush; dead subscriptions are deleted.
 */
class WebPushTest extends SellerFinanceTestCase
{
    use DatabaseTransactions;

    private function user(string $role = 'admin'): array
    {
        $user = User::query()->create(['name' => 'Push', 'email' => Str::lower(Str::random(10)) . '@example.test', 'password' => bcrypt(Str::random(16)), 'role' => $role]);
        $plain = Str::random(40);
        ApiToken::query()->create(['user_id' => $user->id, 'name' => 't', 'token_hash' => hash('sha256', $plain)]);

        return [$user, $plain];
    }

    private function keys(): void
    {
        config(['services.webpush.public_key' => 'BPublicKeyForTests', 'services.webpush.private_key' => 'private-for-tests']);
    }

    private function subscription(string $endpoint): array
    {
        return ['endpoint' => $endpoint, 'keys' => ['p256dh' => 'BKey' . Str::random(20), 'auth' => Str::random(16)]];
    }

    public function test_push_is_off_until_vapid_keys_are_set(): void
    {
        config(['services.webpush.public_key' => null, 'services.webpush.private_key' => null]);
        [, $token] = $this->user();
        $api = $this->withHeader('Authorization', "Bearer {$token}");

        $api->getJson('/api/v1/hellom/push/public-key')->assertOk()->assertJsonPath('data.enabled', false)->assertJsonPath('data.public_key', null);
        $api->postJson('/api/v1/hellom/push/subscriptions', $this->subscription('https://fcm.googleapis.com/fcm/send/x'))->assertStatus(503);

        Queue::fake();
        app(Notifier::class)->admin('platform_notice', 'Tes', 'Tanpa push', ['email' => false]);
        Queue::assertNotPushed(SendWebPush::class);
    }

    public function test_browser_saves_moves_and_removes_its_subscription(): void
    {
        $this->keys();
        [$alice, $aliceToken] = $this->user();
        [$bob, $bobToken] = $this->user();
        $endpoint = 'https://fcm.googleapis.com/fcm/send/' . Str::random(30);

        $this->withHeader('Authorization', "Bearer {$aliceToken}")->getJson('/api/v1/hellom/push/public-key')
            ->assertOk()->assertJsonPath('data.enabled', true)->assertJsonPath('data.public_key', 'BPublicKeyForTests');
        $this->withHeader('Authorization', "Bearer {$aliceToken}")->postJson('/api/v1/hellom/push/subscriptions', ['endpoint' => 'http://insecure.test/x', 'keys' => ['p256dh' => 'a', 'auth' => 'b']])
            ->assertStatus(422);
        $this->withHeader('Authorization', "Bearer {$aliceToken}")->postJson('/api/v1/hellom/push/subscriptions', $this->subscription($endpoint))->assertCreated();
        $this->assertSame((int) $alice->id, (int) PushSubscription::query()->where('endpoint_hash', PushSubscription::hashEndpoint($endpoint))->value('user_id'));

        // Same browser, Bob logs in and enables push → the endpoint is his now (no duplicate row).
        $this->withHeader('Authorization', "Bearer {$bobToken}")->postJson('/api/v1/hellom/push/subscriptions', $this->subscription($endpoint))->assertCreated();
        $rows = PushSubscription::query()->where('endpoint_hash', PushSubscription::hashEndpoint($endpoint))->get();
        $this->assertCount(1, $rows);
        $this->assertSame((int) $bob->id, (int) $rows->first()->user_id);

        // Logout from that browser removes it.
        $this->withHeader('Authorization', "Bearer {$bobToken}")->postJson('/api/v1/hellom/auth/logout', ['push_endpoint' => $endpoint])->assertOk();
        $this->assertFalse(PushSubscription::query()->where('endpoint_hash', PushSubscription::hashEndpoint($endpoint))->exists());

        // Explicit "Matikan".
        $this->withHeader('Authorization', "Bearer {$aliceToken}")->postJson('/api/v1/hellom/push/subscriptions', $this->subscription($endpoint))->assertCreated();
        $this->withHeader('Authorization', "Bearer {$aliceToken}")->deleteJson('/api/v1/hellom/push/subscriptions', ['endpoint' => $endpoint])->assertOk();
        $this->assertSame(0, PushSubscription::query()->where('user_id', $alice->id)->count());
    }

    public function test_notifications_queue_push_for_the_shop_owner_and_super_admins(): void
    {
        $this->keys();
        Queue::fake();
        $seller = $this->seller(100000);
        $admin = User::query()->create(['name' => 'Super', 'email' => Str::lower(Str::random(10)) . '@example.test', 'password' => bcrypt(Str::random(16)), 'role' => 'super_admin']);

        app(Notifier::class)->organization((int) $seller['org']->id, 'sale', 'Penjualan baru · Rp 100.000', 'Pesanan dibayar.', '/dashboard/apps/landing-builder?tab=pesanan');
        Queue::assertPushed(SendWebPush::class, fn (SendWebPush $job) => $job->userIds === [(int) $seller['user']->id]
            && $job->payload['title'] === 'Penjualan baru · Rp 100.000' && $job->payload['url'] === '/dashboard/apps/landing-builder?tab=pesanan');

        app(Notifier::class)->admin('landing_sale', 'Dana masuk Hellom Page · Rp 100.000', 'Toko menjual.', ['action_url' => '/admin/keuangan-penjual', 'email' => false]);
        Queue::assertPushed(SendWebPush::class, fn (SendWebPush $job) => in_array((int) $admin->id, $job->userIds, true)
            && $job->payload['url'] === '/admin/keuangan-penjual' && str_starts_with($job->payload['tag'], 'admin-'));
    }

    public function test_job_removes_expired_subscriptions_and_marks_delivered_ones(): void
    {
        [$user] = $this->user();
        $liveUrl = "https://push.test/live-" . Str::random(12);
        $deadUrl = "https://push.test/dead-" . Str::random(12);
        $live = PushSubscription::query()->create(['user_id' => $user->id, 'endpoint' => $liveUrl, 'endpoint_hash' => PushSubscription::hashEndpoint($liveUrl), 'p256dh' => 'a', 'auth' => 'b']);
        $dead = PushSubscription::query()->create(['user_id' => $user->id, 'endpoint' => $deadUrl, 'endpoint_hash' => PushSubscription::hashEndpoint($deadUrl), 'p256dh' => 'a', 'auth' => 'b']);
        $sender = new class($liveUrl, $deadUrl) extends PushSender {
            public function __construct(private string $liveUrl, private string $deadUrl)
            {
            }

            public array $sent = [];

            public function send(iterable $subscriptions, array $payload): array
            {
                foreach ($subscriptions as $s) {
                    $this->sent[] = [$s->endpoint, $payload['title']];
                }

                return [$this->liveUrl => 'ok', $this->deadUrl => 'expired'];
            }
        };
        $this->app->instance(PushSender::class, $sender);

        (new SendWebPush([(int) $user->id], ['title' => 'Halo', 'body' => 'Isi', 'url' => '/dashboard', 'tag' => 't']))->handle($sender);

        $this->assertCount(2, $sender->sent);
        $this->assertNotNull($live->fresh()->last_used_at);
        $this->assertNull($dead->fresh());
    }
}
