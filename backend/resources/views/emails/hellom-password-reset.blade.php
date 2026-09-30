@extends('emails.partials.hellom-layout')

@section('preheader')Link untuk membuat kata sandi baru, berlaku {{ $expiresInMinutes }} menit.@endsection

@section('content')
    <h1 style="margin:0 0 16px;font-size:22px;line-height:1.3;font-weight:800;color:#0a0a0a;">Buat kata sandi baru</h1>
    <p style="margin:0 0 12px;">Halo{{ !empty($name) ? ' ' . $name : '' }},</p>
    <p style="margin:0;">
        Kami menerima permintaan untuk mengganti kata sandi akun <strong style="color:#18181b;">{{ $email }}</strong>.
        Tekan tombol di bawah untuk membuat kata sandi baru.
    </p>

    @if(!empty($resetUrl))
        @include('emails.partials.button', ['url' => $resetUrl, 'label' => 'Buat kata sandi baru'])
    @else
        <p style="margin:20px 0 8px;font-size:13px;color:#71717a;">Kode reset:</p>
        <p style="margin:0 0 20px;padding:12px 14px;border-radius:10px;background:#f4f4f5;font-family:Consolas,Menlo,monospace;font-size:14px;color:#18181b;word-break:break-all;">{{ $token }}</p>
    @endif

    <p style="margin:0 0 8px;font-size:14px;color:#52525b;">
        Link ini berlaku <strong>{{ $expiresInMinutes }} menit</strong> dan hanya bisa dipakai sekali.
        Setelah kata sandi diganti, semua perangkat yang masih masuk akan dikeluarkan.
    </p>
    <p style="margin:0;font-size:14px;color:#52525b;">
        Tidak merasa meminta ini? Abaikan saja email ini — kata sandi kamu tidak berubah.
    </p>
@endsection
