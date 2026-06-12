<?php

namespace Tests\Unit\Services\Hellom;

use App\Services\Hellom\PlatformMailService;
use Tests\TestCase;

class PlatformMailServiceTest extends TestCase
{
    public function test_normalize_settings_maps_gmail_email_host_to_smtp_hostname(): void
    {
        $service = new PlatformMailService();

        $settings = $service->normalizeSettings([
            'host' => 'hellom.official01@gmail.com',
            'username' => 'hellom.official01@gmail.com',
            'port' => 587,
        ]);

        $this->assertSame('smtp.gmail.com', $settings['host']);
        $this->assertSame('hellom.official01@gmail.com', $settings['username']);
        $this->assertSame(587, $settings['port']);
    }

    public function test_normalize_settings_strips_port_from_host_input(): void
    {
        $service = new PlatformMailService();

        $settings = $service->normalizeSettings([
            'host' => 'smtp.gmail.com:587',
            'username' => 'hellom.official01@gmail.com',
            'port' => '587',
        ]);

        $this->assertSame('smtp.gmail.com', $settings['host']);
        $this->assertSame(587, $settings['port']);
    }

    public function test_invalid_smtp_host_is_rejected(): void
    {
        $service = new PlatformMailService();

        $this->assertFalse($service->isValidSmtpHost('owner@example.com'));
        $this->assertTrue($service->isValidSmtpHost('smtp.gmail.com'));
    }
}
