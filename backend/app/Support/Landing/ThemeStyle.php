<?php

namespace App\Support\Landing;

/**
 * Page look (Fase 5): base colors, background (solid / gradient / image / pattern / animated),
 * fonts and button style → values for the public page views. Text is kept readable on the chosen
 * background (WCAG AA 4.5:1), buttons too (3:1). Everything visual is CSS variables, so a single
 * button can override the theme with inline variables.
 */
final class ThemeStyle
{
    /** Base color presets (same ids as the editor's themes). */
    public const PRESETS = [
        'industrial' => ['background' => '#ffffff', 'text' => '#18181b', 'primary' => '#facc15', 'buttonText' => '#000000'],
        'ocean' => ['background' => '#f0f9ff', 'text' => '#0c4a6e', 'primary' => '#0369a1', 'buttonText' => '#ffffff'],
        'forest' => ['background' => '#fcfdf5', 'text' => '#1a2e05', 'primary' => '#4d7c0f', 'buttonText' => '#ffffff'],
        'luxury' => ['background' => '#09090b', 'text' => '#fafafa', 'primary' => '#d4af37', 'buttonText' => '#000000'],
        'minimal' => ['background' => '#fafafa', 'text' => '#18181b', 'primary' => '#18181b', 'buttonText' => '#ffffff'],
        'blush' => ['background' => '#fff7f5', 'text' => '#3f1d24', 'primary' => '#e11d48', 'buttonText' => '#ffffff'],
        'sunset' => ['background' => '#fffbeb', 'text' => '#422006', 'primary' => '#c2410c', 'buttonText' => '#ffffff'],
    ];

    public const RADIUS = ['square' => '4px', 'rounded' => '14px', 'pill' => '999px'];

    /**
     * @return array<string, mixed> background,text,primary,buttonText,muted,surface,radius,font,headingFont,dark,
     *                              buttonVars,hover,bgCss,bgClass,bgLayers,fontFaces,fontPreload,textAdjusted
     */
    public static function resolve(array $theme): array
    {
        $preset = self::PRESETS[$theme['preset'] ?? 'industrial'] ?? self::PRESETS['industrial'];
        $colors = [
            'background' => $theme['background'] ?? $preset['background'],
            'text' => $theme['text'] ?? $preset['text'],
            'primary' => $theme['primary'] ?? $preset['primary'],
            'buttonText' => $theme['buttonText'] ?? $preset['buttonText'],
        ];
        $bg = is_array($theme['bg'] ?? null) ? $theme['bg'] : [];
        [$bgCss, $bgClass, $bgLayers, $effective] = self::background($bg, $colors['background']);

        // Readable text on the real background (gradient average / image under its overlay).
        $textAdjusted = false;
        if (self::contrast($colors['text'], $effective) < 4.5) {
            $colors['text'] = self::bestOf($effective);
            $textAdjusted = true;
        }
        if (self::contrast($colors['primary'], $colors['buttonText']) < 3) {
            $colors['buttonText'] = self::bestOf($colors['primary']);
        }
        $dark = self::luminance($effective) < 0.35;

        $headingFont = Fonts::valid($theme['headingFont'] ?? null) ? $theme['headingFont'] : 'sans';
        $bodyFont = Fonts::valid($theme['bodyFont'] ?? null) ? $theme['bodyFont'] : 'sans';
        $faces = Fonts::faces([$headingFont, $bodyFont]);
        $button = is_array($theme['button'] ?? null) ? $theme['button'] : [];
        $resolved = $colors + [
            'effectiveBackground' => $effective,
            'dark' => $dark,
            'muted' => $dark ? 'rgba(255,255,255,.72)' : 'rgba(0,0,0,.62)',
            'surface' => $dark ? 'rgba(255,255,255,.08)' : '#ffffff',
            'font' => Fonts::stack($bodyFont),
            'headingFont' => Fonts::stack($headingFont),
            'fontFaces' => $faces['css'],
            'fontPreload' => $faces['preload'],
            'bgCss' => $bgCss,
            'bgClass' => $bgClass,
            'bgLayers' => $bgLayers,
            'hover' => in_array($button['hover'] ?? null, ['lift', 'grow', 'shine'], true) ? $button['hover'] : 'none',
            'textAdjusted' => $textAdjusted,
        ];
        $resolved['button'] = [
            'shape' => isset(self::RADIUS[$button['shape'] ?? null]) ? $button['shape'] : 'rounded',
            'fill' => in_array($button['fill'] ?? null, ['solid', 'outline', 'glass'], true) ? $button['fill'] : 'solid',
            'shadow' => in_array($button['shadow'] ?? null, ['none', 'soft', 'hard'], true) ? $button['shadow'] : 'none',
            'borderWidth' => max(1, min(4, (int) ($button['borderWidth'] ?? 2))),
        ];
        $resolved['radius'] = self::RADIUS[$resolved['button']['shape']];
        $resolved['buttonVars'] = self::buttonVars($resolved);

        return $resolved;
    }

    /**
     * CSS variables of a button (theme defaults, or one button's own shape/fill/shadow).
     */
    public static function buttonVars(array $t, ?string $fill = null, ?string $shape = null, ?string $shadow = null): string
    {
        $fill = in_array($fill, ['solid', 'outline', 'glass'], true) ? $fill : $t['button']['fill'];
        $shadow = in_array($shadow, ['none', 'soft', 'hard'], true) ? $shadow : $t['button']['shadow'];
        $radius = self::RADIUS[$shape] ?? $t['radius'];
        $bw = $t['button']['borderWidth'];
        $bg = $t['effectiveBackground'];
        // Outline/glass buttons sit on the page background: keep their text readable there.
        $outlineFg = self::contrast($t['primary'], $bg) >= 3 ? $t['primary'] : $t['text'];
        [$btnBg, $btnFg, $border, $blur] = match ($fill) {
            'outline' => ['transparent', $outlineFg, $outlineFg, 'none'],
            'glass' => $t['dark']
                ? ['rgba(255,255,255,.14)', $t['text'], 'rgba(255,255,255,.32)', 'blur(12px)']
                : ['rgba(255,255,255,.6)', $t['text'], 'rgba(0,0,0,.08)', 'blur(12px)'],
            default => [$t['primary'], $t['buttonText'], $t['primary'], 'none'],
        };
        $offset = $bw + 2;
        $shadowCss = match ($shadow) {
            'soft' => '0 8px 24px rgba(0,0,0,.16)',
            'hard' => "{$offset}px {$offset}px 0 " . ($t['dark'] ? '#ffffff' : '#000000'),
            default => 'none',
        };
        if ($shadow === 'hard' && $fill === 'solid') {
            $border = $t['dark'] ? '#ffffff' : '#000000'; // neo-brutalism: dark outline + offset shadow
        }

        return "--btn-bg:{$btnBg};--btn-fg:{$btnFg};--btn-border:{$border};--btn-bw:{$bw}px;--btn-shadow:{$shadowCss};--btn-blur:{$blur};--radius:{$radius}";
    }

    /** @return array{0:string,1:string,2:string,3:string} css for body, body class, extra layers html, effective color */
    private static function background(array $bg, string $base): array
    {
        $type = $bg['type'] ?? 'solid';
        $color = self::hex($bg['color'] ?? null) ?? $base;
        $from = self::hex($bg['from'] ?? null) ?? $color;
        $to = self::hex($bg['to'] ?? null) ?? $from;
        $via = self::hex($bg['via'] ?? null);
        $angle = max(0, min(360, (int) ($bg['angle'] ?? 160)));
        $stops = implode(',', array_filter([$from, $via, $to]));
        $average = self::mix($from, $to, 0.5);

        switch ($type) {
            case 'gradient':
                return ["background:{$from};background-image:linear-gradient({$angle}deg,{$stops});background-attachment:fixed", 'bg-gradient', '', $via ? self::mix($average, $via, 0.5) : $average];
            case 'image':
                $url = BlockSchema::url($bg['image'] ?? null, true);
                if (!$url) {
                    return ["background:{$color}", 'bg-solid', '', $color];
                }
                $overlay = max(0.0, min(0.9, (float) ($bg['overlay'] ?? 0.35)));
                $blur = max(0, min(20, (int) ($bg['blur'] ?? 0)));
                $position = in_array($bg['position'] ?? null, ['top', 'bottom', 'center'], true) ? $bg['position'] : 'center';
                $safe = preg_replace('/[^A-Za-z0-9:\/._~%?&=#+-]/', '', $url);
                $layers = '<div class="bg-layer bg-img" style="background-image:url(\'' . e($safe) . '\');background-position:center ' . $position
                    . ($blur ? ';filter:blur(' . $blur . 'px);transform:scale(1.08)' : '') . '" aria-hidden="true"></div>'
                    . '<div class="bg-layer" style="background:rgba(0,0,0,' . $overlay . ')" aria-hidden="true"></div>';

                // Unknown photo: assume mid-grey under the dark overlay.
                return ["background:{$color}", 'bg-image', $layers, self::mix('#7f7f7f', '#000000', $overlay)];
            case 'pattern':
                $ink = self::rgba(self::hex($bg['patternColor'] ?? null) ?? (self::luminance($color) < 0.35 ? '#ffffff' : '#000000'), max(0.03, min(0.5, (float) ($bg['patternOpacity'] ?? 0.12))));
                // Small tiles: CSS gradients or an inline SVG (data: URI, no request; CSP img-src allows data:).
                $svg = fn (string $w, string $h, string $path) => 'url("data:image/svg+xml,' . rawurlencode(
                    "<svg xmlns='http://www.w3.org/2000/svg' width='{$w}' height='{$h}'><path d='{$path}' fill='none' stroke='{$ink}' stroke-width='2' stroke-linecap='round'/></svg>"
                ) . '")';
                [$layers, $tile] = match ($bg['pattern'] ?? 'dots') {
                    'grid' => ["linear-gradient({$ink} 1px,transparent 1px),linear-gradient(90deg,{$ink} 1px,transparent 1px)", '24px 24px'],
                    'diagonal' => ["repeating-linear-gradient(45deg,{$ink} 0 2px,transparent 2px 14px)", 'auto'],
                    'checks' => ["conic-gradient({$ink} 25%,transparent 0 50%,{$ink} 0 75%,transparent 0)", '28px 28px'],
                    'waves' => [$svg('40', '16', 'M0 8c5 0 5-6 10-6s5 6 10 6 5-6 10-6 5 6 10 6'), '40px 16px'],
                    'plus' => [$svg('28', '28', 'M14 9v10M9 14h10'), '28px 28px'],
                    default => ["radial-gradient({$ink} 1.4px,transparent 1.6px)", '18px 18px'],
                };

                return ["background-color:{$color};background-image:{$layers};background-size:{$tile}", 'bg-pattern', '', $color];
            case 'animated':
                $animation = in_array($bg['animation'] ?? null, ['aurora', 'blobs', 'particles'], true) ? $bg['animation'] : 'aurora';
                $layer = match ($animation) {
                    'blobs' => '<div class="bg-layer bg-blobs" aria-hidden="true"><i style="background:' . $from . '"></i><i style="background:' . ($via ?? $to) . '"></i><i style="background:' . $to . '"></i></div>',
                    'particles' => '<div class="bg-layer bg-particles" aria-hidden="true">' . str_repeat('<i></i>', 14) . '</div>',
                    default => '<div class="bg-layer bg-aurora" style="background-image:linear-gradient(' . $angle . 'deg,' . $stops . ',' . $from . ')" aria-hidden="true"></div>',
                };

                return ["background:{$color}", 'bg-animated anim-' . $animation, $layer, $animation === 'particles' ? $color : self::mix(self::mix($color, $average, 0.5), $color, 0.3)];
            default:
                return ["background:{$color}", 'bg-solid', '', $color];
        }
    }

    // ── color helpers ──

    private static function hex(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $value) ? strtolower(strlen($value) === 4
            ? '#' . $value[1] . $value[1] . $value[2] . $value[2] . $value[3] . $value[3] : $value) : null;
    }

    /** @return array{0:int,1:int,2:int} */
    private static function rgb(string $hex): array
    {
        $hex = ltrim(self::hex($hex) ?? '#000000', '#');

        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    private static function rgba(string $hex, float $alpha): string
    {
        [$r, $g, $b] = self::rgb($hex);

        return "rgba({$r},{$g},{$b}," . round($alpha, 2) . ')';
    }

    /** Color $a with $ratio of $b mixed in. */
    public static function mix(string $a, string $b, float $ratio): string
    {
        [$r1, $g1, $b1] = self::rgb($a);
        [$r2, $g2, $b2] = self::rgb($b);
        $c = fn ($x, $y) => str_pad(dechex((int) round($x + ($y - $x) * $ratio)), 2, '0', STR_PAD_LEFT);

        return '#' . $c($r1, $r2) . $c($g1, $g2) . $c($b1, $b2);
    }

    public static function luminance(string $hex): float
    {
        [$r, $g, $b] = array_map(function ($c) {
            $v = $c / 255;

            return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        }, self::rgb($hex));

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    /** WCAG contrast ratio (1–21). */
    public static function contrast(string $a, string $b): float
    {
        [$x, $y] = [self::luminance($a), self::luminance($b)];

        return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
    }

    private static function bestOf(string $background): string
    {
        return self::contrast('#000000', $background) >= self::contrast('#ffffff', $background) ? '#000000' : '#ffffff';
    }
}
