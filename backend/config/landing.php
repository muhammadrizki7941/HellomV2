<?php

/*
| Hellom Page (landing builder + online shop) settings.
*/
return [
    /*
    | Usernames that can never be a shop address (hellomspace.com/{username}): every
    | first path segment the React app or the server uses, plus words that could be used
    | to impersonate Hellom. Keep in sync with frontend/src/App.tsx top-level routes.
    */
    'reserved_usernames' => [
        // app & site routes
        'admin', 'api', 'app', 'apps', 'auth', 'akses', 'aplikasi', 'beli', 'blog', 'cek-pesanan', 'checkout', 'contact', 'customer',
        'dashboard', 'faq', 'forgot-password', 'hellom', 'help', 'insights', 'invitation', 'kebijakan', 'kontak', 'layanan', 'login',
        'logout', 'media', 'member', 'p', 'pesanan', 'portofolio', 'pos', 'produk', 'refund-policy', 'register', 'reset-password', 'settings',
        'socket.io', 'storage', 'sw.js', 'tentang', 'terms', 'wawasan', 'hellom-assets', 'assets', 'build', 'static', 'public',
        // words that look official
        'hellomspace', 'official', 'support', 'bantuan', 'cs', 'billing', 'payment', 'pembayaran', 'bayar', 'invoice', 'status',
        'security', 'keamanan', 'verify', 'verifikasi', 'root', 'system', 'superadmin', 'super-admin', 'webmaster', 'www', 'mail',
        'email', 'toko', 'shop', 'store', 'akun', 'account', 'profile', 'profil', 'user', 'users', 'null', 'undefined',
    ],

    /* Username rules: 3–30 chars, lowercase letters/digits/dash, not starting/ending with a dash. */
    'username_pattern' => '/^[a-z0-9](?:[a-z0-9-]{1,28})[a-z0-9]$/',

    /* Pages a shop may publish without a paid plan (owner decision Q3). */
    'free_pages' => 1,

    /* Seconds the server-rendered public page may be cached by browsers / CDN. */
    'public_cache_seconds' => 60,
];
