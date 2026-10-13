@extends('emails.partials.hellom-layout')

@section('preheader')Akun Hellom kamu sudah aktif. Ini tiga langkah pertama untuk mulai jualan.@endsection

@php
    $steps = [
        ['Buat Hellom Page', 'Pilih template, tambahkan link & produk, lalu terbitkan halaman toko kamu.', \App\Support\FrontendUrl::to('/dashboard/apps/landing-builder')],
        ['Hitung modal di Kalkulator HPP', 'Masukkan bahan & resep, dapatkan saran harga jual yang tetap untung. Gratis.', \App\Support\FrontendUrl::to('/dashboard/apps/hpp')],
        ['Pakai POS Kasir', 'Catat pesanan, meja, dan kas harian dari HP atau tablet.', \App\Support\FrontendUrl::to('/dashboard')],
    ];
@endphp

@section('content')
    <h1 style="margin:0 0 16px;font-size:22px;line-height:1.3;font-weight:800;color:#0a0a0a;">Selamat datang, {{ $name }}</h1>
    <p style="margin:0 0 12px;">
        Akun Hellom kamu sudah aktif.
        @if($organizationName)
            Kamu terhubung ke <strong style="color:#18181b;">{{ $organizationName }}</strong>.
        @endif
    </p>

    @if($verifyUrl)
        <p style="margin:0 0 4px;">Langkah pertama: pastikan email ini milik kamu. Verifikasi diperlukan untuk menarik saldo penjualan dan memulihkan akun.</p>
        @include('emails.partials.button', ['url' => $verifyUrl, 'label' => 'Verifikasi Email'])
        <p style="margin:0 0 20px;font-size:13px;color:#71717a;">Tautan berlaku 24 jam. Kalau sudah lewat, minta tautan baru dari dashboard.</p>
    @endif

    <p style="margin:0 0 10px;font-weight:700;color:#18181b;">Mulai dari sini</p>
    <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin:0 0 20px;border:1px solid #e4e4e7;border-radius:12px;border-collapse:separate;">
        @foreach($steps as $i => [$title, $text, $url])
            <tr>
                <td style="padding:12px 14px;vertical-align:top;width:28px;font-weight:800;color:#F6B400;{{ $loop->last ? '' : 'border-bottom:1px solid #f4f4f5;' }}">{{ $i + 1 }}</td>
                <td style="padding:12px 14px 12px 0;font-size:14px;{{ $loop->last ? '' : 'border-bottom:1px solid #f4f4f5;' }}">
                    <a href="{{ $url }}" style="font-weight:700;color:#18181b;text-decoration:none;">{{ $title }}</a><br>
                    <span style="color:#52525b;">{{ $text }}</span>
                </td>
            </tr>
        @endforeach
    </table>

    @unless($verifyUrl)
        @include('emails.partials.button', ['url' => \App\Support\FrontendUrl::to('/dashboard'), 'label' => 'Buka dashboard'])
    @endunless
    <p style="margin:0;font-size:14px;color:#52525b;">Ada pertanyaan? Balas email ini — tim kami membaca setiap balasan.</p>
@endsection
