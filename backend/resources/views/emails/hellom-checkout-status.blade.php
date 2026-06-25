@php
    $details = $payload['details'] ?? [];
    $manualMethods = $payload['manual_methods'] ?? [];
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>{{ $subjectLine }}</title>
</head>
<body style="font-family: Arial, sans-serif; color: #18181b; line-height: 1.6;">
    <h2 style="margin-bottom: 8px;">{{ $payload['headline'] ?? 'Update pembayaran Hellom' }}</h2>
    <p style="margin-top: 0;">{{ $payload['intro'] ?? 'Ada pembaruan pada pembayaran aplikasi Anda.' }}</p>

    <table cellpadding="8" cellspacing="0" style="border-collapse: collapse; width: 100%; max-width: 640px; margin: 18px 0; border: 1px solid #e4e4e7;">
        <tbody>
            @foreach ($details as $label => $value)
                <tr>
                    <td style="width: 180px; border: 1px solid #e4e4e7; background: #fafafa; font-weight: 600;">{{ $label }}</td>
                    <td style="border: 1px solid #e4e4e7;">{{ $value ?: '-' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if (!empty($manualMethods))
        <h3 style="margin-bottom: 8px;">Instruksi pembayaran manual</h3>
        @foreach ($manualMethods as $method)
            <div style="margin-bottom: 16px; padding: 14px; border: 1px solid #e4e4e7; border-radius: 12px;">
                <div style="font-weight: 700;">{{ $method['label'] ?? strtoupper((string) ($method['key'] ?? 'manual')) }}</div>
                @if (!empty($method['bank_name']))
                    <div>Bank: {{ $method['bank_name'] }}</div>
                @endif
                @if (!empty($method['account_name']))
                    <div>Nama: {{ $method['account_name'] }}</div>
                @endif
                @if (!empty($method['account_number']))
                    <div>Nomor: {{ $method['account_number'] }}</div>
                @endif
                @if (!empty($method['instructions']))
                    <div style="margin-top: 6px;">{{ $method['instructions'] }}</div>
                @endif
                @if (!empty($method['image_url']))
                    <div style="margin-top: 10px;">
                        <a href="{{ $method['image_url'] }}">{{ $method['image_url'] }}</a>
                    </div>
                @endif
            </div>
        @endforeach
    @endif

    @if (!empty($payload['cta_url']))
        <p style="margin: 18px 0;">
            <a href="{{ $payload['cta_url'] }}" style="display:inline-block;background:#18181b;color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:10px;font-weight:700;">{{ $payload['cta_label'] ?? 'Buka' }}</a>
        </p>
    @endif

    @if (!empty($payload['closing']))
        <p>{{ $payload['closing'] }}</p>
    @endif
</body>
</html>
