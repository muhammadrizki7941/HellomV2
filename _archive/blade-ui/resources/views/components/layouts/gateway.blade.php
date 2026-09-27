<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>{{ $title ?? 'Gateway' }}</title>
    <style>
        body{font-family:ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Arial; margin:0; background:#f8fafc; color:#0f172a;}
        a{color:#1d4ed8; text-decoration:none;}
        .wrap{max-width:980px; margin:0 auto; padding:24px;}
        .card{background:white; border:1px solid #e2e8f0; border-radius:16px; padding:18px;}
        .nav{display:flex; gap:14px; align-items:center; justify-content:space-between; margin-bottom:18px;}
        .btn{display:inline-block; background:#0f172a; color:white; padding:10px 14px; border-radius:12px; font-weight:700; border:0; cursor:pointer;}
        .muted{color:#64748b;}
        form{display:inline;}
    </style>
</head>
<body>
    <div class="wrap">
        <div class="nav">
            <div style="font-weight:900;">Gateway</div>
            <div style="display:flex; gap:14px; align-items:center;">
                <a href="/gateway">Home</a>
                <a href="/gateway/dev">Dev</a>
                <a href="/gateway/super">Super</a>
                <form method="POST" action="/logout">@csrf<button class="btn" type="submit">Logout</button></form>
            </div>
        </div>

        @if (session('success'))
            <div class="card" style="border-color:#bbf7d0; background:#f0fdf4; color:#14532d; margin-bottom:12px;">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="card" style="border-color:#fecaca; background:#fef2f2; color:#7f1d1d; margin-bottom:12px;">{{ session('error') }}</div>
        @endif

        {{ $slot }}

        <div class="muted" style="margin-top:24px; font-size:12px;">Foundation mode: dummy auth.</div>
    </div>
</body>
</html>
