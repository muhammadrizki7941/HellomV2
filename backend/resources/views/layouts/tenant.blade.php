<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>{{ $title ?? 'Tenant' }}</title>
    <style>
        body{font-family:ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Arial; margin:0; background:#0f172a; color:#e2e8f0;}
        a{color:#93c5fd; text-decoration:none;}
        .wrap{max-width:980px; margin:0 auto; padding:24px;}
        .card{background:#111827; border:1px solid #1f2937; border-radius:16px; padding:18px;}
        .nav{display:flex; gap:14px; align-items:center; justify-content:space-between; margin-bottom:18px;}
        .btn{display:inline-block; background:#2563eb; color:white; padding:10px 14px; border-radius:12px; font-weight:700;}
        .muted{color:#94a3b8;}
        form{display:inline;}
        button{cursor:pointer;}
    </style>
</head>
<body>
    <div class="wrap">
        <div class="nav">
            <div style="font-weight:900;">Tenant App</div>
            <div style="display:flex; gap:14px; align-items:center;">
                <a href="{{ $tenantHomeUrl ?? '#' }}">Tenant Home</a>
                <a href="{{ $tenantAdminUrl ?? '#' }}">Admin</a>
                <a href="{{ $tenantCashierUrl ?? '#' }}">Cashier</a>
                <a class="btn" href="/gateway">Gateway</a>
            </div>
        </div>

        {{ $slot }}

        <div class="muted" style="margin-top:24px; font-size:12px;">Tenant-scoped: {{ $tenantSlug ?? '-' }}</div>
    </div>
</body>
</html>
