@php
    $c = $b['content'];
    $s = $b['styles'] ?? [];
    $cssUrl = fn ($u) => preg_replace('/[^A-Za-z0-9:\/._~%?&=#+-]/', '', (string) $u);
    $style = collect([
        !empty($s['backgroundColor']) ? 'background-color:' . $s['backgroundColor'] : null,
        !empty($s['backgroundImage']) ? 'background-image:url(\'' . $cssUrl($s['backgroundImage']) . '\');background-size:cover;background-position:center' : null,
        !empty($s['textColor']) ? 'color:' . $s['textColor'] : null,
        !empty($s['buttonColor']) ? '--primary:' . $s['buttonColor'] : null,
        !empty($s['buttonTextColor']) ? '--btn-fg:' . $s['buttonTextColor'] : null,
    ])->filter()->implode(';');
    $cls = trim('blk ' . ($s['paddingY'] ?? '') . ' ' . ($s['textAlign'] ?? ''));
    $wa = function (?string $number, ?string $message) {
        $n = preg_replace('/\D/', '', (string) $number);
        $n = $n !== '' && str_starts_with($n, '0') ? '62' . substr($n, 1) : $n;
        return $n === '' ? null : 'https://wa.me/' . $n . ($message ? '?text=' . rawurlencode($message) : '');
    };
    $href = fn (?string $u) => ($u === null || $u === '' || $u === '#') ? null : $u;
    $rp = fn ($v) => 'Rp ' . number_format((int) $v, 0, ',', '.');
    $imgAttrs = fn (bool $eager) => $eager ? 'fetchpriority="high"' : 'loading="lazy" decoding="async"';
    // Smaller WebP copies (480w/960w) when the upload has them (PF-04).
    $srcset = fn (?string $url, string $sizes) => ($set = \App\Support\ImageOptimizer::srcset($url)) ? 'srcset="' . e($set) . '" sizes="' . e($sizes) . '"' : '';
    $full = '(max-width: 720px) 100vw, 680px';
    // Theme button look comes from CSS variables (ThemeStyle::buttonVars on :root).
    $btnClass = 'btn';
@endphp
@switch($b['type'])
    @case('profile')
        @php
            $coverYt = !empty($c['coverVideo']) ? \App\Support\Landing\Embed::resolve($c['coverVideo']) : null;
            $coverYt = $coverYt && $coverYt['provider'] === 'youtube' ? $coverYt : null;
            $coverImg = !empty($c['coverUrl']) ? $c['coverUrl'] : ($coverYt ? 'https://i.ytimg.com/vi/' . $coverYt['id'] . '/hqdefault.jpg' : null);
            $ratio = ['wide' => '16/9', 'banner' => '3/1', 'square' => '1/1'][$c['coverRatio'] ?? 'banner'];
            $focus = (int) ($c['coverFocusX'] ?? 50) . '% ' . (int) ($c['coverFocusY'] ?? 50) . '%';
        @endphp
        <section class="profile {{ $cls }}{{ $coverImg ? ' has-cover' : '' }}{{ ($c['avatarPosition'] ?? 'overlap') === 'below' ? ' av-below' : '' }}" @if($style) style="{{ $style }}" @endif>
            @if ($coverImg)
                <div class="cover" style="aspect-ratio:{{ $ratio }}">
                    <img src="{{ $coverImg }}" @if(!empty($c['coverUrl'])) {!! $srcset($c['coverUrl'], '(max-width: 720px) 100vw, 720px') !!} @endif alt="" style="object-position:{{ $focus }}" fetchpriority="high">
                    @if ($coverYt)<div class="cover-yt" data-cover-yt="{{ $coverYt['id'] }}" @if(!empty($coverYt['start'])) data-start="{{ (int) $coverYt['start'] }}" @endif></div>@endif
                    @if (!empty($c['coverFade']))<div class="cover-fade" aria-hidden="true"></div>@endif
                </div>
            @endif
            <div class="wrap center">
                @if (!empty($c['avatarUrl']))
                    <img class="avatar" src="{{ $c['avatarUrl'] }}" {!! $srcset($c['avatarUrl'], '104px') !!} alt="{{ $c['name'] ?? $organization->name }}" width="104" height="104" {!! $imgAttrs($first) !!}>
                @else
                    <div class="avatar initial" aria-hidden="true">{{ mb_strtoupper(mb_substr($c['name'] ?? $organization->name, 0, 1)) }}</div>
                @endif
                <h1>{{ $c['name'] ?? $organization->name }}</h1>
                @if (($c['showVerified'] ?? true) && $seller['verified'])<p><span class="badge">✔ Penjual Terverifikasi</span></p>@endif
                @if (!empty($c['bio']))<p class="muted" style="white-space:pre-line">{{ $c['bio'] }}</p>@endif
            </div>
        </section>
        @break

    @case('hero')
        <section class="{{ $cls ?: 'blk' }} {{ empty($s['textAlign']) ? 'center' : '' }}" @if($style) style="{{ $style }}" @endif>
            <div class="wrap">
                @if (!empty($c['imageUrl']))<img class="hero-img" src="{{ $c['imageUrl'] }}" {!! $srcset($c['imageUrl'], $full) !!} alt="" {!! $imgAttrs($first) !!}>@endif
                @if (!empty($c['title']))<h1>{{ $c['title'] }}</h1>@endif
                @if (!empty($c['subtitle']))<p class="muted" style="font-size:1.1rem;white-space:pre-line">{{ $c['subtitle'] }}</p>@endif
                @if (($c['showButton'] ?? true) && !empty($c['buttonText']))
                    <a class="{{ $btnClass }}" href="{{ $href($c['linkUrl'] ?? null) ?? '#produk' }}" data-track="click" data-label="{{ $c['buttonText'] }}">{{ $c['buttonText'] }}</a>
                @endif
            </div>
        </section>
        @break

    @case('features')
        <section class="{{ $cls }}" @if($style) style="{{ $style }}" @endif>
            <div class="wrap wide">
                @if (!empty($c['title']))<h2 class="center">{{ $c['title'] }}</h2>@endif
                <div class="grid g3">
                    @foreach ($c['items'] ?? [] as $item)
                        <div class="card feature-card"><h3>{{ $item['title'] ?? '' }}</h3><p class="muted" style="margin:0">{{ $item['desc'] ?? '' }}</p></div>
                    @endforeach
                </div>
            </div>
        </section>
        @break

    @case('cta')
        @php $ctaHref = ($c['actionType'] ?? 'whatsapp') === 'whatsapp' ? $wa($c['whatsappNumber'] ?? ($settings['whatsappNumber'] ?? null), $c['whatsappMessage'] ?? ($settings['whatsappMessage'] ?? null)) : $href($c['linkUrl'] ?? null); @endphp
        <section class="{{ $cls }} center" @if($style) style="{{ $style }}" @endif>
            <div class="wrap">
                @if (!empty($c['title']))<h2>{{ $c['title'] }}</h2>@endif
                @if (!empty($c['subtitle']))<p class="muted">{{ $c['subtitle'] }}</p>@endif
                @if ($ctaHref && !empty($c['buttonText']))<a class="{{ $btnClass }}" href="{{ $ctaHref }}" target="_blank" rel="noopener" data-track="click" data-label="{{ $c['buttonText'] }}">{{ $c['buttonText'] }}</a>@endif
            </div>
        </section>
        @break

    @case('content')
    @case('text')
        <section class="{{ $cls }}" @if($style) style="{{ $style }}" @endif>
            <div class="wrap">
                @if (!empty($c['title']))<h2>{{ $c['title'] }}</h2>@endif
                <div style="white-space:pre-line">{{ $c['body'] ?? '' }}</div>
            </div>
        </section>
        @break

    @case('banner')
        <section class="blk banner center" style="background-image:url('{{ $cssUrl($c['imageUrl'] ?? '') }}');color:{{ $c['textColor'] ?? '#ffffff' }}">
            <div class="shade" style="opacity:{{ $c['overlayOpacity'] ?? 0.5 }}"></div>
            <div class="wrap">
                @if (!empty($c['title']))<h2>{{ $c['title'] }}</h2>@endif
                @if (!empty($c['subtitle']))<p style="margin:0">{{ $c['subtitle'] }}</p>@endif
            </div>
        </section>
        @break

    @case('image')
    @case('gif')
        @php $src = $b['type'] === 'gif' ? ($c['gifUrl'] ?? '') : ($c['imageUrl'] ?? ''); @endphp
        @if ($src)
            <section class="{{ $cls }} center" @if($style) style="{{ $style }}" @endif>
                <div class="wrap">
                    @if ($href($c['linkUrl'] ?? null))<a href="{{ $c['linkUrl'] }}" target="_blank" rel="noopener" data-track="click" data-label="gambar">@endif
                    <img src="{{ $src }}" {!! $srcset($src, $full) !!} alt="{{ $c['alt'] ?? ($c['caption'] ?? '') }}" style="border-radius:16px;margin:0 auto" {!! $imgAttrs($first) !!}>
                    @if ($href($c['linkUrl'] ?? null))</a>@endif
                    @if (!empty($c['caption']))<p class="muted small" style="margin-top:8px">{{ $c['caption'] }}</p>@endif
                </div>
            </section>
        @endif
        @break

    @case('video')
        @php $e = $b['embed'] ?? null; $square = ($c['corners'] ?? 'rounded') === 'square'; @endphp
        @if ($e)
            <section class="{{ $cls }}" @if($style) style="{{ $style }}" @endif>
                <div class="wrap">
                    @if (!empty($c['title']) && empty($c['hideTitle']))<h2 class="center">{{ $c['title'] }}</h2>@endif
                    @if ($e['provider'] === 'youtube')
                        @include('landing.youtube', ['e' => $e, 'title' => $c['title'] ?? '', 'autoplay' => !empty($c['autoplay']), 'square' => $square])
                    @else
                        <div class="embed{{ $e['vertical'] && !$e['height'] ? ' vertical' : '' }}{{ $square ? ' square' : '' }}" @if($e['height']) style="height:{{ $e['height'] }}px" @endif><iframe src="{{ $e['src'] }}" title="{{ $c['title'] ?? ('Video ' . $e['label']) }}" loading="lazy" allow="encrypted-media; fullscreen; picture-in-picture" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe></div>
                    @endif
                </div>
            </section>
        @endif
        @break

    @case('product')
        @php $p = $b['product'] ?? null; @endphp
        @if ($p || !empty($c['name']))
        <section id="produk" class="{{ $cls }}" @if($style) style="{{ $style }}" @endif>
            <div class="wrap wide">
                @if ($p)
                    <div class="card featured">
                        @if ($p['image_url'])<img class="prod-img" src="{{ $p['image_url'] }}" {!! $srcset($p['image_url'], $full) !!} alt="{{ $p['name'] }}" {!! $imgAttrs($first) !!}>@endif
                        <div class="prod-body">
                            <p class="muted small" style="margin:0">{{ $p['type_label'] }}</p>
                            <h2 style="margin:0"><a href="{{ $p['url'] }}" style="text-decoration:none">{{ $p['name'] }}</a></h2>
                            <p class="price" style="margin:0">{{ $rp($p['price']) }}@if ($p['compare_at_price'])<span class="strike">{{ $rp($p['compare_at_price']) }}</span>@endif</p>
                            @if ($p['stock_left'])<p class="stock" style="margin:0">Sisa {{ $p['stock_left'] }}</p>@endif
                            @if ($p['description'])<p class="muted">{{ \Illuminate\Support\Str::limit(trim(html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '</li>'], ' ', $p['description'])))), 220) }}</p>@endif
                            @if ($p['available'])
                                <a class="{{ $btnClass }} block" href="{{ $p['checkout_url'] }}" data-track="buy" data-product="{{ $p['id'] }}" data-value="{{ $p['price'] }}" data-label="{{ $p['name'] }}">{{ $c['buttonText'] ?? 'Beli Sekarang' }}</a>
                            @else
                                <span class="btn block" aria-disabled="true">{{ $p['in_stock'] ? 'Belum tersedia' : 'Stok habis' }}</span>
                            @endif
                        </div>
                    </div>
                @elseif (!empty($c['name']))
                    {{-- Old block not linked to a product yet: shown, but without online checkout. --}}
                    <div class="card featured">
                        @if (!empty($c['imageUrl']))<img class="prod-img" src="{{ $c['imageUrl'] }}" {!! $srcset($c['imageUrl'], $full) !!} alt="{{ $c['name'] }}" loading="lazy">@endif
                        <div class="prod-body">
                            <h2 style="margin:0">{{ $c['name'] }}</h2>
                            @if (!empty($c['price']))<p class="price" style="margin:0">{{ $c['price'] }}</p>@endif
                            @if (!empty($c['description']))<p class="muted">{{ $c['description'] }}</p>@endif
                            @php $ask = $wa($settings['whatsappNumber'] ?? null, 'Halo, saya mau pesan ' . $c['name']); @endphp
                            @if ($ask)<a class="{{ $btnClass }} block" href="{{ $ask }}" target="_blank" rel="noopener" data-track="click" data-label="{{ $c['name'] }}">Pesan via WhatsApp</a>@endif
                        </div>
                    </div>
                @endif
            </div>
        </section>
        @endif
        @break

    @case('catalog')
        @if (!empty($b['products']))
            <section id="produk" class="{{ $cls }}" @if($style) style="{{ $style }}" @endif>
                <div class="wrap wide">
                    @if (!empty($c['title']))<h2 class="center">{{ $c['title'] }}</h2>@endif
                    <div class="grid g2 {{ ($c['columns'] ?? 2) >= 3 ? 'g3' : '' }}">
                        @foreach ($b['products'] as $p)
                            <div class="card prod">
                                <a href="{{ $p['url'] }}" style="text-decoration:none;color:inherit" aria-label="{{ $p['name'] }}" tabindex="-1">
                                    @if ($p['image_url'])<img class="prod-img" src="{{ $p['image_url'] }}" {!! $srcset($p['image_url'], ($c['columns'] ?? 2) >= 3 ? '(max-width: 600px) 50vw, 300px' : '(max-width: 600px) 50vw, 440px') !!} alt="{{ $p['name'] }}" loading="lazy" decoding="async">@else<div class="prod-img"></div>@endif
                                </a>
                                <div class="prod-body">
                                    <a href="{{ $p['url'] }}" class="prod-name" style="text-decoration:none">{{ $p['name'] }}</a>
                                    <span class="price">{{ $rp($p['price']) }}@if ($p['compare_at_price'])<span class="strike">{{ $rp($p['compare_at_price']) }}</span>@endif</span>
                                    @if ($p['available'])
                                        <a class="{{ $btnClass }} block" style="margin-top:auto;min-height:44px;padding:8px 12px" href="{{ $p['checkout_url'] }}" data-track="buy" data-product="{{ $p['id'] }}" data-value="{{ $p['price'] }}" data-label="{{ $p['name'] }}">{{ $c['buttonText'] ?? 'Beli' }}</a>
                                    @else
                                        <span class="btn block" aria-disabled="true" style="margin-top:auto;min-height:44px;padding:8px 12px">{{ $p['in_stock'] ? 'Belum tersedia' : 'Stok habis' }}</span>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </section>
        @endif
        @break

    @case('pdf')
        @if (($c['accessType'] ?? 'free') === 'free' && $href($c['fileUrl'] ?? null))
            <section class="{{ $cls }}" @if($style) style="{{ $style }}" @endif>
                <div class="wrap"><div class="card" style="padding:20px">
                    <h3>{{ $c['title'] ?? 'Unduh file' }}</h3>
                    @if (!empty($c['description']))<p class="muted">{{ $c['description'] }}</p>@endif
                    <a class="{{ $btnClass }}" href="{{ $c['fileUrl'] }}" target="_blank" rel="noopener" data-track="click" data-label="{{ $c['title'] ?? 'pdf' }}">{{ $c['buttonText'] ?? 'Download Gratis' }}</a>
                </div></div>
            </section>
        @endif
        @break

    @case('social')
        @php
            $links = collect(['instagram' => 'Instagram', 'tiktok' => 'TikTok', 'youtube' => 'YouTube', 'facebook' => 'Facebook', 'threads' => 'Threads', 'x' => 'X', 'linkedin' => 'LinkedIn'])
                ->filter(fn ($l, $k) => $href($c[$k] ?? null));
            $waLink = $wa($c['whatsapp'] ?? null, null);
        @endphp
        @if ($links->isNotEmpty() || $waLink)
            <section class="{{ $cls }} center" @if($style) style="{{ $style }}" @endif>
                <div class="wrap">
                    @if (!empty($c['title']))<h3>{{ $c['title'] }}</h3>@endif
                    <div class="socials">
                        @if ($waLink)<a class="soc" href="{{ $waLink }}" target="_blank" rel="noopener" data-track="click" data-label="WhatsApp">WhatsApp</a>@endif
                        @foreach ($links as $key => $label)
                            <a class="soc" href="{{ $c[$key] }}" target="_blank" rel="noopener me" data-track="click" data-label="{{ $label }}">{{ $label }}</a>
                        @endforeach
                    </div>
                </div>
            </section>
        @endif
        @break

    @case('form')
        @php $formWa = !empty($c['sendToWhatsapp']) ? preg_replace('/^0/', '62', preg_replace('/\D/', '', (string) ($c['whatsappNumber'] ?? ($settings['whatsappNumber'] ?? '')))) : ''; @endphp
        <section class="{{ $cls }}" @if($style) style="{{ $style }}" @endif>
            <div class="wrap"><div class="card" style="padding:20px">
                @if (!empty($c['title']))<h2>{{ $c['title'] }}</h2>@endif
                @if (!empty($c['subtitle']))<p class="muted">{{ $c['subtitle'] }}</p>@endif
                @if (isset($page))
                <form data-lead data-page="{{ $page->id }}" data-block="{{ $b['id'] }}" data-title="{{ $c['title'] ?? '' }}" data-success="{{ $c['successMessage'] ?? '' }}" @if($formWa) data-wa="{{ $formWa }}" @endif>
                    @foreach (($c['fields'] ?? []) ?: [['id' => 'name', 'label' => 'Nama', 'type' => 'text', 'required' => true]] as $f)
                        @php $fid = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($f['id'] ?? 'field'))) ?: 'field'; @endphp
                        <label class="field">{{ $f['label'] ?? $fid }}
                            @if (($f['type'] ?? 'text') === 'textarea')
                                <textarea name="{{ $fid }}" rows="3" @if(!empty($f['required'])) required @endif></textarea>
                            @else
                                <input name="{{ $fid }}" type="{{ in_array($f['type'] ?? 'text', ['text', 'tel', 'email', 'number'], true) ? $f['type'] : 'text' }}" @if(!empty($f['required'])) required @endif
                                    autocomplete="{{ $fid === 'name' ? 'name' : ($fid === 'email' ? 'email' : ($fid === 'phone' ? 'tel' : 'off')) }}">
                            @endif
                        </label>
                    @endforeach
                    <button class="{{ $btnClass }} block" type="submit">{{ $c['buttonText'] ?? 'Kirim' }}</button>
                </form>
                @endif
            </div></div>
        </section>
        @break

    @case('button')
        @php $btnHref = ($c['actionType'] ?? 'link') === 'whatsapp' ? $wa($c['whatsappNumber'] ?? ($settings['whatsappNumber'] ?? null), $c['whatsappMessage'] ?? null) : $href($c['linkUrl'] ?? null); @endphp
        {{-- Public page: no dead buttons. Editor preview: shown with a hint so it can be tapped and filled in. --}}
        @if ($btnHref || !empty($editor))
            @php $btnHref = $btnHref ?: '#'; @endphp
            <section class="{{ $cls }} {{ $c['align'] ?? 'center' }}" style="{{ trim('padding-top:8px;padding-bottom:8px;' . $style, ';') }}">
                <div class="wrap">
                    @php
                        // This button's own look (Fase 5), else the theme's; left icon or thumbnail; featured animation.
                        $ownLook = !empty($c['fill']) || !empty($c['shape']) || !empty($c['shadow']);
                        $btnVars = $ownLook ? \App\Support\Landing\ThemeStyle::buttonVars($theme, $c['fill'] ?? null, $c['shape'] ?? null, $c['shadow'] ?? null) : '';
                        $btnIco = !empty($c['thumbUrl']) ? '<img src="' . e($c['thumbUrl']) . '" alt="" loading="lazy" decoding="async">' : (!empty($c['icon']) ? \App\Support\Landing\ButtonIcons::svg($c['icon']) : '');
                    @endphp
                    <a class="btn{{ !empty($c['fullWidth']) ? ' block' : '' }}{{ !empty($c['featured']) ? ' featured' . (!empty($c['featuredStyle']) ? ' fx-' . $c['featuredStyle'] : '') : '' }}{{ $btnIco ? ' has-ico' : '' }}" @if($btnVars) style="{{ $btnVars }}" @endif href="{{ $btnHref }}"
                       @if (!str_starts_with($btnHref, '/') && !str_starts_with($btnHref, '#')) target="_blank" rel="noopener" @endif data-track="click" data-label="{{ $c['text'] ?? '' }}">@if ($btnIco)<span class="btn-ico">{!! $btnIco !!}</span>@endif{{ $c['text'] ?? 'Klik di sini' }}</a>
                    @if (!empty($editor) && empty($sample) && $btnHref === '#')<p class="small muted" style="margin:6px 0 0">Belum ada link — ketuk untuk mengisi</p>@endif
                </div>
            </section>
        @endif
        @break

    @case('spacer')
        <div aria-hidden="true" style="height:{{ (int) ($c['height'] ?? 32) }}px"></div>
        @break

    @case('whatsapp')
        @php $waHref = $wa(($c['number'] ?? '') !== '' ? $c['number'] : ($settings['whatsappNumber'] ?? null), $c['message'] ?? null); @endphp
        @php $waHref = $waHref ?: (!empty($sample) ? '#' : null); @endphp
        @if ($waHref)
            <section class="{{ $cls }} center" style="{{ trim('padding-top:8px;padding-bottom:8px;' . $style, ';') }}">
                <div class="wrap">
                    @if (($c['style'] ?? 'button') === 'card')
                        <div class="wa-card">
                            @if (!empty($c['title']))<h2 style="margin-top:0">{{ $c['title'] }}</h2>@endif
                            <a class="btn block wa-btn" href="{{ $waHref }}" target="_blank" rel="noopener" data-track="click" data-label="{{ $c['text'] ?? 'WhatsApp' }}"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M20.5 3.5A11 11 0 0 0 3.3 17.2L2 22l4.9-1.3A11 11 0 0 0 20.5 3.5zM12 20a8.9 8.9 0 0 1-4.6-1.3l-.3-.2-2.9.8.8-2.8-.2-.3A9 9 0 1 1 12 20zm4.9-6.7c-.3-.1-1.6-.8-1.8-.9-.3-.1-.4-.1-.6.1l-.8 1c-.2.2-.3.2-.6.1a7.4 7.4 0 0 1-3.6-3.2c-.3-.5.3-.4.8-1.4.1-.2 0-.3 0-.4l-.8-2c-.2-.5-.4-.4-.6-.4h-.5a1 1 0 0 0-.7.3 3 3 0 0 0-.9 2.2 5.2 5.2 0 0 0 1.1 2.8 11.9 11.9 0 0 0 4.6 4c1.7.7 2.4.8 3.2.7.5-.1 1.6-.7 1.8-1.3.2-.6.2-1.2.2-1.3-.1-.2-.3-.2-.6-.3z"/></svg> {{ $c['text'] ?? 'Chat via WhatsApp' }}</a>
                        </div>
                    @else
                        <a class="btn block wa-btn" href="{{ $waHref }}" target="_blank" rel="noopener" data-track="click" data-label="{{ $c['text'] ?? 'WhatsApp' }}"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M20.5 3.5A11 11 0 0 0 3.3 17.2L2 22l4.9-1.3A11 11 0 0 0 20.5 3.5zM12 20a8.9 8.9 0 0 1-4.6-1.3l-.3-.2-2.9.8.8-2.8-.2-.3A9 9 0 1 1 12 20zm4.9-6.7c-.3-.1-1.6-.8-1.8-.9-.3-.1-.4-.1-.6.1l-.8 1c-.2.2-.3.2-.6.1a7.4 7.4 0 0 1-3.6-3.2c-.3-.5.3-.4.8-1.4.1-.2 0-.3 0-.4l-.8-2c-.2-.5-.4-.4-.6-.4h-.5a1 1 0 0 0-.7.3 3 3 0 0 0-.9 2.2 5.2 5.2 0 0 0 1.1 2.8 11.9 11.9 0 0 0 4.6 4c1.7.7 2.4.8 3.2.7.5-.1 1.6-.7 1.8-1.3.2-.6.2-1.2.2-1.3-.1-.2-.3-.2-.6-.3z"/></svg> {{ $c['text'] ?? 'Chat via WhatsApp' }}</a>
                    @endif
                </div>
            </section>
        @endif
        @break

    @case('embed')
        @php $e = $b['embed'] ?? null; @endphp
        @if ($e)
            <section class="{{ $cls }}" @if($style) style="{{ $style }}" @endif>
                <div class="wrap">
                    @if (!empty($c['title']))<h2 class="center">{{ $c['title'] }}</h2>@endif
                    @if ($e['provider'] === 'youtube')
                        @include('landing.youtube', ['e' => $e, 'title' => $c['title'] ?? ''])
                    @else
                        <div class="embed{{ $e['vertical'] && !$e['height'] ? ' vertical' : '' }}" @if($e['height']) style="height:{{ $e['height'] }}px" @endif>
                            <iframe src="{{ $e['src'] }}" title="{{ $c['title'] ?? $e['label'] }}" loading="lazy" allow="encrypted-media; clipboard-write; fullscreen; picture-in-picture" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe>
                        </div>
                    @endif
                </div>
            </section>
        @endif
        @break

    @case('divider')
        <div class="wrap" style="padding:8px 20px"><hr class="div" style="border-top:{{ (int) ($c['thickness'] ?? 1) }}px {{ $c['style'] ?? 'solid' }} currentColor;opacity:.25;width:{{ (int) ($c['width'] ?? 100) }}%"></div>
        @break

    @case('testimonials')
        <section class="{{ $cls }}" @if($style) style="{{ $style }}" @endif>
            <div class="wrap wide">
                @if (!empty($c['title']))<h2 class="center">{{ $c['title'] }}</h2>@endif
                <div class="grid md-g2 g3">
                    @foreach ($c['items'] ?? [] as $t)
                        <figure class="card quote" style="margin:0">
                            @if (!empty($t['rating']))<div class="stars" aria-label="{{ $t['rating'] }} dari 5">{{ str_repeat('★', (int) $t['rating']) }}</div>@endif
                            <blockquote style="margin:8px 0">“{{ $t['text'] ?? '' }}”</blockquote>
                            <figcaption class="small"><strong>{{ $t['name'] ?? '' }}</strong>@if (!empty($t['role']))<span class="muted"> · {{ $t['role'] }}</span>@endif</figcaption>
                        </figure>
                    @endforeach
                </div>
            </div>
        </section>
        @break

    @case('faq')
        <section class="{{ $cls }}" @if($style) style="{{ $style }}" @endif>
            <div class="wrap">
                @if (!empty($c['title']))<h2 class="center">{{ $c['title'] }}</h2>@endif
                @foreach ($c['items'] ?? [] as $item)
                    <details class="faq"><summary>{{ $item['q'] ?? '' }}</summary><p class="muted" style="margin:8px 0 0;white-space:pre-line">{{ $item['a'] ?? '' }}</p></details>
                @endforeach
            </div>
        </section>
        @break

    @case('list')
        <section class="{{ $cls }}" @if($style) style="{{ $style }}" @endif>
            <div class="wrap">
                @if (!empty($c['title']))<h2>{{ $c['title'] }}</h2>@endif
                <ul class="checks">@foreach ($c['items'] ?? [] as $item)<li>{{ $item['text'] ?? '' }}</li>@endforeach</ul>
            </div>
        </section>
        @break

    @case('slider')
        @if (!empty($c['images']))
            <section class="{{ $cls }}" @if($style) style="{{ $style }}" @endif>
                <div class="wrap wide"><div class="scroller">
                    @foreach ($c['images'] as $img)
                        @if (!empty($img['url']))<figure style="margin:0"><img src="{{ $img['url'] }}" {!! $srcset($img['url'], '(max-width: 600px) 86vw, 900px') !!} alt="{{ $img['caption'] ?? '' }}" {!! $imgAttrs($first && $loop->first) !!}>@if (!empty($img['caption']))<figcaption class="small muted" style="padding:6px">{{ $img['caption'] }}</figcaption>@endif</figure>@endif
                    @endforeach
                </div></div>
            </section>
        @endif
        @break

    @case('gallery')
        @php $galleryImages = array_values(array_filter($c['images'] ?? [], fn ($img) => !empty($img['url']))); @endphp
        @if ($galleryImages !== [])
            <section class="{{ $cls }}" @if($style) style="{{ $style }}" @endif>
                <div class="wrap wide">
                    @if (!empty($c['title']))<h2 class="center">{{ $c['title'] }}</h2>@endif
                    <div class="grid gallery" style="grid-template-columns:repeat({{ min(4, max(2, (int) ($c['columns'] ?? 2))) }},minmax(0,1fr));gap:8px">
                        @foreach ($c['images'] as $img)
                            @if (!empty($img['url']))<img src="{{ $img['url'] }}" {!! $srcset($img['url'], '(max-width: 600px) 50vw, 300px') !!} alt="{{ $img['caption'] ?? '' }}" loading="lazy" decoding="async">@endif
                        @endforeach
                    </div>
                </div>
            </section>
        @endif
        @break

    @case('countdown')
        @if (!empty($c['targetDate']))
            <section class="{{ $cls }} center" @if($style) style="{{ $style }}" @endif>
                <div class="wrap">
                    @if (!empty($c['title']))<h2>{{ $c['title'] }}</h2>@endif
                    @if (!empty($c['subtitle']))<p class="muted">{{ $c['subtitle'] }}</p>@endif
                    <div class="count" data-countdown="{{ $c['targetDate'] }}" data-expired="{{ $c['expiredText'] ?? 'Promo sudah berakhir' }}">
                        <div><b>00</b><small>hari</small></div><div><b>00</b><small>jam</small></div><div><b>00</b><small>menit</small></div><div><b>00</b><small>detik</small></div>
                    </div>
                </div>
            </section>
        @endif
        @break

    @case('html')
        <section class="{{ $cls }}" @if($style) style="{{ $style }}" @endif>
            {{-- Sanitised on save and again in BlockSchema::normalize (allowlist, no scripts). --}}
            <div class="wrap html-block">{!! $c['html'] ?? '' !!}</div>
        </section>
        @break
@endswitch
