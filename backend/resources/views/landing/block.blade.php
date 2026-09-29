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
    $btnClass = 'btn' . (($theme['buttonStyle'] ?? 'solid') === 'outline' ? ' outline' : '');
@endphp
@switch($b['type'])
    @case('profile')
        <section class="profile {{ $cls }}" @if($style) style="{{ $style }}" @endif>
            @if (!empty($c['coverUrl']))<div class="cover" style="background-image:url('{{ $cssUrl($c['coverUrl']) }}')"></div>@endif
            <div class="wrap center">
                @if (!empty($c['avatarUrl']))
                    <img class="avatar" src="{{ $c['avatarUrl'] }}" alt="{{ $c['name'] ?? $organization->name }}" width="104" height="104" {!! $imgAttrs($first) !!}>
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
                @if (!empty($c['imageUrl']))<img class="hero-img" src="{{ $c['imageUrl'] }}" alt="" {!! $imgAttrs($first) !!}>@endif
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
                    <img src="{{ $src }}" alt="{{ $c['alt'] ?? ($c['caption'] ?? '') }}" style="border-radius:16px;margin:0 auto" {!! $imgAttrs($first) !!}>
                    @if ($href($c['linkUrl'] ?? null))</a>@endif
                    @if (!empty($c['caption']))<p class="muted small" style="margin-top:8px">{{ $c['caption'] }}</p>@endif
                </div>
            </section>
        @endif
        @break

    @case('video')
        @if (!empty($b['youtube_id']))
            <section class="{{ $cls }}" @if($style) style="{{ $style }}" @endif>
                <div class="wrap">
                    @if (!empty($c['title']))<h2 class="center">{{ $c['title'] }}</h2>@endif
                    <button type="button" class="yt" data-yt="{{ $b['youtube_id'] }}" aria-label="Putar video {{ $c['title'] ?? '' }}">
                        <img src="https://i.ytimg.com/vi/{{ $b['youtube_id'] }}/hqdefault.jpg" alt="" loading="lazy" decoding="async"><span></span>
                    </button>
                </div>
            </section>
        @endif
        @break

    @case('product')
        @php $p = $b['product'] ?? null; @endphp
        <section id="produk" class="{{ $cls }}" @if($style) style="{{ $style }}" @endif>
            <div class="wrap wide">
                @if ($p)
                    <div class="card featured">
                        @if ($p['image_url'])<img class="prod-img" src="{{ $p['image_url'] }}" alt="{{ $p['name'] }}" {!! $imgAttrs($first) !!}>@endif
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
                        @if (!empty($c['imageUrl']))<img class="prod-img" src="{{ $c['imageUrl'] }}" alt="{{ $c['name'] }}" loading="lazy">@endif
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
                                    @if ($p['image_url'])<img class="prod-img" src="{{ $p['image_url'] }}" alt="{{ $p['name'] }}" loading="lazy" decoding="async">@else<div class="prod-img"></div>@endif
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
        @if ($btnHref)
            <section class="{{ $cls }} {{ $c['align'] ?? 'center' }}" style="{{ trim('padding-top:8px;padding-bottom:8px;' . $style, ';') }}">
                <div class="wrap">
                    <a class="btn{{ ($c['style'] ?? $theme['buttonStyle']) === 'outline' ? ' outline' : '' }}{{ !empty($c['fullWidth']) ? ' block' : '' }}" href="{{ $btnHref }}"
                       @if (!str_starts_with($btnHref, '/') && !str_starts_with($btnHref, '#')) target="_blank" rel="noopener" @endif data-track="click" data-label="{{ $c['text'] ?? '' }}">{{ $c['text'] ?? 'Klik di sini' }}</a>
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
                        @if (!empty($img['url']))<figure style="margin:0"><img src="{{ $img['url'] }}" alt="{{ $img['caption'] ?? '' }}" {!! $imgAttrs($first && $loop->first) !!}>@if (!empty($img['caption']))<figcaption class="small muted" style="padding:6px">{{ $img['caption'] }}</figcaption>@endif</figure>@endif
                    @endforeach
                </div></div>
            </section>
        @endif
        @break

    @case('gallery')
        @if (!empty($c['images']))
            <section class="{{ $cls }}" @if($style) style="{{ $style }}" @endif>
                <div class="wrap wide">
                    @if (!empty($c['title']))<h2 class="center">{{ $c['title'] }}</h2>@endif
                    <div class="grid gallery" style="grid-template-columns:repeat({{ min(4, max(2, (int) ($c['columns'] ?? 2))) }},minmax(0,1fr));gap:8px">
                        @foreach ($c['images'] as $img)
                            @if (!empty($img['url']))<img src="{{ $img['url'] }}" alt="{{ $img['caption'] ?? '' }}" loading="lazy" decoding="async">@endif
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
