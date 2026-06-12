@extends('emails.partials.hellom-layout')

@section('content')
    <h1 style="margin:0 0 12px;font-size:28px;line-height:1.2;color:#111827;">Reset password akun Hellom</h1>
    <p style="margin:0 0 16px;font-size:15px;line-height:1.75;color:#374151;">
        Kami menerima permintaan reset password untuk akun <strong>{{ $email }}</strong>.
    </p>
    <p style="margin:0 0 12px;font-size:14px;color:#6b7280;">Masukkan token berikut di halaman reset password Hellom:</p>
    <div style="margin:0 0 18px;padding:14px 16px;border-radius:14px;background:#f3f4f6;font-family:Consolas,monospace;font-size:15px;color:#111827;word-break:break-all;">
        {{ $token }}
    </div>
    <p style="margin:0 0 16px;font-size:15px;line-height:1.75;color:#374151;">
        Token berlaku selama <strong>{{ $expiresInMinutes }}</strong> menit. Jika kamu tidak melakukan permintaan ini, abaikan email ini.
    </p>
@endsection
