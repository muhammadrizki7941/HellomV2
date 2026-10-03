<?php

namespace App\Support\Landing;

/**
 * Turns a link the seller pastes into an official embed (iframe, no third-party script):
 * Spotify, TikTok, Instagram post/reel, YouTube. Only these hosts are allowed by the shop
 * page CSP (PageSecurity frame-src); anything else is not embedded.
 */
final class Embed
{
    /** Hosts the embeds load from (keep in sync with PageSecurity::policy frame-src). */
    public const FRAME_HOSTS = ['https://open.spotify.com', 'https://www.tiktok.com', 'https://www.instagram.com'];

    /**
     * @return array{provider:string, src:?string, id:string, height:?int, vertical:bool, label:string}|null
     */
    public static function resolve(?string $url): ?array
    {
        $url = trim((string) $url);
        if ($url === '' || !preg_match('~^https?://~i', $url)) {
            return null;
        }
        if (preg_match('~^https?://open\.spotify\.com/(?:intl-[a-z]{2}(?:-[a-z]{2})?/)?(track|album|playlist|episode|show|artist)/([A-Za-z0-9]{10,40})~i', $url, $m)) {
            $type = strtolower($m[1]);

            return ['provider' => 'spotify', 'id' => $m[2], 'label' => 'Spotify', 'vertical' => false,
                'src' => "https://open.spotify.com/embed/{$type}/{$m[2]}", 'height' => in_array($type, ['track', 'episode'], true) ? 152 : 352];
        }
        if (preg_match('~^https?://(?:www\.|m\.)?tiktok\.com/@[\w.-]+/video/(\d{8,25})~i', $url, $m)) {
            return ['provider' => 'tiktok', 'id' => $m[1], 'label' => 'TikTok', 'vertical' => true,
                'src' => "https://www.tiktok.com/embed/v2/{$m[1]}", 'height' => null];
        }
        if (preg_match('~^https?://(?:www\.)?instagram\.com/(?:[\w.]+/)?(p|reel|tv)/([A-Za-z0-9_-]{5,40})~i', $url, $m)) {
            $kind = strtolower($m[1]) === 'p' ? 'p' : 'reel';

            return ['provider' => 'instagram', 'id' => $m[2], 'label' => 'Instagram', 'vertical' => $kind === 'reel',
                'src' => "https://www.instagram.com/{$kind}/{$m[2]}/embed", 'height' => $kind === 'p' ? 560 : null];
        }
        if (preg_match('~(?:youtube\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|live/)|youtu\.be/)([A-Za-z0-9_-]{11})~', $url, $m)) {
            return ['provider' => 'youtube', 'id' => $m[1], 'label' => 'YouTube', 'vertical' => str_contains($url, '/shorts/'), 'src' => null, 'height' => null];
        }

        return null;
    }
}
