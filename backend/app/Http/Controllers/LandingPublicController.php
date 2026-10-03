<?php

namespace App\Http\Controllers;

use App\Models\LandingDomain;
use App\Models\LandingProduct;
use App\Models\Organization;
use App\Models\OrganizationLandingPage;
use App\Services\Landing\LandingDocumentService;
use App\Services\Landing\LandingRenderer;
use App\Services\Landing\LandingShop;
use App\Support\Landing\BlockSchema;
use App\Support\Landing\OgImage;
use App\Support\Landing\PageSecurity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server-rendered Hellom Page (Fase 4, audit PF-01/PF-02): hellomspace.com/{username} and
 * /{username}/{page-or-product}. Plain HTML with inline CSS and a few KB of JS, cached per
 * shop (the cache version changes on publish, product change and settings). Anything that
 * is not a shop falls through to the React app (SPA shell).
 */
class LandingPublicController extends Controller
{
    public function __construct(
        private readonly LandingShop $shop,
        private readonly LandingDocumentService $documents,
        private readonly LandingRenderer $renderer,
    ) {
    }

    public function show(Request $request, string $username, ?string $slug = null): Response
    {
        $organization = $this->shop->findByUsername($username);
        if (!$organization) {
            // Old address after a username change → permanent redirect.
            $renamed = $this->shop->isReservedUsername($username) ? null : $this->shop->redirectTarget($username);
            if ($renamed) {
                return redirect('/' . $renamed->landingUsername() . ($slug ? '/' . $slug : ''), 301);
            }

            return SpaController::shell();
        }

        return $this->render($request, $organization, $slug);
    }

    /**
     * Generated share card of a published page (Fase 6): /og/{username}/{slug|_}-{hash}. Made once per
     * content hash and kept on the local disk; an old hash redirects to the current card.
     */
    public function ogImage(string $username, string $key): Response
    {
        $organization = $this->shop->findByUsername($username);
        if (!$organization || $organization->landing_suspended_at !== null || !OgImage::supported() || !preg_match('/^([a-z0-9-]+|_)-([a-f0-9]{12})$/', $key, $m)) {
            abort(404);
        }
        $live = $this->shop->livePages($organization);
        $page = $m[1] === '_' ? ($live->firstWhere('is_home', true) ?? $live->first()) : $live->firstWhere('slug', $m[1]);
        if (!$page) {
            abort(404);
        }
        $document = BlockSchema::normalize($this->documents->published($page) ?? []);
        $current = $this->renderer->ogCard($organization, $page, $document, $this->shop->publicUrl($organization, $page->is_home ? '' : (string) $page->slug));
        if ($current !== '/og/' . $username . '/' . $key) {
            return redirect((string) $current, 302);
        }

        $disk = Storage::disk('local');
        $dir = 'og/' . $organization->id;
        $file = $dir . '/' . $page->id . '-' . $m[2] . '.jpg';
        if (!$disk->exists($file)) {
            foreach ($disk->files($dir) as $old) { // one card per page
                if (str_starts_with(basename($old), $page->id . '-')) {
                    $disk->delete($old);
                }
            }
            $disk->put($file, OgImage::render(OgImage::card($organization, $document, (string) preg_replace('#^https?://(www\.)?#', '', $this->shop->publicUrl($organization, $page->is_home ? '' : (string) $page->slug)))));
        }

        return response()->file($disk->path($file), ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'public, max-age=31536000, immutable']);
    }

    /** Editor preview of the draft (signed link, not cached, no tracking). */
    public function preview(Request $request, int $page): Response
    {
        $landingPage = OrganizationLandingPage::query()->with('organization')->findOrFail($page);
        $document = $this->documents->draft($landingPage)['document'];

        return PageSecurity::apply(response()->view('landing.page', $this->renderer->page($landingPage->organization, $landingPage, $document, true))
            ->header('Cache-Control', 'no-store')
            ->header('X-Robots-Tag', 'noindex'));
    }

    /** A verified custom domain (landing_domains) shows the page it is linked to at "/". */
    public function forCustomDomain(Request $request): ?Response
    {
        $appHost = parse_url((string) config('app.frontend_url'), PHP_URL_HOST);
        $host = strtolower($request->getHost());
        if (!$request->is('/') || $host === $appHost || $host === parse_url((string) config('app.url'), PHP_URL_HOST)) {
            return null;
        }
        $domain = LandingDomain::query()->where('domain', $host)->where('status', 'verified')->with('landingPage.organization')->first();
        $page = $domain?->landingPage;
        if (!$page || !$page->organization || $page->status !== 'published') {
            return null;
        }

        return $this->render($request, $page->organization, $page->is_home ? null : $page->slug);
    }

    private function render(Request $request, Organization $organization, ?string $slug): Response
    {
        if ($organization->landing_suspended_at !== null) {
            return $this->status('suspended', $organization, 410);
        }

        $version = $this->shop->cacheVersion((int) $organization->id);
        // A deploy that changes the templates must not serve old cached HTML.
        $templates = max(array_map('filemtime', glob(resource_path('views/landing/*')) ?: [0]));
        $key = "landing:html:{$organization->id}:{$version}:{$templates}:" . ($slug ?? '_home');
        $cached = Cache::get($key);
        if (!is_array($cached)) {
            $cached = $this->build($organization, $slug);
            Cache::put($key, $cached, now()->addDay());
        }
        [$html, $status] = $cached;

        $etag = '"' . substr(sha1($html), 0, 20) . '"';
        if ($status === 200 && $request->headers->get('If-None-Match') === $etag) {
            return PageSecurity::apply(response('', 304)->header('ETag', $etag));
        }
        $seconds = (int) config('landing.public_cache_seconds', 60);

        return PageSecurity::apply(response($html, $status)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', $status === 200 ? "public, max-age={$seconds}, stale-while-revalidate=300" : 'no-store')
            ->header('ETag', $etag));
    }

    /** @return array{0: string, 1: int} */
    private function build(Organization $organization, ?string $slug): array
    {
        $live = $this->shop->livePages($organization);
        $home = $live->firstWhere('is_home', true) ?? $live->first();

        if ($slug === null) {
            if (!$home) {
                return [$this->statusHtml('empty', $organization), 404];
            }

            return [view('landing.page', $this->renderer->page($organization, $home, $this->documents->published($home) ?? []))->render(), 200];
        }

        $product = LandingProduct::query()->where('organization_id', $organization->id)->where('slug', $slug)
            ->where('is_active', true)->whereNull('admin_disabled_at')->first();
        if ($product) {
            return [view('landing.product', $this->renderer->product($organization, $product, $home ? $this->documents->published($home) : null))->render(), 200];
        }
        $page = $live->firstWhere('slug', $slug);
        if ($page) {
            return [view('landing.page', $this->renderer->page($organization, $page, $this->documents->published($page) ?? []))->render(), 200];
        }

        return [$this->statusHtml('not_found', $organization), 404];
    }

    private function status(string $kind, Organization $organization, int $code): Response
    {
        return PageSecurity::apply(response($this->statusHtml($kind, $organization), $code)->header('Cache-Control', 'no-store'));
    }

    private function statusHtml(string $kind, Organization $organization): string
    {
        return view('landing.status', [
            'kind' => $kind,
            'organization' => $organization,
            'homeUrl' => $this->shop->publicUrl($organization),
            'theme' => $this->renderer->theme([]),
        ])->render();
    }
}
