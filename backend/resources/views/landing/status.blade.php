@php
    $copy = [
        'suspended' => ['Toko ini sedang nonaktif', 'Halaman ini sementara tidak bisa dibuka. Sudah pernah membeli? Link produk kamu tetap bisa dibuka dari email.', 410],
        'empty' => ['Halaman belum terbit', 'Pemilik toko belum menerbitkan halamannya. Coba lagi nanti ya.', 404],
        'not_found' => ['Halaman tidak ditemukan', 'Mungkin produknya sudah tidak dijual atau link-nya salah ketik.', 404],
    ][$kind] ?? ['Halaman tidak ditemukan', '', 404];
@endphp
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex">
<title>{{ $copy[0] }} · {{ $organization->name }}</title>
<style>body{margin:0;min-height:100svh;display:flex;align-items:center;justify-content:center;background:#fafafa;color:#18181b;font-family:{!! $theme['font'] !!};text-align:center;padding:24px}main{max-width:420px}h1{font-size:1.5rem;margin:0 0 8px}p{color:#52525b;line-height:1.6}a{display:inline-flex;min-height:48px;align-items:center;padding:0 20px;border-radius:14px;background:#18181b;color:#fff;text-decoration:none;font-weight:700;margin:6px}</style>
</head>
<body>
<main>
    <h1>{{ $copy[0] }}</h1>
    <p>{{ $copy[1] }}</p>
    @if ($kind === 'not_found')<a href="{{ $homeUrl }}">Ke halaman {{ $organization->name }}</a>@endif
    <a href="/cek-pesanan" style="background:#fff;color:#18181b;border:1px solid #e4e4e7">Cek pesanan saya</a>
</main>
</body>
</html>
