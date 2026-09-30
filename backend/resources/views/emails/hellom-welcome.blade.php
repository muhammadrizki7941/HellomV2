@extends('emails.partials.hellom-layout')

@section('preheader')Akun Hellom kamu sudah aktif.@endsection

@section('content')
    <h1 style="margin:0 0 16px;font-size:22px;line-height:1.3;font-weight:800;color:#0a0a0a;">Selamat datang, {{ $name }}</h1>
    <p style="margin:0 0 12px;">
        Akun Hellom kamu sudah aktif.
        @if($organizationName)
            Kamu terhubung ke <strong style="color:#18181b;">{{ $organizationName }}</strong>.
        @endif
    </p>
    <p style="margin:0;">Dari dashboard kamu bisa membuat halaman toko, menjual produk digital, dan memakai POS untuk usaha kamu.</p>
    @include('emails.partials.button', ['url' => \App\Support\FrontendUrl::to('/dashboard'), 'label' => 'Buka dashboard'])
@endsection
