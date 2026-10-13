<?php

namespace App\Jobs;

use App\Models\PushSubscription;
use App\Services\Notifications\PushSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Web push to every device of these users; subscriptions the push service no longer knows are removed. */
class SendWebPush implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $backoff = 30;

    /**
     * @param list<int> $userIds
     * @param array{title: string, body: string, url: string, tag: string} $payload
     */
    public function __construct(
        public readonly array $userIds,
        public readonly array $payload,
    ) {
    }

    public function handle(PushSender $sender): void
    {
        $subscriptions = PushSubscription::query()->whereIn('user_id', $this->userIds)->get();
        if ($subscriptions->isEmpty()) {
            return;
        }

        $results = $sender->send($subscriptions, $this->payload);

        $byEndpoint = $subscriptions->keyBy('endpoint');
        $expired = [];
        $delivered = [];
        foreach ($results as $endpoint => $status) {
            $subscription = $byEndpoint->get($endpoint);
            if (!$subscription) {
                continue;
            }
            if ($status === 'expired') {
                $expired[] = $subscription->id;
            } elseif ($status === 'ok') {
                $delivered[] = $subscription->id;
            }
        }

        if ($expired !== []) {
            PushSubscription::query()->whereIn('id', $expired)->delete();
        }
        if ($delivered !== []) {
            PushSubscription::query()->whereIn('id', $delivered)->update(['last_used_at' => now()]);
        }
    }
}
