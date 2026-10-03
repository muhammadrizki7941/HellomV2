<?php

namespace App\Support\Landing;

use App\Models\Organization;
use Illuminate\Support\Facades\Storage;

/**
 * Share card for a Hellom Page (Fase 6): 1200×630 JPEG with the profile photo, name and bio on the
 * page's own background, used as og:image when the seller has not uploaded one (seo_image).
 * Only reads images from our public disk (never fetches URLs). The hash in the URL changes with
 * the content, so the file can be cached forever.
 */
final class OgImage
{
    public const WIDTH = 1200;

    public const HEIGHT = 630;

    private const VERSION = 'v1';

    public static function supported(): bool
    {
        return function_exists('imagettftext') && function_exists('imagejpeg') && is_file(self::font(800));
    }

    /**
     * What the card shows, from a (normalized) page document.
     *
     * @return array{name: string, bio: string, avatar: ?string, from: string, to: string, text: string, muted: string, primary: string, buttonText: string, address: string}
     */
    public static function card(Organization $organization, array $document, string $address): array
    {
        $profile = null;
        foreach ($document['blocks'] ?? [] as $block) {
            if (($block['type'] ?? null) === 'profile' && empty($block['hidden'])) {
                $profile = $block['content'] ?? [];
                break;
            }
        }
        $theme = ThemeStyle::resolve($document['theme'] ?? []);
        $bg = is_array($document['theme']['bg'] ?? null) ? $document['theme']['bg'] : [];
        $gradient = in_array($bg['type'] ?? null, ['gradient', 'animated'], true) && !empty($bg['from']);
        $bio = trim(preg_split('/\R/', (string) ($profile['bio'] ?? ''))[0] ?? '');

        return [
            'name' => mb_substr(trim((string) ($profile['name'] ?? '')) ?: (string) $organization->name, 0, 60),
            'bio' => mb_substr($bio, 0, 120),
            'avatar' => self::localImage($profile['avatarUrl'] ?? null) ? (string) $profile['avatarUrl'] : null,
            'from' => $gradient ? (string) $bg['from'] : $theme['effectiveBackground'],
            'to' => $gradient ? (string) ($bg['to'] ?? $bg['from']) : $theme['effectiveBackground'],
            'text' => $theme['text'],
            'muted' => $theme['dark'] ? '#d4d4d8' : '#52525b',
            'primary' => $theme['primary'],
            'buttonText' => $theme['buttonText'],
            'address' => $address,
        ];
    }

    public static function hash(array $card): string
    {
        return substr(sha1(self::VERSION . '|' . json_encode($card)), 0, 12);
    }

    /** JPEG bytes of the card. */
    public static function render(array $card): string
    {
        $w = self::WIDTH;
        $h = self::HEIGHT;
        $img = imagecreatetruecolor($w, $h);
        [$r1, $g1, $b1] = self::rgb($card['from']);
        [$r2, $g2, $b2] = self::rgb($card['to']);
        for ($x = 0; $x < $w; $x++) { // diagonal-ish gradient, solid when both ends match
            $t = $x / ($w - 1);
            imageline($img, $x, 0, $x, $h, imagecolorallocate($img, (int) ($r1 + ($r2 - $r1) * $t), (int) ($g1 + ($g2 - $g1) * $t), (int) ($b1 + ($b2 - $b1) * $t)));
        }
        $text = self::color($img, $card['text']);
        $muted = self::color($img, $card['muted']);

        // Profile photo (circle) or the initial on the primary colour.
        $size = 240;
        $ax = 96;
        $ay = (int) (($h - $size) / 2);
        $photo = $card['avatar'] ? self::loadCircle((string) self::localImage($card['avatar']), $size) : null;
        if ($photo) {
            imagecopy($img, $photo, $ax, $ay, 0, 0, $size, $size);
            imagedestroy($photo);
        } else {
            $dot = self::circle(null, $card['primary'], $size);
            imagecopy($img, $dot, $ax, $ay, 0, 0, $size, $size);
            imagedestroy($dot);
            $initial = mb_strtoupper(mb_substr($card['name'], 0, 1));
            $box = imagettfbbox(110, 0, self::font(800), $initial);
            imagettftext($img, 110, 0, (int) ($ax + ($size - ($box[2] - $box[0])) / 2 - $box[0]), (int) ($ay + ($size + ($box[1] - $box[7])) / 2 - $box[1]), self::color($img, $card['buttonText']), self::font(800), $initial);
        }

        // Name (shrinks to fit, up to 2 lines), bio (2 lines), address.
        $left = $ax + $size + 64;
        $maxWidth = $w - $left - 80;
        $nameSize = 64;
        do {
            $nameLines = self::wrap($card['name'], self::font(800), $nameSize, $maxWidth, 2);
            $nameSize -= 4;
        } while ($nameLines === null && $nameSize >= 36);
        $nameLines ??= self::wrap($card['name'], self::font(800), 36, $maxWidth, 2, true);
        $nameSize += 4;
        $bioLines = $card['bio'] !== '' ? self::wrap($card['bio'], self::font(500), 30, $maxWidth, 2, true) : [];

        $lineName = (int) ($nameSize * 1.25);
        $blockHeight = count($nameLines) * $lineName + ($bioLines ? 24 + count($bioLines) * 44 : 0) + 72;
        $y = (int) (($h - $blockHeight) / 2) + $nameSize;
        foreach ($nameLines as $line) {
            imagettftext($img, $nameSize, 0, $left, $y, $text, self::font(800), $line);
            $y += $lineName;
        }
        if ($bioLines) {
            $y += 24 - $lineName + 44;
            foreach ($bioLines as $line) {
                imagettftext($img, 30, 0, $left, $y, $muted, self::font(500), $line);
                $y += 44;
            }
            $y -= 44;
        } else {
            $y -= $lineName;
        }
        imagettftext($img, 26, 0, $left, $y + 72, $muted, self::font(500), $card['address']);

        ob_start();
        imagejpeg($img, null, 86);
        imagedestroy($img);

        return (string) ob_get_clean();
    }

    /** Absolute path of an image on our public disk ("/media/…", "/storage/…", or our own full URL). */
    public static function localImage(?string $url): ?string
    {
        $path = (string) parse_url((string) $url, PHP_URL_PATH);
        $host = parse_url((string) $url, PHP_URL_HOST);
        if ($host !== null && !in_array($host, array_filter([parse_url((string) config('app.url'), PHP_URL_HOST), parse_url((string) config('app.frontend_url'), PHP_URL_HOST)]), true)) {
            return null;
        }
        foreach (['/media/', '/storage/'] as $prefix) {
            if (str_starts_with($path, $prefix) && !str_contains($path, '..')) {
                $relative = rawurldecode(substr($path, strlen($prefix)));
                $disk = Storage::disk('public');

                return $disk->exists($relative) ? $disk->path($relative) : null;
            }
        }

        return null;
    }

    private static function font(int $weight): string
    {
        return resource_path('fonts/og/jakarta-' . ($weight >= 700 ? 800 : 500) . '.ttf');
    }

    /** @return list<string>|null lines, or null when it does not fit and $force is false */
    private static function wrap(string $text, string $font, int $size, int $maxWidth, int $maxLines, bool $force = false): ?array
    {
        $words = preg_split('/\s+/', trim($text)) ?: [];
        $lines = [];
        $line = '';
        foreach ($words as $word) {
            $try = $line === '' ? $word : $line . ' ' . $word;
            if (self::width($try, $font, $size) <= $maxWidth || $line === '') {
                $line = $try;
                continue;
            }
            $lines[] = $line;
            $line = $word;
        }
        if ($line !== '') {
            $lines[] = $line;
        }
        $tooWide = array_filter($lines, fn ($l) => self::width($l, $font, $size) > $maxWidth);
        if (count($lines) <= $maxLines && !$tooWide) {
            return $lines;
        }
        if (!$force) {
            return null;
        }
        $lines = array_slice($lines, 0, $maxLines);
        foreach ($lines as $i => $l) { // cut with an ellipsis
            $cut = count($lines) - 1 === $i || self::width($l, $font, $size) > $maxWidth;
            while ($cut && mb_strlen($l) > 1 && self::width($l . '…', $font, $size) > $maxWidth) {
                $l = mb_substr($l, 0, -1);
            }
            $lines[$i] = $cut && $l !== $lines[$i] ? rtrim($l) . '…' : $l;
        }

        return $lines;
    }

    private static function width(string $text, string $font, int $size): int
    {
        $box = imagettfbbox($size, 0, $font, $text);

        return (int) ($box[2] - $box[0]);
    }

    private static function loadCircle(string $path, int $size): ?\GdImage
    {
        $data = @file_get_contents($path);
        $src = $data ? @imagecreatefromstring($data) : false;
        if (!$src) {
            return null;
        }
        $circle = self::circle($src, null, $size);
        imagedestroy($src);

        return $circle;
    }

    /** Smooth circle (drawn at 2× and scaled down): a centre-cropped photo, or a solid colour. */
    private static function circle(?\GdImage $photo, ?string $color, int $size): \GdImage
    {
        $big = $size * 2;
        $square = imagecreatetruecolor($big, $big);
        imagealphablending($square, false);
        imagesavealpha($square, true);
        if ($photo) {
            $side = min(imagesx($photo), imagesy($photo));
            imagecopyresampled($square, $photo, 0, 0, (int) ((imagesx($photo) - $side) / 2), (int) ((imagesy($photo) - $side) / 2), $big, $big, $side, $side);
        } else {
            imagefill($square, 0, 0, self::color($square, (string) $color));
        }
        $clear = imagecolorallocatealpha($square, 0, 0, 0, 127);
        $r = $big / 2;
        for ($x = 0; $x < $big; $x++) {
            for ($y = 0; $y < $big; $y++) {
                if (($x - $r + .5) ** 2 + ($y - $r + .5) ** 2 > $r * $r) {
                    imagesetpixel($square, $x, $y, $clear);
                }
            }
        }
        $small = imagecreatetruecolor($size, $size);
        imagealphablending($small, false);
        imagesavealpha($small, true);
        imagefill($small, 0, 0, imagecolorallocatealpha($small, 0, 0, 0, 127));
        imagecopyresampled($small, $square, 0, 0, 0, 0, $size, $size, $big, $big);
        imagedestroy($square);
        imagealphablending($small, true);

        return $small;
    }

    private static function color(\GdImage $img, string $hex): int
    {
        [$r, $g, $b] = self::rgb($hex);

        return imagecolorallocate($img, $r, $g, $b);
    }

    /** @return array{int, int, int} */
    private static function rgb(string $hex): array
    {
        $hex = ltrim(trim($hex), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (!preg_match('/^[0-9a-f]{6}$/i', $hex)) {
            return [255, 255, 255];
        }

        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }
}
