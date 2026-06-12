@extends('emails.partials.hellom-layout')

@section('content')
    <h1 style="margin:0 0 12px;font-size:28px;line-height:1.2;color:#111827;">Undangan bergabung ke tim</h1>
    <p style="margin:0 0 16px;font-size:15px;line-height:1.75;color:#374151;">
        Kamu diundang untuk bergabung ke organisasi <strong>{{ $organizationName }}</strong> sebagai <strong>{{ $role }}</strong>.
    </p>
    <p style="margin:0 0 18px;font-size:15px;line-height:1.75;color:#374151;">
        Klik tombol di bawah untuk login atau daftar menggunakan email yang diundang, lalu selesaikan proses join organisasi di Hellom.
    </p>
    @if(!empty($registerUrl))
        <a href="{{ $registerUrl }}" style="display:inline-block;padding:12px 18px;background:#111827;color:#ffffff;text-decoration:none;border-radius:12px;font-weight:700;">
            Buka Undangan
        </a>
    @endif
    <p style="margin:18px 0 12px;font-size:14px;color:#6b7280;">Token undangan</p>
    <div style="margin:0 0 18px;padding:14px 16px;border-radius:14px;background:#f3f4f6;font-family:Consolas,monospace;font-size:15px;color:#111827;word-break:break-all;">
        {{ $token }}
    </div>
    @if($expiresAt)
        <p style="margin:0 0 16px;font-size:15px;line-height:1.75;color:#374151;">
            Undangan berlaku sampai <strong>{{ $expiresAt->format('d M Y H:i') }}</strong>.
        </p>
    @endif
    <p style="margin:0;font-size:14px;line-height:1.75;color:#6b7280;">
        Jika kamu tidak merasa diundang, abaikan email ini.
    </p>
@endsection
