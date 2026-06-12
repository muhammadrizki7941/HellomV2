@extends('emails.partials.hellom-layout')

@section('content')
    <h1 style="margin:0 0 12px;font-size:28px;line-height:1.2;color:#111827;">Selamat datang, {{ $name }}.</h1>
    <p style="margin:0 0 16px;font-size:15px;line-height:1.7;color:#374151;">
        Akun Hellom kamu sudah aktif dan siap dipakai.
        @if($organizationName)
            Kamu sudah terhubung ke organisasi <strong>{{ $organizationName }}</strong>.
        @endif
    </p>
    <p style="margin:0 0 16px;font-size:15px;line-height:1.7;color:#374151;">
        Sekarang kamu bisa masuk ke dashboard, melihat aplikasi yang tersedia, dan melanjutkan setup bisnis langsung dari Hellom.
    </p>
@endsection
