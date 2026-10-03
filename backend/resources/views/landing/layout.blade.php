@php
    /** @var array $theme */
    $css = \Illuminate\Support\Facades\Cache::rememberForever('landing:css:' . filemtime(resource_path('views/landing/styles.css')), fn () => preg_replace('/\s*\n\s*/', '', file_get_contents(resource_path('views/landing/styles.css'))));
    $js = \Illuminate\Support\Facades\Cache::rememberForever('landing:js:' . filemtime(resource_path('views/landing/script.js')), fn () => file_get_contents(resource_path('views/landing/script.js')));
    $config = [
        'api' => $apiBase,
        'username' => $username,
        'orgSlug' => $organization->slug,
        'pageId' => $tracking_page['page_id'] ?? null,
        'productId' => $tracking_page['product_id'] ?? null,
        'productName' => $tracking_page['name'] ?? null,
        'value' => $tracking_page['value'] ?? null,
        'shareUrl' => $meta['url'],
        'tracking' => $tracking,
        'preview' => $preview,
    ];
    $waNumber = preg_replace('/\D/', '', (string) ($settings['whatsappNumber'] ?? ''));
    $waNumber = $waNumber !== '' && str_starts_with($waNumber, '0') ? '62' . substr($waNumber, 1) : $waNumber;
@endphp
<!doctype html>
<html lang="id" style="{{ $theme['bgCss'] }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>{{ $meta['title'] }}</title>
<meta name="description" content="{{ $meta['description'] }}">
<link rel="canonical" href="{{ $meta['url'] }}">
@if ($preview)<meta name="robots" content="noindex,nofollow">@endif
<meta property="og:site_name" content="{{ $organization->name }}">
<meta property="og:locale" content="id_ID">
<meta property="og:type" content="{{ $meta['type'] === 'product' ? 'product' : 'website' }}">
<meta property="og:title" content="{{ $meta['title'] }}">
<meta property="og:description" content="{{ $meta['description'] }}">
<meta property="og:url" content="{{ $meta['url'] }}">
@if (!empty($meta['image']))
<meta property="og:image" content="{{ $meta['image'] }}">
<meta name="twitter:image" content="{{ $meta['image'] }}">
@endif
<meta name="twitter:card" content="{{ !empty($meta['image']) ? 'summary_large_image' : 'summary' }}">
<meta name="twitter:title" content="{{ $meta['title'] }}">
<meta name="twitter:description" content="{{ $meta['description'] }}">
@if (isset($meta['price']))
<meta property="product:price:amount" content="{{ $meta['price'] }}">
<meta property="product:price:currency" content="IDR">
@endif
<meta name="theme-color" content="{{ $theme['effectiveBackground'] }}">
@if (!empty($theme['fontPreload']))<link rel="preload" href="{{ $theme['fontPreload'] }}" as="font" type="font/woff2" crossorigin>@endif
<link rel="icon" href="/favicon.ico">
@if (!empty($tracking['meta_pixel_id']))<link rel="preconnect" href="https://connect.facebook.net" crossorigin>@endif
<style>{!! $theme['fontFaces'] !!}:root{--bg:{{ $theme['effectiveBackground'] }};--fg:{{ $theme['text'] }};--primary:{{ $theme['primary'] }};--btn-text:{{ $theme['buttonText'] }};--muted:{{ $theme['muted'] }};--surface:{{ $theme['surface'] }};--font:{!! $theme['font'] !!};--font-h:{!! $theme['headingFont'] !!};{{ $theme['buttonVars'] }}}{!! $css !!}</style>
</head>
<body class="{{ trim((($theme['dark'] ?? false) ? 'dark ' : '') . ($theme['bgClass'] ?? '') . ' hv-' . ($theme['hover'] ?? 'none')) }}">
{!! $theme['bgLayers'] ?? '' !!}
@if ($preview && empty($editor))<div class="preview-bar">Pratinjau draft — belum tayang ke publik</div>@endif
<main>
@yield('content')
</main>
<footer class="foot">
    @if ($seller['verified'])
        <p><span class="badge">✔ Penjual Terverifikasi</span></p>
    @endif
    <nav>
        <a href="/cek-pesanan">Cek pesanan</a>
        <a href="/kebijakan/refund">Kebijakan refund</a>
        <button type="button" data-report>⚑ Laporkan</button>
    </nav>
    <p class="muted small">Dibuat dengan <a href="{{ \App\Support\FrontendUrl::to('/') }}" style="padding:0;min-height:0;display:inline">Hellom</a></p>
</footer>

<div class="share">
    @if (!empty($settings['showFloatingWhatsapp']) && $waNumber !== '')
        <a class="wa" href="https://wa.me/{{ $waNumber }}?text={{ rawurlencode((string) ($settings['whatsappMessage'] ?? 'Halo')) }}" target="_blank" rel="noopener" aria-label="Chat WhatsApp" data-item="wa-float" data-track="click" data-label="WhatsApp mengambang">
            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M20.5 3.5A11 11 0 0 0 3.3 17.2L2 22l4.9-1.3A11 11 0 0 0 20.5 3.5zM12 20a8.9 8.9 0 0 1-4.6-1.3l-.3-.2-2.9.8.8-2.8-.2-.3A9 9 0 1 1 12 20zm4.9-6.7c-.3-.1-1.6-.8-1.8-.9-.3-.1-.4-.1-.6.1l-.8 1c-.2.2-.3.2-.6.1a7.4 7.4 0 0 1-3.6-3.2c-.3-.5.3-.4.8-1.4.1-.2 0-.3 0-.4l-.8-2c-.2-.5-.4-.4-.6-.4h-.5a1 1 0 0 0-.7.3 3 3 0 0 0-.9 2.2 5.2 5.2 0 0 0 1.1 2.8 11.9 11.9 0 0 0 4.6 4c1.7.7 2.4.8 3.2.7.5-.1 1.6-.7 1.8-1.3.2-.6.2-1.2.2-1.3-.1-.2-.3-.2-.6-.3z"/></svg>
        </a>
    @endif
    <button type="button" data-share aria-label="Bagikan halaman">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.6 13.5l6.8 4M15.4 6.5l-6.8 4"/></svg>
    </button>
</div>

<dialog id="hl-share" aria-label="Bagikan">
    <button class="x" type="button" data-close aria-label="Tutup">×</button>
    <h3>Bagikan halaman</h3>
    <div class="sheet-actions">
        <button class="btn block" type="button" data-copy>Salin link</button>
        <a class="btn block outline" href="https://wa.me/?text={{ rawurlencode($meta['title'] . ' ' . $meta['url']) }}" target="_blank" rel="noopener">Bagikan ke WhatsApp</a>
        <button class="btn block outline" type="button" data-qr>Unduh QR code</button>
        <button class="btn block ghost" type="button" data-native-share>Lainnya…</button>
    </div>
</dialog>

<dialog id="hl-report" aria-label="Laporkan halaman">
    <button class="x" type="button" data-close aria-label="Tutup">×</button>
    <h3>Laporkan halaman ini</h3>
    <form id="hl-report-form">
        @foreach (\App\Models\LandingReport::REASONS as $key => $label)
            <label class="opt"><input type="radio" name="reason" value="{{ $key }}" @checked($loop->first)> {{ $label }}</label>
        @endforeach
        <label class="field">Ceritakan singkat (opsional)<textarea name="description" rows="3" maxlength="2000"></textarea></label>
        <label class="field">Email kamu (opsional)<input type="email" name="email" maxlength="150"></label>
        <button class="btn block" type="submit">Kirim laporan</button>
        <p class="small muted">Lihat <a href="/kebijakan/produk-terlarang">daftar produk terlarang</a>.</p>
    </form>
</dialog>

@if (!empty($tracking) && !$preview)
<div id="hl-consent" class="consent" role="dialog" aria-label="Persetujuan cookie" hidden>
    <p>Toko ini memakai cookie & piksel iklan (Meta, Google, TikTok) untuk mengukur iklan. Boleh?</p>
    <div class="consent-actions">
        <button type="button" class="btn ghost" data-consent="denied">Tolak</button>
        <button type="button" class="btn" data-consent="granted">Terima</button>
    </div>
    <a href="/kebijakan/privasi" class="small">Kebijakan privasi</a>
</div>
@endif
<div id="hl-toast" class="toast" role="status"></div>
<script type="application/json" id="hl-data">{!! json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
<script>{!! $js !!}</script>
@if (!empty($editor))
{{-- Editor phone preview (sandboxed iframe in the dashboard): tap to select, highlight. --}}
<style>[data-hl-block]>*{cursor:pointer}[data-hl-block]>*:hover{outline:1px dashed rgba(250,204,21,.9);outline-offset:-1px}.hl-sel{outline:2px solid #facc15!important;outline-offset:-2px}</style>
<script>{!! file_get_contents(resource_path('views/landing/editor.js')) !!}</script>
@endif
</body>
</html>
