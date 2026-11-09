<?php

namespace Tests\Unit;

use App\Rules\DemoVideoUrl;
use PHPUnit\Framework\TestCase;

class DemoVideoUrlTest extends TestCase
{
    public function test_supported_video_sources_are_accepted(): void
    {
        foreach (['/media/showcase/abc123.mp4', '/media/showcase/demo-video.webm'] as $url) {
            (new DemoVideoUrl())->validate('url', $url, fn () => $this->fail('Rejected '.$url));
            $this->addToAssertionCount(1);
        }
    }

    public function test_unsafe_or_unplayable_urls_are_rejected(): void
    {
        foreach (['/media/showcase/../secret.mp4', '/media/showcase/demo.svg', '//evil.test/video.mp4', '/media/showcase/demo.mp4?url=evil', 'https://youtu.be/dQw4w9WgXcQ', 'https://vimeo.com/123456', 'https://cdn.example.com/demo.mp4', 'javascript:alert(1)', 'http://example.com/video.mp4', 'https://example.com/page', 'https://youtube.com/watch?v=bad', 'https://youtube.com/watch?v[]=123', 'https://youtube.com.evil.test/embed/dQw4w9WgXcQ'] as $url) {
            $rejected = false;
            (new DemoVideoUrl())->validate('url', $url, function () use (&$rejected) { $rejected = true; });
            $this->assertTrue($rejected, $url);
        }
    }
}
