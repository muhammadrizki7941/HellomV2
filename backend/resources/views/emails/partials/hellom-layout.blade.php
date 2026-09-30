@php
    $brand = \App\Models\HellomBrandSetting::getSettings();
    $appName = $brand->app_name ?: ($brand->business_name ?: 'Hellom');
    // Embedded (cid:) so every mail client shows it, even when the site URL is not reachable.
    $logoFile = $brand->logoFilePath();
    $logo = (isset($message) && $logoFile) ? $message->embed($logoFile) : $brand->logoUrl();
    $font = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
@endphp
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>{{ $subject ?? $appName }}</title>
</head>
<body style="margin:0;padding:0;background:#f4f4f5;font-family:{{ $font }};color:#18181b;-webkit-font-smoothing:antialiased;">
@hasSection('preheader')
    <div style="display:none;max-height:0;overflow:hidden;opacity:0;">@yield('preheader')</div>
@endif
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f5;padding:32px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e4e4e7;">
                <tr><td style="height:4px;background:#F6B400;font-size:0;line-height:0;">&nbsp;</td></tr>
                <tr>
                    <td style="padding:24px 32px 20px;border-bottom:1px solid #f4f4f5;">
                        <table role="presentation" cellpadding="0" cellspacing="0">
                            <tr>
                                @if($logo)
                                    <td style="padding-right:10px;vertical-align:middle;">
                                        <img src="{{ $logo }}" alt="{{ $appName }}" width="36" height="36" style="display:block;width:36px;height:36px;border-radius:9px;border:0;">
                                    </td>
                                @endif
                                <td style="vertical-align:middle;font-size:18px;font-weight:800;letter-spacing:-0.2px;color:#0a0a0a;">{{ $appName }}</td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <tr>
                    <td style="padding:32px 32px 28px;font-size:15px;line-height:1.65;color:#3f3f46;">
                        @yield('content')
                    </td>
                </tr>
                <tr>
                    <td style="padding:20px 32px 24px;background:#fafafa;border-top:1px solid #f4f4f5;font-size:12px;line-height:1.6;color:#71717a;">
                        @if($brand->support_email)
                            Butuh bantuan? Balas email ini atau hubungi <a href="mailto:{{ $brand->support_email }}" style="color:#3f3f46;">{{ $brand->support_email }}</a>.<br>
                        @endif
                        Email ini dikirim otomatis oleh {{ $appName }}.<br>
                        {{ $brand->footer_text ?: '© ' . date('Y') . ' ' . $appName }}
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
