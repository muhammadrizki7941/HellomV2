<?php

namespace App\Jobs;

use App\Mail\HellomCheckoutStatusMail;
use App\Services\Hellom\PlatformMailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Queued notification email using the standard Hellom layout (headline, details, closing). */
class SendPlatformMail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    /**
     * @param list<string> $to
     * @param array<string, mixed> $payload headline, intro, details, closing, cta_url, cta_label
     */
    public function __construct(
        public readonly array $to,
        public readonly string $subject,
        public readonly array $payload,
    ) {
    }

    public function handle(PlatformMailService $mailer): void
    {
        $to = array_values(array_unique(array_filter($this->to)));
        // Several owners: one job each, so a retry never re-sends to someone who already got it.
        if (count($to) > 1) {
            foreach ($to as $email) {
                self::dispatch([$email], $this->subject, $this->payload);
            }

            return;
        }
        if ($to === []) {
            return;
        }

        // Throws on failure → the queue retries (tries/backoff), then failed().
        // On the sync queue (local/tests) a throw would break the request that dispatched it.
        if ($this->job === null || $this->job->getConnectionName() === 'sync') {
            $mailer->sendTo($to[0], new HellomCheckoutStatusMail(subjectLine: $this->subject, payload: $this->payload));

            return;
        }
        $mailer->sendOrFail($to[0], new HellomCheckoutStatusMail(subjectLine: $this->subject, payload: $this->payload));
    }

    public function failed(\Throwable $exception): void
    {
        \Illuminate\Support\Facades\Log::warning('Platform email gave up', ['subject' => $this->subject, 'error' => $exception->getMessage()]);
    }
}
