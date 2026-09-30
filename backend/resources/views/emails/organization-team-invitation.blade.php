@extends('emails.partials.hellom-layout')

@php
    $roleLabel = ['cashier' => 'Kasir', 'admin' => 'Admin', 'member' => 'Anggota tim', 'owner' => 'Pemilik'][$role] ?? $role;
    $isCashier = $role === 'cashier';
@endphp

@section('preheader'){{ ($activation ?? false) ? 'Aktifkan akun kasir kamu' : 'Kamu diundang bergabung' }} di {{ $organizationName }}.@endsection

@section('content')
    @if($activation ?? false)
        <h1 style="margin:0 0 16px;font-size:22px;line-height:1.3;font-weight:800;color:#0a0a0a;">Aktifkan akun kamu</h1>
        <p style="margin:0;">
            Email ini terdaftar sebagai <strong style="color:#18181b;">{{ $roleLabel }}</strong> di
            <strong style="color:#18181b;">{{ $organizationName }}</strong>, tapi belum punya akun Hellom.
            Buat kata sandi sekarang untuk mulai memakai POS toko.
        </p>
    @else
        <h1 style="margin:0 0 16px;font-size:22px;line-height:1.3;font-weight:800;color:#0a0a0a;">Kamu diundang bergabung</h1>
        <p style="margin:0;">
            <strong style="color:#18181b;">{{ $organizationName }}</strong> mengundang kamu bergabung sebagai
            <strong style="color:#18181b;">{{ $roleLabel }}</strong> di Hellom.
            @if($isCashier) Setelah bergabung, kamu langsung bisa memakai POS toko ini. @endif
        </p>
    @endif

    @if(!empty($registerUrl))
        @include('emails.partials.button', ['url' => $registerUrl, 'label' => ($activation ?? false) ? 'Buat kata sandi' : 'Terima undangan'])
    @endif

    @if($isCashier)
        <p style="margin:0 0 12px;font-size:14px;color:#52525b;">
            Berikutnya, masuk lewat halaman <strong>Masuk kasir</strong>: <a href="{{ \App\Support\FrontendUrl::to('/login/kasir') }}" style="color:#3f3f46;">{{ \App\Support\FrontendUrl::to('/login/kasir') }}</a>
        </p>
    @endif
    @if($expiresAt)
        <p style="margin:0 0 8px;font-size:14px;color:#52525b;">
            Link berlaku sampai <strong>{{ $expiresAt->copy()->timezone(config('app.timezone'))->locale('id')->translatedFormat('d F Y, H:i') }}</strong>.
        </p>
    @endif
    <p style="margin:0;font-size:14px;color:#52525b;">
        Tidak mengenal toko ini? Abaikan email ini — tidak ada yang berubah di akun kamu.
    </p>
@endsection
