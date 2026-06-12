<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $pageTitle ?? ($brand->meta_title ?? $brand->app_name ?? 'Hellom') }}</title>
    <meta name="description" content="{{ $metaDescription ?? ($brand->meta_description ?? 'Hellom membantu bisnis F&B menjalankan POS, promo, dan landing page yang siap dipakai.') }}">
    <link rel="canonical" href="{{ $canonicalUrl ?? url()->current() }}">
    <meta property="og:type" content="website">
    <meta property="og:title" content="{{ $pageTitle ?? ($brand->meta_title ?? $brand->app_name ?? 'Hellom') }}">
    <meta property="og:description" content="{{ $metaDescription ?? ($brand->meta_description ?? 'Hellom membantu bisnis F&B menjalankan POS, promo, dan landing page yang siap dipakai.') }}">
    <meta property="og:url" content="{{ $canonicalUrl ?? url()->current() }}">
    <meta property="og:image" content="{{ $ogImage ?? ($brand->logoUrl() ?: url('/hellom/assets/logo-hellom.png')) }}">
    <meta name="twitter:card" content="summary_large_image">
    @if($brand->faviconUrl())
        <link rel="icon" href="{{ $brand->faviconUrl() }}">
    @endif
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:ital,wght@0,400;0,500;0,600;0,700;0,800;1,700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('meta')
    <style>
        :root {
            --brand-primary: {{ $brand->primary_color ?: '#0c0c0c' }};
            --brand-secondary: {{ $brand->secondary_color ?: '#334155' }};
            --brand-accent: {{ $brand->accent_color ?: '#f5c518' }};
            --brand-bg: {{ $brand->background_color ?: '#080808' }};
            --brand-surface: #111111;
            --brand-surface-2: #171717;
            --brand-border: rgba(255,255,255,0.08);
            --brand-text: #f8fafc;
            --brand-muted: rgba(248,250,252,0.7);
            --brand-warm: rgba(245,197,24,0.14);
            --font-display: 'Montserrat', sans-serif;
            --font-body: 'Plus Jakarta Sans', sans-serif;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            background:
                radial-gradient(circle at top, rgba(245, 197, 24, 0.10), transparent 25%),
                linear-gradient(180deg, #050505 0%, var(--brand-bg) 100%);
            color: var(--brand-text);
            font-family: var(--font-body);
        }

        .font-display {
            font-family: var(--font-display);
        }

        .public-shell {
            position: relative;
            min-height: 100vh;
            overflow-x: hidden;
        }

        .public-shell::before,
        .public-shell::after {
            content: '';
            position: fixed;
            inset: auto;
            pointer-events: none;
            z-index: 0;
            border-radius: 9999px;
            filter: blur(90px);
            opacity: 0.14;
        }

        .public-shell::before {
            top: 6rem;
            left: -5rem;
            width: 16rem;
            height: 16rem;
            background: var(--brand-accent);
        }

        .public-shell::after {
            right: -4rem;
            top: 20rem;
            width: 18rem;
            height: 18rem;
            background: #2563eb;
        }

        .section-shell {
            position: relative;
            z-index: 1;
        }

        .surface-card {
            background: linear-gradient(180deg, rgba(255,255,255,0.04), rgba(255,255,255,0.02));
            border: 1px solid var(--brand-border);
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.32);
        }

        .accent-ring {
            box-shadow: 0 0 0 1px rgba(245, 197, 24, 0.22), 0 24px 50px rgba(245, 197, 24, 0.10);
        }

        .text-muted {
            color: var(--brand-muted);
        }

        .bg-accent-soft {
            background: var(--brand-warm);
        }

        .text-accent {
            color: var(--brand-accent);
        }

        .border-accent-soft {
            border-color: rgba(245, 197, 24, 0.20);
        }
    </style>
</head>
<body>
    <div class="public-shell">
        @yield('content')
    </div>
    @stack('scripts')
</body>
</html>
