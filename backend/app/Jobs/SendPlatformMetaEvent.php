<?php

namespace App\Jobs;

use Illuminate\Foundation\Bus\Dispatchable;

class SendPlatformMetaEvent
{
    use Dispatchable;
    public function __construct(public int $eventId) {}
    public function handle(\App\Services\PlatformMarketing $marketing): void { $marketing->deliver($this->eventId); }
}
