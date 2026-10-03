<?php

namespace App\Services\Landing;

use App\Models\LandingProduct;
use App\Models\LandingTrackingSetting;
use App\Models\Organization;
use App\Models\OrganizationLandingPage;
use App\Support\FrontendUrl;
use App\Support\Landing\BlockSchema;
use App\Support\Landing\Embed;
use App\Support\Landing\SocialLinks;
use App\Support\Landing\ThemeStyle;
use Illuminate\Support\Collection;

/**
 * Builds the data for the server-rendered public pages (resources/views/landing). Only
 * public product fields are used (LandingProduct::publicPayload) — never delivery data.
 */
final class LandingRenderer
{
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
            'social' => $document['social'] ?? SocialLinks::normalize(null),
            'tracking' => $tracking?->publicIds() ?? [],
            'preview' => $preview,
            'apiBase' => '/api/v1/hellom', // public pages are served from the API origin
        ];
    }

    /** Page look for the views (colors, background, fonts, button variables): ThemeStyle (Fase 5). */
    public function theme(array $theme): array
    {
        return ThemeStyle::resolve($theme);
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
            // TikTok links in the video block play as the official TikTok embed.
            $embed = $block['youtube_id'] ? null : Embed::resolve((string) ($block['content']['videoUrl'] ?? ''));
            $block['embed'] = $embed && $embed['provider'] === 'tiktok' ? $embed : null;
        }
        if ($block['type'] === 'embed') {
            $block['embed'] = Embed::resolve((string) ($block['content']['url'] ?? ''));
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
}
