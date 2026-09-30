@extends('emails.partials.hellom-layout', ['subject' => $subjectLine])

@php
    $details = $payload['details'] ?? [];
    $manualMethods = $payload['manual_methods'] ?? [];
@endphp

@section('content')
    <h1 style="margin:0 0 16px;font-size:22px;line-height:1.3;font-weight:800;color:#0a0a0a;">{{ $payload['headline'] ?? 'Update pembayaran Hellom' }}</h1>
    <p style="margin:0;">{{ $payload['intro'] ?? 'Ada pembaruan pada pembayaran aplikasi kamu.' }}</p>

    @if (!empty($details))
        <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin:20px 0;border:1px solid #e4e4e7;border-radius:12px;border-collapse:separate;overflow:hidden;">
            @foreach ($details as $label => $value)
                <tr>
                    <td style="padding:10px 14px;width:42%;font-size:13px;color:#71717a;{{ $loop->last ? '' : 'border-bottom:1px solid #f4f4f5;' }}">{{ $label }}</td>
                    <td style="padding:10px 14px;font-size:14px;font-weight:600;color:#18181b;{{ $loop->last ? '' : 'border-bottom:1px solid #f4f4f5;' }}">{{ $value ?: '-' }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    @if (!empty($manualMethods))
        <p style="margin:0 0 10px;font-size:15px;font-weight:700;color:#18181b;">Instruksi pembayaran manual</p>
        @foreach ($manualMethods as $method)
            <div style="margin:0 0 12px;padding:14px;border:1px solid #e4e4e7;border-radius:12px;font-size:14px;">
                <div style="font-weight:700;color:#18181b;">{{ $method['label'] ?? strtoupper((string) ($method['key'] ?? 'manual')) }}</div>
                @if (!empty($method['bank_name']))<div>Bank: {{ $method['bank_name'] }}</div>@endif
                @if (!empty($method['account_name']))<div>Nama: {{ $method['account_name'] }}</div>@endif
                @if (!empty($method['account_number']))<div>Nomor: <strong>{{ $method['account_number'] }}</strong></div>@endif
                @if (!empty($method['instructions']))<div style="margin-top:6px;color:#52525b;">{{ $method['instructions'] }}</div>@endif
                @if (!empty($method['image_url']))<div style="margin-top:8px;"><a href="{{ $method['image_url'] }}" style="color:#3f3f46;">Lihat QR / gambar</a></div>@endif
            </div>
        @endforeach
    @endif

    @if (!empty($payload['cta_url']))
        @include('emails.partials.button', ['url' => $payload['cta_url'], 'label' => $payload['cta_label'] ?? 'Buka'])
    @endif

    @if (!empty($payload['closing']))
        <p style="margin:0;font-size:14px;color:#52525b;">{{ $payload['closing'] }}</p>
    @endif
@endsection
