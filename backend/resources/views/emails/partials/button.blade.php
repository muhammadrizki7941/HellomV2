{{-- Bulletproof button for emails: @include('emails.partials.button', ['url' => …, 'label' => …]) --}}
<table role="presentation" cellpadding="0" cellspacing="0" style="margin:24px 0;">
    <tr>
        <td style="border-radius:10px;background:#0a0a0a;">
            <a href="{{ $url }}" target="_blank" rel="noopener" style="display:inline-block;padding:13px 24px;font-size:15px;font-weight:700;line-height:1;color:#ffffff;text-decoration:none;border-radius:10px;">{{ $label }}</a>
        </td>
    </tr>
</table>
<p style="margin:0 0 20px;font-size:12px;line-height:1.6;color:#71717a;">
    Tombol tidak bisa ditekan? Salin link ini ke browser:<br>
    <a href="{{ $url }}" style="color:#52525b;word-break:break-all;">{{ $url }}</a>
</p>
