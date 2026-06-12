@php
    $brand = \App\Models\HellomBrandSetting::getSettings();
    $appName = $brand->app_name ?: ($brand->business_name ?: 'Hellom');
    $accent = $brand->accent_color ?: '#facc15';
    $primary = $brand->primary_color ?: '#111827';
    $logo = $brand->logoUrl();
@endphp
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $subject ?? $appName }}</title>
</head>
<body style="margin:0;padding:0;background:#f5f7fb;font-family:Arial,sans-serif;color:#111827;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f7fb;padding:24px 0;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:640px;background:#ffffff;border-radius:20px;overflow:hidden;border:1px solid #e5e7eb;">
                <tr>
                    <td style="padding:28px 32px;background:{{ $primary }};">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                            <tr>
                                <td align="left">
                                    <div style="display:flex;align-items:center;gap:12px;">
                                        @if($logo)
                                            <img src="{{ $logo }}" alt="{{ $appName }}" style="height:40px;max-width:140px;object-fit:contain;background:#ffffff;border-radius:10px;padding:6px;">
                                        @else
                                            <div style="display:inline-flex;align-items:center;justify-content:center;width:40px;height:40px;background:{{ $accent }};border-radius:12px;font-weight:700;color:#111827;">
                                                {{ strtoupper(substr($appName, 0, 2)) }}
                                            </div>
                                        @endif
                                        <div style="display:inline-block;vertical-align:middle;">
                                            <div style="font-size:18px;font-weight:700;color:#ffffff;">{{ $appName }}</div>
                                            @if($brand->tagline)
                                                <div style="font-size:12px;color:rgba(255,255,255,0.78);margin-top:4px;">{{ $brand->tagline }}</div>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <tr>
                    <td style="padding:32px;">
                        @yield('content')
                    </td>
                </tr>
                <tr>
                    <td style="padding:20px 32px;border-top:1px solid #e5e7eb;background:#fafafa;">
                        <div style="font-size:12px;color:#6b7280;line-height:1.6;">
                            <div>{{ $brand->footer_text ?: 'Hellom' }}</div>
                            @if($brand->support_email)
                                <div>Butuh bantuan? Hubungi {{ $brand->support_email }}</div>
                            @endif
                        </div>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
