@extends('landing.layout')

@php
    // Editor preview: names for the placeholder of a block that has nothing to show yet.
    $editorLabels = [
        'profile' => 'Profil', 'button' => 'Tombol link', 'social' => 'Ikon sosial media', 'text' => 'Teks', 'divider' => 'Pemisah',
        'product' => 'Produk', 'catalog' => 'Katalog produk', 'pdf' => 'File / PDF', 'form' => 'Formulir', 'countdown' => 'Hitung mundur',
        'testimonials' => 'Testimoni', 'image' => 'Gambar', 'banner' => 'Banner', 'slider' => 'Carousel gambar', 'gallery' => 'Galeri',
        'video' => 'Video', 'faq' => 'Tanya jawab', 'hero' => 'Hero', 'features' => 'Keunggulan', 'cta' => 'Ajakan',
        'content' => 'Konten', 'list' => 'Daftar', 'gif' => 'GIF', 'html' => 'HTML', 'social' => 'Ikon sosial media',
        'spacer' => 'Spasi', 'whatsapp' => 'Tombol WhatsApp', 'embed' => 'Embed',
    ];
@endphp

@section('content')
    @php
        // Social icons sit right above / below the first profile block, or at the top of a page without one.
        $hasSocial = !empty($social['items']);
        $profileAt = $hasSocial ? collect($blocks)->search(fn ($x) => $x['type'] === 'profile') : false;
        $socialRow = fn () => !empty($editor)
            ? '<div data-hl-block="__social" style="display:contents">' . view('landing.social', ['social' => $social])->render() . '</div>'
            : view('landing.social', ['social' => $social])->render();
    @endphp
    @if ($hasSocial && $profileAt === false){!! $socialRow() !!}@endif
    @foreach ($blocks as $b)
        @if ($hasSocial && $profileAt === $loop->index && ($social['position'] ?? 'bottom') === 'top'){!! $socialRow() !!}@endif
        @if (!empty($editor))
            {{-- Editor phone preview: a tap selects the block (layout unchanged: display contents). A block
                 that has nothing to show yet (no image, no link…) gets a placeholder so it can be tapped. --}}
            @php $blockHtml = trim($__env->make('landing.block', ['b' => $b, 'first' => $loop->first], \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render()); @endphp
            <div data-hl-block="{{ $b['id'] }}" style="display:contents">
                @if ($blockHtml !== '')
                    {!! $blockHtml !!}
                @else
                    <section class="blk center" style="padding-top:10px;padding-bottom:10px"><div class="wrap"><p class="small muted" style="margin:0;padding:14px;border:1px dashed currentColor;border-radius:14px;opacity:.75">{{ $editorLabels[$b['type']] ?? 'Bagian' }} — {{ !empty($sample) ? 'isi milikmu tampil di sini' : 'ketuk untuk melengkapi' }}</p></div></section>
                @endif
            </div>
        @else
            {{-- Clicks remember which block they came from (statistics per link, Fase 6); entrance animation (Fase 7.2). --}}
            @php
                $enter = empty($theme['motion']['off']) ? ($b['styles']['entrance'] ?? $theme['motion']['entrance'] ?? 'none') : 'none';
                $publicHtml = str_replace(' data-track="', ' data-item="' . e($b['id']) . '" data-track="', $__env->make('landing.block', ['b' => $b, 'first' => $loop->first], \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render());
            @endphp
            @if ($enter !== 'none' && trim($publicHtml) !== '')
                <div class="ent ent-{{ $enter }}" style="--i:{{ min($loop->index, 10) }}">{!! $publicHtml !!}</div>
            @else
                {!! $publicHtml !!}
            @endif
        @endif
        @if ($hasSocial && $profileAt === $loop->index && ($social['position'] ?? 'bottom') === 'bottom'){!! $socialRow() !!}@endif
    @endforeach
    @if (count($blocks) === 0)
        <section class="blk center"><div class="wrap"><h1>{{ $organization->name }}</h1><p class="muted">Halaman ini sedang disiapkan.</p></div></section>
    @endif
@endsection
