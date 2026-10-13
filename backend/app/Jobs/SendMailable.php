<?php

namespace App\Jobs;

use App\Services\Hellom\PlatformMailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/** Any Mailable through PlatformMailService in the background (PlatformMailService::queue). Retries 3×. */
class SendMailable implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    /** @param list<string> $to */
    public function __construct(
        public readonly array $to,
        public readonly Mailable $mailable,
    ) {
    }

    public function handle(PlatformMailService $mailer): void
    {
        // On the sync queue (local/tests) a throw would break the request that dispatched it.
        if ($this->job === null || $this->job->getConnectionName() === 'sync') {
            $mailer->sendTo($this->to, $this->mailable);

            return;
        }
        $mailer->sendOrFail($this->to, $this->mailable);
    }

    public function failed(\Throwable $exception): void
    {
        Log::warning('Email gave up after retries', ['to' => $this->to, 'mailable' => class_basename($this->mailable), 'error' => $exception->getMessage()]);
    }
}
