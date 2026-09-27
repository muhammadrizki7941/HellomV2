@extends('emails.partials.hellom-layout')

@section('content')
    <h1 style="margin:0 0 12px;font-size:26px;line-height:1.25;color:#111827;">Pembayaran berhasil 🎉</h1>
    <p style="margin:0 0 20px;font-size:15px;line-height:1.7;color:#374151;">
        Terima kasih. <strong>{{ $productName }}</strong> sudah aktif di akun Anda dan bisa langsung dipakai.
    </p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 24px;border:1px solid #e5e7eb;border-radius:14px;">
        <tr>
            <td style="padding:14px 18px;font-size:14px;color:#6b7280;">Produk</td>
            <td style="padding:14px 18px;font-size:14px;color:#111827;text-align:right;font-weight:600;">{{ $productName }}</td>
        </tr>
        <tr>
            <td style="padding:14px 18px;font-size:14px;color:#6b7280;border-top:1px solid #f3f4f6;">No. transaksi</td>
            <td style="padding:14px 18px;font-size:14px;color:#111827;text-align:right;border-top:1px solid #f3f4f6;">{{ $transactionCode }}</td>
        </tr>
        <tr>
            <td style="padding:14px 18px;font-size:14px;color:#6b7280;border-top:1px solid #f3f4f6;">Total</td>
            <td style="padding:14px 18px;font-size:14px;color:#111827;text-align:right;border-top:1px solid #f3f4f6;">Rp {{ number_format($amount, 0, ',', '.') }}</td>
        </tr>
        <tr>
            <td style="padding:14px 18px;font-size:14px;color:#6b7280;border-top:1px solid #f3f4f6;">Masa akses</td>
            <td style="padding:14px 18px;font-size:14px;color:#111827;text-align:right;border-top:1px solid #f3f4f6;">{{ $accessPeriod }}</td>
        </tr>
    </table>

    <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 12px;">
        <tr>
            <td style="border-radius:12px;background:#111827;">
                <a href="{{ $accessUrl }}" style="display:inline-block;padding:14px 26px;font-size:15px;font-weight:700;color:#ffffff;text-decoration:none;">Buka produk saya</a>
            </td>
        </tr>
    </table>
    <p style="margin:0 0 24px;font-size:13px;line-height:1.6;color:#6b7280;">
        Tombol di atas langsung masuk ke dashboard tanpa login, berlaku {{ $linkValidDays }} hari dan hanya bisa dipakai sekali.
    </p>

    @if($password)
        <div style="margin:0 0 20px;padding:18px 20px;border-radius:14px;background:#f9fafb;border:1px solid #e5e7eb;">
            <p style="margin:0 0 10px;font-size:14px;font-weight:700;color:#111827;">Akun Anda</p>
            <p style="margin:0 0 6px;font-size:14px;color:#374151;">Email: <strong>{{ $email }}</strong></p>
            <p style="margin:0;font-size:14px;color:#374151;">Password: <strong style="font-family:Consolas,monospace;letter-spacing:0.5px;">{{ $password }}</strong></p>
        </div>
        <p style="margin:0 0 16px;font-size:13px;line-height:1.6;color:#6b7280;">
            Setelah link di atas kedaluwarsa, masuk di <a href="{{ $loginUrl }}" style="color:#111827;">{{ $loginUrl }}</a>. Demi keamanan, segera ganti password di menu profil.
        </p>
    @else
        <p style="margin:0 0 16px;font-size:13px;line-height:1.6;color:#6b7280;">
            Produk ditambahkan ke akun <strong>{{ $email }}</strong> yang sudah terdaftar. Setelah link kedaluwarsa, masuk di
            <a href="{{ $loginUrl }}" style="color:#111827;">{{ $loginUrl }}</a> dengan password akun Anda (atau gunakan "Lupa password").
        </p>
    @endif
@endsection
