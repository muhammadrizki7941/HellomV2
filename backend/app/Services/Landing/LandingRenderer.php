<?php

namespace App\Services\Landing;

use App\Models\LandingProduct;
use App\Models\LandingTrackingSetting;
use App\Models\Organization;
use App\Models\OrganizationLandingPage;
use App\Support\FrontendUrl;
use App\Support\Landing\BlockSchema;
use Illuminate\Support\Collection;

/**
 * Builds the data for the server-rendered public pages (resources/views/landing). Only
 * public product fields are used (LandingProduct::publicPayload) — never delivery data.
 */
final class LandingRenderer
{
    /** Theme presets (same ids as the editor's THEMES); a document's own colors override them. */
    public const PRESETS = [
        'industrial' => ['background' => '#ffffff', 'text' => '#18181b', 'primary' => '#facc15', 'buttonText' => '#000000'],
        'ocean' => ['background' => '#f0f9ff', 'text' => '#0c4a6e', 'primary' => '#0369a1', 'buttonText' => '#ffffff'],
        'forest' => ['background' => '#fcfdf5', 'text' => '#1a2e05', 'primary' => '#4d7c0f', 'buttonText' => '#ffffff'],
        'luxury' => ['background' => '#09090b', 'text' => '#fafafa', 'primary' => '#d4af37', 'buttonText' => '#000000'],
        'minimal' => ['background' => '#fafafa', 'text' => '#18181b', 'primary' => '#18181b', 'buttonText' => '#ffffff'],
        'blush' => ['background' => '#fff7f5', 'text' => '#3f1d24', 'primary' => '#e11d48', 'buttonText' => '#ffffff'],
        'sunset' => ['background' => '#fffbeb', 'text' => '#422006', 'primary' => '#c2410c', 'buttonText' => '#ffffff'],
    ];

    public const FONTS = [
        'sans' => 'system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif',
        'serif' => 'Georgia, Cambria, "Times New Roman", Times, serif',
        'rounded' => 'ui-rounded, "SF Pro Rounded", "Nunito", system-ui, sans-serif',
        'mono' => 'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace',
    ];

    public function __construct(
        private readonly LandingShop $shop,
        private readonly SellerTrust $trust,
    ) {
    }

    /** @return array<string, mixed> view data for landing.page */
    public function page(Organization $organization, OrganizationLandingPage $page, array $document, bool $preview = false): array
    {
        $document = BlockSchema::normalize($document);
        $blocks = array_values(array_filter($document['blocks'], fn ($b) => !$b['hidden']));
        $products = $this->productsFor($organization, $blocks);
        $blocks = array_map(fn ($b) => $this->withProducts($b, $products, $organization), $blocks);
        $first = $this->firstUseful($blocks);

        $url = $this->shop->publicUrl($organization, $page->is_home ? '' : (string) $page->slug);
        $title = $page->seo_title ?: ($first['title'] ?? null) ?: (string) $organization->name;
        $description = $page->seo_description ?: ($first['description'] ?? null) ?: 'Halaman ' . $organization->name . ' di Hellom';
        $image = $page->seo_image ?: ($first['image'] ?? null);

        return $this->base($organization, $document, $preview) + [
            'page' => $page,
            'blocks' => $blocks,
            'meta' => [
                'title' => $title,
                'description' => mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags((string) $description)) ?? ''), 0, 200),
                'image' => $this->absolute($image),
                'url' => $url,
                'type' => 'website',
            ],
            'tracking_page' => ['type' => 'page', 'page_id' => $page->id],
        ];
    }

    /** @return array<string, mixed> view data for landing.product */
    public function product(Organization $organization, LandingProduct $product, ?array $homeDocument): array
    {
        $public = $product->publicPayload();

        return $this->base($organization, BlockSchema::normalize($homeDocument ?? []), false) + [
            'product' => $public,
            'checkoutUrl' => '/beli/' . $product->public_id,
            'meta' => [
                'title' => $product->name . ' — ' . $organization->name,
                'description' => mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags((string) $public['description'])) ?? '') ?: 'Beli ' . $product->name . ' dari ' . $organization->name, 0, 200),
                'image' => $this->absolute($public['image_url']),
                'url' => $this->shop->publicUrl($organization, (string) $product->slug),
                'type' => 'product',
                'price' => (int) $product->price,
            ],
            'tracking_page' => ['type' => 'product', 'product_id' => $product->public_id, 'value' => (int) $product->price, 'name' => $product->name],
        ];
    }

    /** Shared: seller, theme, tracking ids, share links. */
    private function base(Organization $organization, array $document, bool $preview): array
    {
        $theme = $this->theme($document['theme'] ?? []);
        $tracking = $preview ? null : LandingTrackingSetting::query()->find($organization->id);

        return [
            'organization' => $organization,
            'seller' => $this->trust->publicSeller($organization),
            'username' => $organization->landingUsername(),
            'homeUrl' => $this->shop->publicUrl($organization),
            'theme' => $theme,
            'settings' => $document['settings'] ?? [],
            'tracking' => $tracking?->publicIds() ?? [],
            'preview' => $preview,
            'apiBase' => '/api/v1/hellom', // public pages are served from the API origin
        ];
    }

    /** @return array{background:string,text:string,primary:string,buttonText:string,font:string,radius:string,buttonStyle:string,muted:string,surface:string,dark:bool} */
    public function theme(array $theme): array
    {
        $preset = self::PRESETS[$theme['preset'] ?? 'industrial'] ?? self::PRESETS['industrial'];
        $colors = [
            'background' => $theme['background'] ?? $preset['background'],
            'text' => $theme['text'] ?? $preset['text'],
            'primary' => $theme['primary'] ?? $preset['primary'],
            'buttonText' => $theme['buttonText'] ?? $preset['buttonText'],
        ];
        // Unreadable button (custom colors, contrast < 3:1): switch to black or white text.
        if ($this->contrast($colors['primary'], $colors['buttonText']) < 3) {
            $colors['buttonText'] = $this->contrast($colors['primary'], '#000000') >= $this->contrast($colors['primary'], '#ffffff') ? '#000000' : '#ffffff';
        }
        $dark = $this->luminance($colors['background']) < 0.35;

        return $colors + [
            'font' => self::FONTS[$theme['font'] ?? 'sans'] ?? self::FONTS['sans'],
            'radius' => match ($theme['buttonShape'] ?? 'rounded') { 'pill' => '999px', 'square' => '4px', default => '14px' },
            'buttonStyle' => $theme['buttonStyle'] ?? 'solid',
            'muted' => $dark ? 'rgba(255,255,255,.68)' : 'rgba(0,0,0,.6)',
            'surface' => $dark ? 'rgba(255,255,255,.06)' : '#ffffff',
            'dark' => $dark,
        ];
    }

    /** @return Collection<string, LandingProduct> products referenced by product/catalog blocks, keyed by public id */
    private function productsFor(Organization $organization, array $blocks): Collection
    {
        $ids = [];
        $all = false;
        foreach ($blocks as $b) {
            if ($b['type'] === 'product' && !empty($b['content']['productId'])) {
                $ids[] = $b['content']['productId'];
            }
            if ($b['type'] === 'catalog') {
                $all = $all || !empty($b['content']['showAll']) || empty($b['content']['productIds']);
                array_push($ids, ...($b['content']['productIds'] ?? []));
            }
        }
        if (!$all && $ids === []) {
            return collect();
        }

        return LandingProduct::query()->where('organization_id', $organization->id)
            ->when(!$all, fn ($q) => $q->whereIn('public_id', array_unique($ids)))
            ->where('is_active', true)->whereNull('admin_disabled_at')
            ->orderBy('sort_order')->orderByDesc('id')->limit(100)->get()->keyBy('public_id');
    }

    private function withProducts(array $block, Collection $products, Organization $organization): array
    {
        $shape = fn (LandingProduct $p) => $p->publicPayload() + [
            'url' => $this->shop->publicUrl($organization, (string) $p->slug),
            'checkout_url' => '/beli/' . $p->public_id,
        ];
        if ($block['type'] === 'product') {
            $p = $products[$block['content']['productId'] ?? ''] ?? null;
            $block['product'] = $p ? $shape($p) : null;
        }
        if ($block['type'] === 'catalog') {
            $list = !empty($block['content']['productIds']) && empty($block['content']['showAll'])
                ? collect($block['content']['productIds'])->map(fn ($id) => $products[$id] ?? null)->filter()
                : $products->values();
            $block['products'] = $list->map($shape)->filter(fn ($p) => $p['available'] || !$p['in_stock'])->values()->all();
        }
        if ($block['type'] === 'video') {
            $block['youtube_id'] = $this->youtubeId((string) ($block['content']['videoUrl'] ?? ''));
        }

        return $block;
    }

    /** First title/description/image on the page, for meta tags. */
    private function firstUseful(array $blocks): array
    {
        $out = [];
        foreach ($blocks as $b) {
            $c = $b['content'];
            $out['title'] ??= match ($b['type']) { 'profile' => $c['name'] ?? null, 'hero', 'banner' => $c['title'] ?? null, default => null };
            $out['description'] ??= match ($b['type']) { 'profile' => $c['bio'] ?? null, 'hero', 'banner' => $c['subtitle'] ?? null, default => null };
            $out['image'] ??= match ($b['type']) {
                'profile' => ($c['coverUrl'] ?? null) ?: ($c['avatarUrl'] ?? null),
                'hero', 'banner', 'image' => $c['imageUrl'] ?? null,
                'product' => $b['product']['image_url'] ?? null,
                default => null,
            } ?: null;
        }

        return $out;
    }

    public function youtubeId(string $url): ?string
    {
        if (preg_match('~(?:youtube\.com/(?:watch\?v=|embed/|shorts/|live/)|youtu\.be/)([A-Za-z0-9_-]{11})~', $url, $m)) {
            return $m[1];
        }

        return null;
    }

    private function absolute(?string $url): ?string
    {
        if (!$url) {
            return null;
        }

        return str_starts_with($url, '/') ? FrontendUrl::to($url) : $url;
    }

    /** WCAG contrast ratio between two hex colors (1–21). */
    private function contrast(string $a, string $b): float
    {
        $rel = function (string $hex): float {
            $hex = ltrim($hex, '#');
            if (strlen($hex) === 3) {
                $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
            }
            [$r, $g, $b] = array_map(function ($c) {
                $v = hexdec($c) / 255;

                return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
            }, str_split(substr($hex . '000000', 0, 6), 2));

            return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
        };
        [$x, $y] = [$rel($a), $rel($b)];

        return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
    }

    private function luminance(string $hex): float
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        [$r, $g, $b] = array_map(fn ($c) => hexdec($c) / 255, str_split(substr($hex . '000000', 0, 6), 2));

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }
}
