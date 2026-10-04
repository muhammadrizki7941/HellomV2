<?php

namespace App\Support\Landing;

use App\Support\SafeHtml;
use Illuminate\Support\Str;

/**
 * The shape of a Hellom Page document (audit LB-23): every block type has a whitelist of
 * fields with a type and a length limit. Unknown fields are dropped, URLs must be
 * http(s)/mailto/tel/relative (no javascript:/data:), colors are hex, HTML is sanitised.
 * The server stores and renders only what passes here — seller content is data, not markup.
 *
 * Document: { schema_version, theme{...}, settings{...}, blocks: [{ id, type, hidden, content{}, styles{} }] }
 * Older documents are upgraded first (DocumentMigrator).
 */
final class BlockSchema
{
    public const MAX_BLOCKS = 60;

    /** field => spec. Specs: str:N, text:N, url, img, bool, int:min:max, num:min:max, enum:a|b, color, date, html, pid, pids, font, icon, ['list', N, spec], ['obj', spec] */
    public const TYPES = [
        // Banner (Fase 7.1): image/GIF (coverUrl) or a YouTube link playing muted (coverVideo); ratio, focus point,
        // fade into the page background, avatar overlapping the banner edge or below it.
        'profile' => ['name' => 'str:80', 'bio' => 'text:300', 'avatarUrl' => 'img', 'coverUrl' => 'img', 'showVerified' => 'bool',
            'coverVideo' => 'url', 'coverRatio' => 'enum:wide|banner|square', 'coverFocusX' => 'int:0:100', 'coverFocusY' => 'int:0:100',
            'coverFade' => 'bool', 'avatarPosition' => 'enum:overlap|below'],
        'hero' => ['title' => 'str:160', 'subtitle' => 'text:400', 'buttonText' => 'str:40', 'showButton' => 'bool', 'linkUrl' => 'url', 'imageUrl' => 'img'],
        'features' => ['title' => 'str:160', 'items' => ['list', 12, ['title' => 'str:80', 'desc' => 'text:300']]],
        'cta' => ['title' => 'str:160', 'subtitle' => 'text:400', 'buttonText' => 'str:40', 'actionType' => 'enum:whatsapp|link', 'whatsappNumber' => 'str:20', 'whatsappMessage' => 'text:300', 'linkUrl' => 'url'],
        'content' => ['title' => 'str:160', 'body' => 'text:5000'],
        'text' => ['body' => 'text:5000'],
        'banner' => ['imageUrl' => 'img', 'title' => 'str:160', 'subtitle' => 'text:400', 'textColor' => 'color', 'overlayOpacity' => 'num:0:1'],
        'image' => ['imageUrl' => 'img', 'caption' => 'str:200', 'linkUrl' => 'url', 'alt' => 'str:160'],
        'gif' => ['gifUrl' => 'img', 'caption' => 'str:200'],
        // Fase 7.3: YouTube (incl. Shorts), TikTok, Instagram Reels; autoplay = muted, starts when visible.
        'video' => ['videoUrl' => 'url', 'title' => 'str:160', 'autoplay' => 'bool', 'hideTitle' => 'bool', 'corners' => 'enum:rounded|square'],
        // kind: which products the editor's picker offers (digital / physical gallery cards).
        'product' => ['productId' => 'pid', 'buttonText' => 'str:40', 'layout' => 'enum:card|wide', 'kind' => 'enum:digital|physical|rental',
            // Legacy inline product (before Fase 3); only for blocks not linked to a product.
            'name' => 'str:200', 'price' => 'str:40', 'description' => 'text:1000', 'imageUrl' => 'img'],
        'catalog' => ['title' => 'str:160', 'productIds' => 'pids', 'showAll' => 'bool', 'columns' => 'int:1:3', 'buttonText' => 'str:40'],
        'pdf' => ['title' => 'str:160', 'description' => 'text:600', 'fileUrl' => 'url', 'fileName' => 'str:160', 'accessType' => 'enum:free|paid', 'price' => 'str:40',
            'buttonText' => 'str:40', 'paidButtonText' => 'str:40'],
        'social' => ['title' => 'str:80', 'facebook' => 'url', 'instagram' => 'url', 'tiktok' => 'url', 'threads' => 'url', 'youtube' => 'url', 'x' => 'url', 'linkedin' => 'url', 'whatsapp' => 'str:20'],
        'form' => ['title' => 'str:160', 'subtitle' => 'text:400', 'buttonText' => 'str:40', 'successMessage' => 'str:200', 'sendToWhatsapp' => 'bool', 'whatsappNumber' => 'str:20',
            'fields' => ['list', 10, ['id' => 'str:40', 'label' => 'str:80', 'type' => 'enum:text|tel|email|textarea|number', 'required' => 'bool', 'system' => 'bool']]],
        'button' => ['text' => 'str:60', 'actionType' => 'enum:link|whatsapp', 'linkUrl' => 'url', 'whatsappNumber' => 'str:20', 'whatsappMessage' => 'text:300',
            'align' => 'enum:left|center|right', 'style' => 'enum:solid|outline', 'fullWidth' => 'bool',
            // Fase 5: this button's own look (empty = theme), left icon or thumbnail, featured animation.
            'shape' => 'enum:square|rounded|pill', 'fill' => 'enum:solid|outline|glass', 'shadow' => 'enum:none|soft|hard',
            'icon' => 'icon', 'thumbUrl' => 'img', 'featured' => 'bool',
            // Fase 7.2: featured animation (empty = pulse + shimmer).
            'featuredStyle' => 'enum:pulse|shake|glow|shimmer'],
        'divider' => ['style' => 'enum:solid|dashed|dotted', 'thickness' => 'int:1:8', 'width' => 'int:10:100'],
        'testimonials' => ['title' => 'str:160', 'items' => ['list', 20, ['name' => 'str:80', 'role' => 'str:80', 'text' => 'text:600', 'rating' => 'int:1:5', 'avatarUrl' => 'img']]],
        'faq' => ['title' => 'str:160', 'items' => ['list', 30, ['q' => 'str:200', 'a' => 'text:1500']]],
        'list' => ['title' => 'str:160', 'items' => ['list', 30, ['text' => 'str:200']]],
        'slider' => ['autoplay' => 'bool', 'images' => ['list', 12, ['url' => 'img', 'caption' => 'str:160']]],
        'gallery' => ['title' => 'str:160', 'columns' => 'int:2:4', 'images' => ['list', 24, ['url' => 'img', 'caption' => 'str:160']]],
        'countdown' => ['title' => 'str:160', 'subtitle' => 'text:300', 'targetDate' => 'date', 'expiredText' => 'str:160'],
        'html' => ['html' => 'html'],
        // Link-in-bio blocks (Fase 3).
        'spacer' => ['height' => 'int:8:160'],
        'whatsapp' => ['title' => 'str:160', 'text' => 'str:60', 'number' => 'str:20', 'message' => 'text:300', 'style' => 'enum:button|card'],
        'embed' => ['url' => 'url', 'title' => 'str:160'],
    ];

    public const STYLES = [
        'backgroundColor' => 'color', 'textColor' => 'color', 'buttonColor' => 'color', 'buttonTextColor' => 'color', 'accentColor' => 'color',
        'backgroundImage' => 'img', 'paddingY' => 'enum:py-0|py-4|py-8|py-12|py-16|py-20|py-24|py-32', 'textAlign' => 'enum:left|center|right',
        // Fase 7.2: this block's own entrance (empty = page setting).
        'entrance' => 'enum:none|fade|slide|zoom',
    ];

    /** Page look (schema v3, Fase 5). Rendered by ThemeStyle::resolve. */
    public const THEME = [
        'preset' => 'str:30', 'primary' => 'color', 'background' => 'color', 'text' => 'color', 'buttonText' => 'color',
        'headingFont' => 'font', 'bodyFont' => 'font',
        'bg' => ['obj', [
            'type' => 'enum:solid|gradient|image|pattern|animated', 'color' => 'color', 'from' => 'color', 'via' => 'color', 'to' => 'color', 'angle' => 'int:0:360',
            'image' => 'img', 'overlay' => 'num:0:0.9', 'blur' => 'int:0:20', 'position' => 'enum:center|top|bottom',
            'pattern' => 'enum:dots|grid|diagonal|checks|waves|plus', 'patternColor' => 'color', 'patternOpacity' => 'num:0.03:0.5',
            'animation' => 'enum:aurora|blobs|particles|waves',
        ]],
        'button' => ['obj', [
            'shape' => 'enum:square|rounded|pill', 'fill' => 'enum:solid|outline|glass', 'borderWidth' => 'int:1:4',
            'shadow' => 'enum:none|soft|hard', 'hover' => 'enum:none|lift|grow|shine',
        ]],
        // Fase 7.2: blocks appear on page load (fade / slide up / zoom, optional stagger); off = no animation at all.
        'motion' => ['obj', ['entrance' => 'enum:none|fade|slide|zoom', 'speed' => 'enum:slow|normal|fast', 'stagger' => 'bool', 'off' => 'bool']],
    ];

    public const SETTINGS = ['whatsappNumber' => 'str:20', 'whatsappMessage' => 'text:300', 'showFloatingWhatsapp' => 'bool'];

    /**
     * Clean a whole document. Always returns a valid document (bad parts dropped).
     *
     * @return array{schema_version:int, theme:array, settings:array, social:array, blocks:list<array>}
     */
    public static function normalize(mixed $document): array
    {
        $doc = DocumentMigrator::upgrade(is_array($document) ? $document : []);
        $blocks = [];
        $seen = [];
        foreach (array_slice(array_values(is_array($doc['blocks'] ?? null) ? $doc['blocks'] : []), 0, self::MAX_BLOCKS) as $block) {
            $clean = self::normalizeBlock($block);
            if ($clean === null) {
                continue;
            }
            while (isset($seen[$clean['id']])) {
                $clean['id'] = Str::lower(Str::random(10));
            }
            $seen[$clean['id']] = true;
            $blocks[] = $clean;
        }

        return [
            'schema_version' => DocumentMigrator::CURRENT,
            'theme' => self::fields(is_array($doc['theme'] ?? null) ? $doc['theme'] : [], self::THEME),
            'settings' => self::fields(is_array($doc['settings'] ?? null) ? $doc['settings'] : [], self::SETTINGS),
            // Social media panel (Fase 4): a part of the page, not a block.
            'social' => SocialLinks::normalize($doc['social'] ?? null),
            'blocks' => $blocks,
        ];
    }

    /** @return array{id:string,type:string,hidden:bool,content:array,styles:array}|null */
    public static function normalizeBlock(mixed $block): ?array
    {
        if (!is_array($block) || !isset(self::TYPES[(string) ($block['type'] ?? '')])) {
            return null;
        }
        $type = (string) $block['type'];
        $id = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($block['id'] ?? '')) ?: Str::lower(Str::random(10));
        $content = is_array($block['content'] ?? null) ? $block['content'] : [];
        // Older blocks kept styles inside content.
        $styles = is_array($block['styles'] ?? null) ? $block['styles'] : (is_array($content['styles'] ?? null) ? $content['styles'] : []);

        return [
            'id' => Str::limit($id, 40, ''),
            'type' => $type,
            'hidden' => (bool) ($block['hidden'] ?? false),
            'content' => self::fields($content, self::TYPES[$type]),
            'styles' => self::fields($styles, self::STYLES),
        ];
    }

    /** @param array<string, mixed> $spec */
    private static function fields(array $input, array $spec): array
    {
        $out = [];
        foreach ($spec as $key => $rule) {
            if (!array_key_exists($key, $input)) {
                continue;
            }
            $value = self::value($input[$key], $rule);
            if ($value !== null) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    private static function value(mixed $value, string|array $rule): mixed
    {
        if (is_array($rule) && $rule[0] === 'obj') { // ['obj', spec]: nested object
            return is_array($value) ? self::fields($value, $rule[1]) : null;
        }
        if (is_array($rule)) { // ['list', max, itemSpec]
            if (!is_array($value)) {
                return [];
            }
            $items = [];
            foreach (array_slice(array_values($value), 0, (int) $rule[1]) as $item) {
                if (is_array($item)) {
                    $items[] = self::fields($item, $rule[2]);
                }
            }

            return $items;
        }

        $parts = explode(':', $rule, 2);
        $kind = $parts[0];
        $arg = $parts[1] ?? '';

        return match ($kind) {
            'str' => is_scalar($value) ? Str::limit(trim(preg_replace('/\s+/', ' ', (string) $value) ?? ''), (int) $arg, '') : null,
            'text' => is_scalar($value) ? Str::limit(str_replace("\r\n", "\n", (string) $value), (int) $arg, '') : null,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false,
            'int' => is_numeric($value) ? max((int) explode(':', $arg)[0], min((int) explode(':', $arg)[1], (int) $value)) : null,
            'num' => is_numeric($value) ? max((float) explode(':', $arg)[0], min((float) explode(':', $arg)[1], (float) $value)) : null,
            'enum' => in_array((string) $value, explode('|', $arg), true) ? (string) $value : null,
            'color' => is_string($value) && preg_match('/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/', trim($value)) ? strtolower(trim($value)) : null,
            'date' => is_string($value) && strtotime($value) !== false ? date(DATE_ATOM, strtotime($value)) : null,
            'url' => self::url($value, false),
            'img' => self::url($value, true),
            'html' => is_string($value) ? SafeHtml::clean(Str::limit($value, 20000, '')) : null,
            'pid' => is_string($value) && preg_match('/^[a-z0-9]{6,24}$/', $value) ? $value : null,
            'font' => is_string($value) && Fonts::valid($value) ? $value : null,
            'icon' => is_string($value) && ButtonIcons::valid($value) ? $value : null,
            'pids' => is_array($value) ? array_values(array_slice(array_filter($value, fn ($v) => is_string($v) && preg_match('/^[a-z0-9]{6,24}$/', $v)), 0, 50)) : null,
            default => null,
        };
    }

    /** Safe link or image source; '' stays '' (empty field), anything unsafe becomes null. */
    public static function url(mixed $value, bool $image): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '' || $value === '#') {
            return $value;
        }
        if (strlen($value) > 2000) {
            return null;
        }
        if (str_starts_with($value, '/') && !str_starts_with($value, '//')) {
            return $value; // same-origin path (/media/..., /beli/...)
        }
        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
        $allowed = $image ? ['https', 'http'] : ['https', 'http', 'mailto', 'tel'];
        if (!in_array($scheme, $allowed, true)) {
            return null;
        }
        if (in_array($scheme, ['http', 'https'], true) && filter_var($value, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return $value;
    }
}
