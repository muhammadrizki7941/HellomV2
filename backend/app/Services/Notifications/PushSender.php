<?php

namespace App\Services\Notifications;

use App\Models\PushSubscription;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush as WebPushClient;

/**
 * Thin wrapper around minishlink/web-push (swapped for a fake in tests).
 * Returns per endpoint: "ok", "expired" (404/410 → delete the subscription) or "failed".
 */
class PushSender
{
    /**
     * @param iterable<PushSubscription> $subscriptions
     * @return array<string, 'ok'|'expired'|'failed'> keyed by endpoint
     */
    public function send(iterable $subscriptions, array $payload): array
    {
        $client = new WebPushClient([
            'VAPID' => [
                'subject' => (string) (config('services.webpush.subject') ?: config('app.url')),
                'publicKey' => (string) config('services.webpush.public_key'),
                'privateKey' => (string) config('services.webpush.private_key'),
            ],
        ], ['TTL' => 86400, 'urgency' => 'high', 'topic' => substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($payload['tag'] ?? '')), 0, 32) ?: null]);

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        foreach ($subscriptions as $subscription) {
            $client->queueNotification(Subscription::create([
                'endpoint' => $subscription->endpoint,
                'publicKey' => $subscription->p256dh,
                'authToken' => $subscription->auth,
                'contentEncoding' => $subscription->content_encoding ?: 'aes128gcm',
            ]), $body);
        }

        $results = [];
        foreach ($client->flush() as $report) {
            $results[$report->getEndpoint()] = $report->isSuccess() ? 'ok' : ($report->isSubscriptionExpired() ? 'expired' : 'failed');
        }

        return $results;
    }
}
