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

    public int $backoff = 60;

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
        foreach (array_unique(array_filter($this->to)) as $email) {
            $mailer->sendTo($email, new HellomCheckoutStatusMail(subjectLine: $this->subject, payload: $this->payload));
        }
    }
}
