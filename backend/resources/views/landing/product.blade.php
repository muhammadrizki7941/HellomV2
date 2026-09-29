@extends('landing.layout')

@php $rp = fn ($v) => 'Rp ' . number_format((int) $v, 0, ',', '.'); @endphp

@section('content')
    <div class="wrap" style="padding-top:12px">
        <a class="back" href="{{ $homeUrl }}">← {{ $organization->name }}</a>
    </div>
    <article class="blk py-8">
        <div class="wrap">
            <div class="card">
                @if ($product['image_url'])
                    <img class="prod-img" src="{{ $product['image_url'] }}" @if ($set = \App\Support\ImageOptimizer::srcset($product['image_url'])) srcset="{{ $set }}" sizes="(max-width: 720px) 100vw, 680px" @endif alt="{{ $product['name'] }}" fetchpriority="high">
                @endif
                <div class="prod-body" style="padding:20px">
                    <p class="muted small" style="margin:0">{{ $product['type_label'] }}</p>
                    <h1 style="margin:0">{{ $product['name'] }}</h1>
                    <p class="price" style="margin:0;font-size:1.4rem">{{ $rp($product['price']) }}@if ($product['compare_at_price'])<span class="strike">{{ $rp($product['compare_at_price']) }}</span>@endif</p>
                    @if ($product['stock_left'])<p class="stock" style="margin:0">Sisa {{ $product['stock_left'] }}</p>@endif
                    @if ($seller['verified'])<p style="margin:4px 0 0"><span class="badge">✔ Penjual Terverifikasi</span></p>@endif
                    @if ($product['description'])
                        {{-- Sanitised on save (SafeHtml) and again in publicPayload(). --}}
                        <div class="desc" style="margin-top:12px">{!! $product['description'] !!}</div>
                    @endif
                    @if ($product['shipping'])
                        <p class="muted small" style="margin-top:12px">Pengiriman: {{ ['free' => 'gratis ongkir', 'flat' => 'ongkir ' . $rp($product['shipping']['fee']), 'manual' => 'ongkir dikonfirmasi penjual'][$product['shipping']['mode']] ?? '' }}</p>
                    @endif
                </div>
            </div>
        </div>
    </article>
    <div class="pp-bar">
        <div class="wrap">
            <div><div class="muted small">Harga</div><strong>{{ $rp($product['price']) }}</strong></div>
            @if ($product['available'])
                <a class="btn" href="{{ $checkoutUrl }}" data-track="buy" data-product="{{ $product['id'] }}" data-value="{{ $product['price'] }}" data-label="{{ $product['name'] }}">Beli sekarang</a>
            @else
                <span class="btn" aria-disabled="true">{{ $product['in_stock'] ? 'Belum tersedia' : 'Stok habis' }}</span>
            @endif
        </div>
    </div>
@endsection
