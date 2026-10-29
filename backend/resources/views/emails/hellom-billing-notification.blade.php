@extends('emails.partials.hellom-layout')

@section('preheader'){{ $statusLabel }} — paket {{ $planName }} ({{ $appName }}) untuk {{ $organizationName }}.@endsection

@section('content')
    <h1 style="margin:0 0 16px;font-size:22px;line-height:1.3;font-weight:800;color:#0a0a0a;">{{ $statusLabel }}</h1>
    <p style="margin:0 0 18px;font-size:15px;line-height:1.75;color:#374151;">
        Terima kasih. Berikut pembaruan paket <strong>{{ $planName }}</strong> untuk aplikasi <strong>{{ $appName }}</strong> pada organisasi <strong>{{ $organizationName }}</strong>.
    </p>
    <table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;background:#f9fafb;border-radius:16px;overflow:hidden;">
        <tr>
            <td style="padding:14px 16px;border-bottom:1px solid #e5e7eb;font-size:14px;color:#6b7280;">Nominal</td>
            <td style="padding:14px 16px;border-bottom:1px solid #e5e7eb;font-size:14px;font-weight:700;color:#111827;text-align:right;">Rp {{ number_format($amount, 0, ',', '.') }}</td>
        </tr>
        <tr>
            <td style="padding:14px 16px;border-bottom:1px solid #e5e7eb;font-size:14px;color:#6b7280;">Mulai aktif</td>
            <td style="padding:14px 16px;border-bottom:1px solid #e5e7eb;font-size:14px;font-weight:700;color:#111827;text-align:right;">{{ $startsAt?->format('d M Y H:i') ?? '-' }}</td>
        </tr>
        <tr>
            <td style="padding:14px 16px;font-size:14px;color:#6b7280;">Berakhir</td>
            <td style="padding:14px 16px;font-size:14px;font-weight:700;color:#111827;text-align:right;">{{ $endsAt?->format('d M Y H:i') ?? '-' }}</td>
        </tr>
    </table>
    @include('emails.partials.button', ['url' => \App\Support\FrontendUrl::to('/dashboard/billing'), 'label' => 'Lihat tagihan dan masa aktif'])
    <p style="font-size:14px;color:#52525b;">Periksa masa aktif paket dan simpan email ini sebagai referensi. Butuh bantuan menggunakan aplikasi? Balas email ini.</p>
@endsection
