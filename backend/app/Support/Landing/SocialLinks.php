<?php

namespace App\Support\Landing;

/**
 * Social media panel of a Hellom Page (Fase 4). The seller types a username, a phone number or
 * pastes a link; the link is always rebuilt here from that value, so only a real profile URL of
 * the chosen platform can appear on the page (never javascript:, never another site).
 * Mirrors frontend landing-builder/editor/socialPlatforms.ts (instant feedback in the editor).
 */
final class SocialLinks
{
    /** key => [label, brand color] in the order the editor offers them. */
    public const PLATFORMS = [
        'instagram' => ['Instagram', '#e1306c'],
        'tiktok' => ['TikTok', '#000000'],
        'youtube' => ['YouTube', '#ff0000'],
        'facebook' => ['Facebook', '#1877f2'],
        'x' => ['X', '#000000'],
        'threads' => ['Threads', '#000000'],
        'whatsapp' => ['WhatsApp', '#25d366'],
        'telegram' => ['Telegram', '#229ed9'],
        'linkedin' => ['LinkedIn', '#0a66c2'],
        'pinterest' => ['Pinterest', '#e60023'],
        'shopee' => ['Shopee', '#ee4d2d'],
        'tokopedia' => ['Tokopedia', '#03ac0e'],
        'spotify' => ['Spotify', '#1db954'],
        'discord' => ['Discord', '#5865f2'],
        'snapchat' => ['Snapchat', '#fffc00'],
        'twitch' => ['Twitch', '#9146ff'],
        'email' => ['Email', '#52525b'],
        'website' => ['Website', '#52525b'],
    ];

    /**
     * Hellom's own simple line glyphs (24×24, drawn for Hellom — not the platforms' official logos),
     * rendered inside <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">.
     */
    public const ICONS = [
        'instagram' => '<rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r="1" fill="currentColor" stroke="none"/>',
        'tiktok' => '<path d="M14 3v11.5a3.5 3.5 0 1 1-3.5-3.5"/><path d="M14 3c.6 2.6 2.4 4.4 5 4.8"/>',
        'youtube' => '<rect x="2" y="5" width="20" height="14" rx="4"/><path d="M10 9l5 3-5 3z" fill="currentColor"/>',
        'facebook' => '<path d="M15 3h-2a4 4 0 0 0-4 4v3H7v4h2v7h4v-7h2.5l.5-4h-3V7.5a.5.5 0 0 1 .5-.5H15z"/>',
        'x' => '<path d="M4 4l16 16M20 4L4 20"/>',
        'threads' => '<circle cx="12" cy="12" r="3.5"/><path d="M15.5 12v1.3a2.6 2.6 0 0 0 5.2 0V12a8.7 8.7 0 1 0-3.4 6.9"/>',
        'whatsapp' => '<path d="M3.5 20.5l1.4-4.3A8.6 8.6 0 1 1 8 19.3z"/><path d="M9.2 8.8c0 3 2.9 6 6 6l1-1.1-1.7-1.2-.9.5a3.6 3.6 0 0 1-1.6-1.6l.5-.9-1.2-1.7z" fill="currentColor" stroke="none"/>',
        'telegram' => '<path d="M21 4L3 11l6.5 2.2L12 20l3.3-4.4L20 19z"/><path d="M9.5 13.2L21 4"/>',
        'linkedin' => '<rect x="3" y="3" width="18" height="18" rx="3"/><path d="M8 10.5V17M8 7.5v.01M12 17v-6.5M12 13.5a2.5 2.5 0 0 1 5 0V17"/>',
        'pinterest' => '<circle cx="12" cy="12" r="9"/><path d="M10.5 20.5l2-8.2"/><path d="M9.3 13.5A3.6 3.6 0 1 1 13 15"/>',
        'shopee' => '<path d="M5 8h14l-1.2 12H6.2z"/><path d="M9 8a3 3 0 0 1 6 0"/><path d="M14 12.3c-.5-.6-1.2-.8-2-.8-1 0-1.8.5-1.8 1.3 0 1.8 3.8 1 3.8 2.9 0 .8-.8 1.4-2 1.4-.9 0-1.6-.3-2.1-.9"/>',
        'tokopedia' => '<path d="M4 9h16v11H4z"/><path d="M8 9a4 4 0 0 1 8 0"/><circle cx="9.5" cy="14" r="1.6"/><circle cx="14.5" cy="14" r="1.6"/>',
        'spotify' => '<circle cx="12" cy="12" r="9"/><path d="M7.5 9.5c3-1 6.5-.7 9 .8M8 12.6c2.5-.7 5.2-.4 7.2.8M8.6 15.5c2-.5 4-.3 5.6.6"/>',
        'discord' => '<path d="M6.5 7c3.6-1.6 7.4-1.6 11 0l2 9c-1.8 1.8-3.6 2.4-4.8 2.6l-1-2.1h-3.4l-1 2.1c-1.2-.2-3-.8-4.8-2.6z"/><circle cx="9.5" cy="12.5" r="1" fill="currentColor"/><circle cx="14.5" cy="12.5" r="1" fill="currentColor"/>',
        'snapchat' => '<path d="M12 3.5c2.8 0 4.7 2 4.7 4.8v2l1.8.9-1.8.9c.4 1.8 1.8 2.8 2.8 3.2-1.4.5-2.8.5-3.7 1.4-.9.8-2.2 1-3.8 1s-2.9-.2-3.8-1c-.9-.9-2.3-.9-3.7-1.4 1-.4 2.4-1.4 2.8-3.2l-1.8-.9 1.8-.9v-2c0-2.8 1.9-4.8 4.7-4.8z"/>',
        'twitch' => '<path d="M4 3h16v11l-5 5h-4l-3 3v-3H4z"/><path d="M11 7.5v4M15.5 7.5v4"/>',
        'email' => '<rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="M3.5 6.5l8.5 6.5 8.5-6.5"/>',
        'website' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.6 3.7 5.6 3.7 9s-1.2 6.4-3.7 9c-2.5-2.6-3.7-5.6-3.7-9S9.5 5.6 12 3z"/>',
    ];
    public const MAX_ITEMS = 18;
    public const POSITIONS = ['top', 'bottom'];
    public const SIZES = ['sm', 'md', 'lg'];
    public const COLORS = ['mono', 'brand', 'custom'];

    /** The profile URL for what the seller typed, or null when it is not valid for that platform. */
    public static function url(string $platform, mixed $value): ?string
    {
        if (!is_string($value) || !isset(self::PLATFORMS[$platform])) {
            return null;
        }
        $v = trim($value);
        if ($platform === 'whatsapp') {
            $v = preg_replace('/[\s().-]/', '', $v) ?? ''; // "+62 812-3456 7890"
        }
        if ($v === '' || strlen($v) > 300 || preg_match('/[\s<>"\'`]/', $v)) {
            return null;
        }
        // Path of a pasted link on one of the platform's own hosts, e.g. "instagram.com/toko" → "toko".
        $path = function (string $hosts) use ($v): ?string {
            if (preg_match('~^(?:https?://)?(?:(?:www|m|mobile|web|id)\.)?(?:' . $hosts . ')(?:/([^?#]*))?~i', $v, $m)) {
                return trim($m[1] ?? '', '/');
            }

            return null;
        };
        $handle = fn (string $pattern, string $from) => preg_match('~^@?(' . $pattern . ')$~', $from, $m) ? $m[1] : null;

        switch ($platform) {
            case 'instagram':
                $h = $handle('[A-Za-z0-9._]{1,30}', ($p = $path('instagram\.com')) !== null ? explode('/', $p)[0] : $v);

                return $h && !in_array(strtolower($h), ['p', 'reel', 'explore', 'accounts'], true) ? "https://www.instagram.com/{$h}" : null;
            case 'tiktok':
                $h = $handle('[A-Za-z0-9._]{2,24}', ($p = $path('tiktok\.com')) !== null ? explode('/', $p)[0] : $v);

                return $h ? "https://www.tiktok.com/@{$h}" : null;
            case 'youtube':
                $p = $path('youtube\.com');
                if ($p !== null) {
                    return preg_match('~^(@[A-Za-z0-9._-]{3,30}|channel/UC[A-Za-z0-9_-]{22}|c/[A-Za-z0-9._-]{1,100}|user/[A-Za-z0-9._-]{1,100})$~', $p) ? "https://www.youtube.com/{$p}" : null;
                }
                $h = $handle('[A-Za-z0-9._-]{3,30}', $v);

                return $h ? "https://www.youtube.com/@{$h}" : null;
            case 'facebook':
                $p = $path('facebook\.com|fb\.com');
                if ($p !== null) {
                    if (preg_match('~^profile\.php$~', $p) && preg_match('~[?&]id=(\d{5,20})~', $v, $m)) {
                        return "https://www.facebook.com/profile.php?id={$m[1]}";
                    }
                    $p = explode('/', $p)[0];
                }
                $h = $handle('[A-Za-z0-9.]{5,50}', $p ?? $v);

                return $h ? "https://www.facebook.com/{$h}" : null;
            case 'x':
                $h = $handle('[A-Za-z0-9_]{1,15}', ($p = $path('x\.com|twitter\.com')) !== null ? explode('/', $p)[0] : $v);

                return $h ? "https://x.com/{$h}" : null;
            case 'threads':
                $h = $handle('[A-Za-z0-9._]{1,30}', ($p = $path('threads\.net|threads\.com')) !== null ? explode('/', $p)[0] : $v);

                return $h ? "https://www.threads.net/@{$h}" : null;
            case 'whatsapp':
                $p = $path('wa\.me');
                $digits = preg_replace('/\D/', '', $p ?? $v);
                $digits = str_starts_with($digits, '0') ? '62' . substr($digits, 1) : $digits;

                return preg_match('/^[1-9]\d{8,14}$/', $digits) ? "https://wa.me/{$digits}" : null;
            case 'telegram':
                $h = $handle('[A-Za-z][A-Za-z0-9_]{4,31}', ($p = $path('t\.me|telegram\.me')) !== null ? explode('/', $p)[0] : $v);

                return $h ? "https://t.me/{$h}" : null;
            case 'linkedin':
                $p = $path('linkedin\.com');
                if ($p !== null) {
                    return preg_match('~^(in|company|school)/([A-Za-z0-9-]{2,100})~', $p, $m) ? "https://www.linkedin.com/{$m[1]}/{$m[2]}" : null;
                }
                $h = $handle('[A-Za-z0-9-]{3,100}', $v);

                return $h ? "https://www.linkedin.com/in/{$h}" : null;
            case 'pinterest':
                $h = $handle('[A-Za-z0-9_]{3,30}', ($p = $path('pinterest\.com|id\.pinterest\.com|pin\.it')) !== null ? explode('/', $p)[0] : $v);

                return $h ? "https://www.pinterest.com/{$h}" : null;
            case 'shopee':
                $h = $handle('[A-Za-z0-9._]{3,40}', ($p = $path('shopee\.co\.id')) !== null ? explode('/', $p)[0] : $v);

                return $h ? "https://shopee.co.id/{$h}" : null;
            case 'tokopedia':
                $h = $handle('[A-Za-z0-9-]{3,40}', ($p = $path('tokopedia\.com')) !== null ? explode('/', $p)[0] : $v);

                return $h ? "https://www.tokopedia.com/{$h}" : null;
            case 'spotify':
                $p = $path('open\.spotify\.com');

                return $p !== null && preg_match('~^(?:intl-[a-z]{2}(?:-[a-z]{2})?/)?(artist|user|show|playlist)/([A-Za-z0-9]{10,40})~i', $p, $m)
                    ? 'https://open.spotify.com/' . strtolower($m[1]) . '/' . $m[2] : null;
            case 'discord':
                $p = $path('discord\.gg|discord\.com/invite');
                $code = $handle('[A-Za-z0-9-]{2,32}', $p !== null ? explode('/', $p)[0] : $v);

                return $code ? "https://discord.gg/{$code}" : null;
            case 'snapchat':
                $p = $path('snapchat\.com');
                $h = $handle('[A-Za-z][A-Za-z0-9._-]{2,14}', $p !== null ? (preg_replace('~^add/~', '', $p) ?? '') : $v);

                return $h ? "https://www.snapchat.com/add/{$h}" : null;
            case 'twitch':
                $h = $handle('[A-Za-z0-9_]{4,25}', ($p = $path('twitch\.tv')) !== null ? explode('/', $p)[0] : $v);

                return $h ? "https://www.twitch.tv/{$h}" : null;
            case 'email':
                $email = preg_replace('~^mailto:~i', '', $v);

                return filter_var($email, FILTER_VALIDATE_EMAIL) ? 'mailto:' . strtolower($email) : null;
            case 'website':
                $url = preg_match('~^https?://~i', $v) ? $v : 'https://' . $v;
                $host = (string) parse_url($url, PHP_URL_HOST);

                // No "user@" part: https://bank.com@evil.com opens evil.com (phishing).
                $authority = explode('/', (string) preg_replace('~^https?://~i', '', $url))[0];
                $hasUserInfo = str_contains($authority, '@');

                return !$hasUserInfo && filter_var($url, FILTER_VALIDATE_URL) && str_contains($host, '.') && preg_match('~^[a-z0-9.-]+$~i', $host) ? $url : null;
        }

        return null;
    }

    /**
     * Clean the page's social panel: known platforms with a valid value only, url rebuilt.
     *
     * @return array{items:list<array{platform:string,value:string,url:string}>, position:string, size:string, color:string, customColor:?string}
     */
    public static function normalize(mixed $social): array
    {
        $social = is_array($social) ? $social : [];
        $items = [];
        $seen = [];
        foreach (array_slice(array_values(is_array($social['items'] ?? null) ? $social['items'] : []), 0, self::MAX_ITEMS) as $item) {
            $platform = is_array($item) ? (string) ($item['platform'] ?? '') : '';
            $value = is_array($item) && is_string($item['value'] ?? null) ? trim($item['value']) : '';
            $url = self::url($platform, $value);
            if ($url === null || isset($seen[$platform])) {
                continue; // invalid or a second entry for the same platform
            }
            $seen[$platform] = true;
            $items[] = ['platform' => $platform, 'value' => mb_substr($value, 0, 300), 'url' => $url];
        }
        $color = in_array($social['color'] ?? null, self::COLORS, true) ? $social['color'] : 'mono';
        $custom = is_string($social['customColor'] ?? null) && preg_match('/^#[0-9a-fA-F]{6}$/', $social['customColor']) ? strtolower($social['customColor']) : null;

        return [
            'items' => $items,
            'position' => in_array($social['position'] ?? null, self::POSITIONS, true) ? $social['position'] : 'bottom',
            'size' => in_array($social['size'] ?? null, self::SIZES, true) ? $social['size'] : 'md',
            'color' => $color === 'custom' && $custom === null ? 'mono' : $color,
            'customColor' => $custom,
        ];
    }
}
