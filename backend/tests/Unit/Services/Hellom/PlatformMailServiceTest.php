<?php

namespace Tests\Unit\Services\Hellom;

use App\Services\Hellom\PlatformMailService;
use Tests\TestCase;

class PlatformMailServiceTest extends TestCase
{
    public function test_normalize_settings_trims_sender_addresses(): void
    {
        $service = new PlatformMailService;

        $settings = $service->normalizeSettings([
            'from_address' => ' noreply@hellomspace.com ',
            'reply_to_address' => ' support@hellomspace.com ',
        ]);

        $this->assertSame('noreply@hellomspace.com', $settings['from_address']);
        $this->assertSame('support@hellomspace.com', $settings['reply_to_address']);
    }
}
