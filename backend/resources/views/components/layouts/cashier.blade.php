<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>{{ $title ?? 'Cashier' }}</title>
    <style>
        body{font-family:ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Arial; margin:0; background:#fff7ed; color:#7c2d12;}
        a{color:#9a3412; text-decoration:none;}
        .wrap{max-width:980px; margin:0 auto; padding:24px;}
        .card{background:white; border:1px solid #fed7aa; border-radius:16px; padding:18px;}
        .nav{display:flex; gap:14px; align-items:center; justify-content:space-between; margin-bottom:18px;}
        .btn{display:inline-block; background:#ea580c; color:white; padding:10px 14px; border-radius:12px; font-weight:800; border:0; cursor:pointer;}
        .muted{color:#9a3412; opacity:.75;}
        input{width:100%; padding:10px 12px; border-radius:12px; border:1px solid #fdba74;}
        .error{color:#b91c1c; font-size:13px;}
        form{display:inline;}
    </style>
</head>
<body>
    <div class="wrap">
        <div class="nav">
            <div style="font-weight:900;">Cashier App</div>
            <div style="display:flex; gap:14px; align-items:center;">
                <a href="{{ $cashierHomeUrl ?? '#' }}">Home</a>
                <form method="POST" action="{{ $cashierLogoutUrl ?? '#' }}">@csrf<button class="btn" type="submit">Logout</button></form>
            </div>
        </div>

        @if (session('success'))
            <div class="card" style="border-color:#bbf7d0; background:#f0fdf4; color:#14532d; margin-bottom:12px;">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="card" style="border-color:#fecaca; background:#fef2f2; color:#7f1d1d; margin-bottom:12px;">{{ session('error') }}</div>
        @endif

        {{ $slot }}

        <div class="muted" style="margin-top:24px; font-size:12px;">Tenant-scoped cashier.</div>
    </div>
</body>
</html>
