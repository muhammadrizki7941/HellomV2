<?php

namespace Tests\Support;

use App\Services\Hellom\PlatformMailService;
use Illuminate\Contracts\Mail\Mailable;

/** Records mails instead of sending them (project rule: never send real email in tests). */
class NoopPlatformMailService extends PlatformMailService
{
    /** @var list<array{to: string|array, mailable: Mailable}> */
    public array $sent = [];

    public function sendTo(string|array $to, Mailable $mailable): array
    {
        $this->sent[] = ['to' => $to, 'mailable' => $mailable];

        return ['sent' => true, 'mailer' => 'noop', 'error' => null];
    }
}
