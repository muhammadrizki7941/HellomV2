<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $brand->business_name ?? 'Hellom POS' }} | Kasir + Self Order Online UMKM</title>
    <script src="https://cdn.tailwindcss.com/3.4.0"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        'jakarta': ['Plus Jakarta Sans', 'sans-serif'],
                    }
                }
            }
        }
    </script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    @if($brand && $brand->faviconUrl())
        <link rel="icon" href="{{ $brand->faviconUrl() }}">
    @endif
    <style>
        body {
            font-family: {{ $brand->font_family ?? 'system-ui' }}, 'Plus Jakarta Sans', sans-serif;
            background-color: {{ $brand->background_color ?? '#fdfdfd' }};
            scroll-behavior: smooth;
            overflow-x: hidden;
            --primary-color: {{ $brand->primary_color ?? '#0f172a' }};
            --secondary-color: {{ $brand->secondary_color ?? '#334155' }};
            --accent-color: {{ $brand->accent_color ?? '#22C55E' }};
            --background-color: {{ $brand->background_color ?? '#fdfdfd' }};
            --button-radius: {{ $brand->button_radius ?? 18 }}px;
            @if($brand && $brand->backgroundImageUrl())
            background-image: url('{{ $brand->backgroundImageUrl() }}');
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
            @endif
            @if($brand && $brand->background_gradient)
            background: {{ $brand->background_gradient }};
            @endif
        }

        @if($brand && $brand->backgroundImageUrl() && $brand->background_overlay_opacity > 0)
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: {{ $brand->background_color ?? '#fdfdfd' }};
            opacity: {{ $brand->background_overlay_opacity }};
            z-index: -1;
        }
        @endif

        /* Background Patterns */
        @if($brand && $brand->background_pattern === 'mesh')
        .bg-mesh {
            background-color: {{ $brand->background_color ?? '#fdfdfd' }};
            background-image:
                radial-gradient(at 0% 0%, rgba(34, 197, 94, 0.05) 0px, transparent 50%),
                radial-gradient(at 100% 0%, rgba(59, 130, 246, 0.03) 0px, transparent 50%);
        }
        @elseif($brand && $brand->background_pattern === 'dots')
        .bg-mesh {
            background-color: {{ $brand->background_color ?? '#fdfdfd' }};
            background-image: radial-gradient(circle, rgba(34, 197, 94, 0.1) 1px, transparent 1px);
            background-size: 20px 20px;
        }
        @elseif($brand && $brand->background_pattern === 'lines')
        .bg-mesh {
            background-color: {{ $brand->background_color ?? '#fdfdfd' }};
            background-image: linear-gradient(90deg, rgba(34, 197, 94, 0.1) 1px, transparent 1px);
            background-size: 30px 30px;
        }
        @elseif($brand && $brand->background_pattern === 'waves')
        .bg-mesh {
            background-color: {{ $brand->background_color ?? '#fdfdfd' }};
            background-image: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%2322c55e' fill-opacity='0.05'%3E%3Cpath d='M30 30c0-11.046-8.954-20-20-20s-20 8.954-20 20 8.954 20 20 20 20-8.954 20-20zm15 0c0-11.046-8.954-20-20-20s-20 8.954-20 20 8.954 20 20 20 20-8.954 20-20z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
        }
        @else
        .bg-mesh {
            background-color: {{ $brand->background_color ?? '#fdfdfd' }};
        }
        @endif

        .btn-green {
            background: linear-gradient(135deg, var(--accent-color) 0%, var(--primary-color) 100%);
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            position: relative;
            overflow: hidden;
            border-radius: var(--button-radius);
        }

        .btn-green:hover {
            transform: translateY(-3px) scale(1.02);
            box-shadow: 0 20px 25px -5px var(--accent-color)4D;
        }

        /* Shimmer Effect for CTA */
        .shimmer::after {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: linear-gradient(45deg, transparent, rgba(255,255,255,0.3), transparent);
            transform: rotate(45deg);
            animation: shimmer 3s infinite;
        }

        @keyframes shimmer {
            0% { left: -100%; }
            100% { left: 100%; }
        }

        /* Floating Animation */
        .floating {
            animation: float 6s ease-in-out infinite;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-15px); }
        }

        /* Vector-like Floating Animation */
        .vector-float {
            animation: vectorFloat 8s ease-in-out infinite;
        }

        @keyframes vectorFloat {
            0%, 100% {
                transform: translateY(0) rotate(0deg) scale(1);
            }
            25% {
                transform: translateY(-8px) rotate(0.5deg) scale(1.02);
            }
            50% {
                transform: translateY(-12px) rotate(-0.3deg) scale(0.98);
            }
            75% {
                transform: translateY(-6px) rotate(0.2deg) scale(1.01);
            }
        }

        /* Subtle Wobble Animation */
        .wobble-float {
            animation: wobbleFloat 10s ease-in-out infinite;
        }

        @keyframes wobbleFloat {
            0%, 100% {
                transform: translateY(0) translateX(0) rotate(0deg);
            }
            20% {
                transform: translateY(-5px) translateX(2px) rotate(0.2deg);
            }
            40% {
                transform: translateY(-10px) translateX(-1px) rotate(-0.1deg);
            }
            60% {
                transform: translateY(-8px) translateX(1px) rotate(0.1deg);
            }
            80% {
                transform: translateY(-3px) translateX(-1px) rotate(-0.05deg);
            }
        }

        /* Reveal on Scroll */
        .reveal {
            opacity: 0;
            transform: translateY(30px);
            transition: all 0.8s ease-out;
        }

        .reveal.active {
            opacity: 1;
            transform: translateY(0);
        }

        /* Stagger reveal children which use .delay-* classes */
        .reveal .delay-0,
        .reveal .delay-05,
        .reveal .delay-08,
        .reveal .delay-1,
        .reveal .delay-12,
        .reveal .delay-15,
        .reveal .delay-18,
        .reveal .delay-2,
        .reveal .delay-4 {
            opacity: 0;
            transform: translateY(18px);
            transition: opacity 0.6s cubic-bezier(.22,.9,.24,1), transform 0.6s cubic-bezier(.22,.9,.24,1);
            will-change: opacity, transform;
        }

        .reveal.active .delay-0,
        .reveal.active .delay-05,
        .reveal.active .delay-08,
        .reveal.active .delay-1,
        .reveal.active .delay-12,
        .reveal.active .delay-15,
        .reveal.active .delay-18,
        .reveal.active .delay-2,
        .reveal.active .delay-4 {
            opacity: 1;
            transform: translateY(0);
        }

        .soft-shadow {
            box-shadow: 0 10px 40px -10px rgba(0, 0, 0, 0.04);
        }

        .feature-card {
            transition: all 0.3s ease;
            border: 1px solid rgba(226, 232, 240, 0.5);
        }

        .feature-card:hover {
            background: var(--bg3);
            border-color: var(--accent);
            transform: translateY(-10px);
            box-shadow: 0 20px 40px -15px rgba(0,0,0,0.6);
        }

        .feature-icon {
            animation: pulse 2s infinite;
        }

        .feature-card:hover .feature-icon {
            animation: bounce 0.6s ease;
        }

        .feature-icon:nth-child(2) {
            animation: rotate 3s linear infinite;
        }

        @keyframes rotate {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        @keyframes bounce {
            0%, 20%, 50%, 80%, 100% { transform: translateY(0); }
            40% { transform: translateY(-10px); }
            60% { transform: translateY(-5px); }
        }

        .pricing-card {
            transition: all 0.4s ease;
            opacity: 0;
            transform: translateY(20px);
            animation: fadeInUp 0.6s ease forwards;
        }
        .pricing-card:hover {
            transform: scale(1.03);
        }

        .pricing-card:nth-child(1) { animation-delay: 0.1s; }
        .pricing-card:nth-child(2) { animation-delay: 0.2s; }
        .pricing-card:nth-child(3) { animation-delay: 0.3s; }

        @keyframes fadeInUp {
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .sticky-cta {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            z-index: 100;
            padding: 1.25rem;
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(15px);
            border-top: 1px solid rgba(226, 232, 240, 0.8);
            transform: translateY(100%);
            transition: transform 0.5s ease;
        }

        .sticky-cta.visible {
            transform: translateY(0);
        }
        /* Dark theme variables and lightweight overrides (adaptation from provided HTML) */
        :root {
            --bg: {{ $brand->background_color ?? '#0c0c0c' }};
            --bg2: {{ $brand->surface_color ?? '#141414' }};
            --bg3: {{ $brand->card_color ?? '#1c1c1c' }};
            --accent: {{ $brand->accent_color ?? '#FFB020' }};
            --accent2: {{ $brand->accent2_color ?? '#ff6b35' }};
            --text: {{ $brand->text_color ?? '#f0ede8' }};
            --muted: {{ $brand->muted_color ?? '#888580' }};
            --border: rgba(255,255,255,0.06);
            --font-display: 'Space Grotesk', sans-serif;
            --font-body: 'Plus Jakarta Sans', sans-serif;
            /* overlay / alpha-variants for accent colors (8-digit hex appended alpha) */
            --accent-0d: {{ $brand->accent_color ?? '#FFB020' }}0D;
            --accent-33: {{ $brand->accent_color ?? '#FFB020' }}33;
            --accent-1a: {{ $brand->accent_color ?? '#FFB020' }}1A;
            --primary-1a: {{ $brand->primary_color ?? '#3b82f6' }}1A;
        }

        body {
            background: var(--bg);
            color: var(--text);
            font-family: var(--font-body);
        }

        .surface-card {
            background: var(--bg2) !important;
            border: 1px solid var(--border) !important;
            color: var(--text) !important;
        }

        .text-dark { color: var(--text) !important; }
        .text-muted { color: var(--muted) !important; }

        .text-slate-900 { color: var(--text) !important; }
        .text-slate-600, .text-slate-500, .text-slate-400 { color: var(--muted) !important; }

        .btn-green { background: linear-gradient(135deg, var(--accent) 0%, var(--accent2) 100%) !important; color: #0c0c0c !important; }
        .btn-green:hover { box-shadow: 0 10px 30px rgba(0,0,0,0.2); transform: translateY(-2px); }

        /* Accent utility classes */
        .hero-badge { display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.35rem 1rem; border-radius: 999px; background: var(--accent-0d); color: var(--accent); border: 1px solid var(--accent-1a); font-size: 0.85rem; font-weight: 700; }
        .accent-dot { width: 6px; height: 6px; border-radius: 50%; background: var(--accent); display: inline-block; }
        .accent-panel { background-color: var(--accent-0d); border-color: var(--accent-1a); color: var(--accent); }
        .accent-text { color: var(--accent) !important; }
        .accent-border { border-color: var(--accent-1a) !important; }
        .accent-svg { color: var(--accent); }

        .accent-circle { background-color: var(--accent-33); }
        .primary-circle { background-color: var(--primary-1a); }
        .accent-bubble { background-color: var(--accent-1a); }
        .feature-icon { background: var(--accent-1a); color: var(--accent); }
        .final-cta-pattern { background-image: radial-gradient(var(--accent) 0.5px, transparent 0.5px); background-size: 30px 30px; }

        /* Popular / highlighted helpers */
        .popular { border-color: var(--accent) !important; box-shadow: 0 20px 40px -15px var(--accent-1a) !important; }
        .badge-popular { background-color: var(--accent) !important; color: #071012 !important; }

        /* Animation + transition delay helpers to match React reference timing (staggered) */
        .delay-0 { animation-delay: 0s !important; transition-delay: 0s !important; will-change: opacity, transform; }
        .delay-05 { animation-delay: 0s !important; transition-delay: 0s !important; will-change: opacity, transform; }
        .delay-08 { animation-delay: 0.1s !important; transition-delay: 0.1s !important; will-change: opacity, transform; }
        .delay-12 { animation-delay: 0.2s !important; transition-delay: 0.2s !important; will-change: opacity, transform; }
        .delay-15 { animation-delay: 0.3s !important; transition-delay: 0.3s !important; will-change: opacity, transform; }
        .delay-18 { animation-delay: 0.4s !important; transition-delay: 0.4s !important; will-change: opacity, transform; }
        .delay-1 { animation-delay: 0.5s !important; transition-delay: 0.5s !important; will-change: opacity, transform; }
        .delay-2 { animation-delay: 0.8s !important; transition-delay: 0.8s !important; will-change: opacity, transform; }
        .delay-4 { animation-delay: 2s !important; transition-delay: 2s !important; will-change: opacity, transform; }

        header { color: var(--text); }
        header a { color: var(--muted); }
        header a:hover { color: var(--text); }
        /* Stagger child cards in features and pricing sections */
        #fitur .reveal .feature-card {
            opacity: 0;
            transform: translateY(18px);
            transition: opacity 0.6s cubic-bezier(.22,.9,.24,1), transform 0.6s cubic-bezier(.22,.9,.24,1);
            will-change: opacity, transform;
        }
        #fitur .reveal.active .feature-card { opacity: 1; transform: translateY(0); }
        #fitur .reveal .feature-card:nth-child(1) { transition-delay: 0.05s; }
        #fitur .reveal .feature-card:nth-child(2) { transition-delay: 0.12s; }
        #fitur .reveal .feature-card:nth-child(3) { transition-delay: 0.18s; }
        #fitur .reveal .feature-card:nth-child(4) { transition-delay: 0.24s; }
        #fitur .reveal .feature-card:nth-child(5) { transition-delay: 0.30s; }
        #fitur .reveal .feature-card:nth-child(6) { transition-delay: 0.36s; }

        /* Pricing cards stagger */
        #paket .reveal .pricing-card {
            opacity: 0;
            transform: translateY(18px);
            transition: opacity 0.6s cubic-bezier(.22,.9,.24,1), transform 0.6s cubic-bezier(.22,.9,.24,1);
            will-change: opacity, transform;
        }
        #paket .reveal.active .pricing-card { opacity: 1; transform: translateY(0); }
        #paket .reveal .pricing-card:nth-child(1) { transition-delay: 0.08s; }
        #paket .reveal .pricing-card:nth-child(2) { transition-delay: 0.14s; }
        #paket .reveal .pricing-card:nth-child(3) { transition-delay: 0.20s; }
        /* Small helpers */
        .why-desc { color: var(--muted); font-size: 0.95rem; line-height: 1.7; }
        .price-value.accent { color: var(--accent); }
        .price-value.custom { font-size: 1.8rem; }
        /* Mobile menu panel (hidden by default) */
        .mobile-menu-panel { width: 320px; max-width: 85vw; }

        /* MOBILE + CTA ADJUSTMENTS */
        @media (max-width: 768px) {
            .hero-section { padding-top: 3.5rem !important; padding-bottom: 3rem !important; padding-left: 1rem !important; padding-right: 1rem !important; }
            .hero-section .reveal h1, .hero-section .text-5xl { font-size: 2.25rem !important; line-height: 1.05 !important; }
            .hero-section .reveal p { font-size: 1rem !important; }
            .hero-eyebrow { margin-bottom: 1rem !important; padding: 0.25rem 0.85rem !important; }
            .hero-actions { gap: 0.75rem !important; }
            .hero-actions .btn-big, .hero-actions .btn-big-ghost, .hero-actions button { width: 100% !important; }

            .section-title { font-size: 1.6rem !important; }
            .cta-section { padding: 4rem 1.25rem !important; }
            .cta-title { font-size: 2rem !important; }
            .cta-sub { font-size: 1rem !important; }
            .cta-btns { flex-direction: column !important; gap: 0.75rem !important; align-items: stretch !important; }
            .cta-btns .btn-cta-main, .cta-btns .btn-cta-ghost { width: 100% !important; padding: 0.9rem 1rem !important; font-size: 1rem !important; border-radius: 14px !important; justify-content:center !important; }

            .sticky-cta { background: rgba(12,12,12,0.9) !important; border-top: 1px solid var(--border) !important; }
            .mobile-menu-panel { width: 90vw !important; max-width: 360px !important; }

            .stats-inner { padding: 1rem !important; grid-template-columns: 1fr 1fr !important; }
            .pricing-box { padding: 2rem !important; grid-template-columns: 1fr !important; }
            .testi-grid { grid-template-columns: 1fr !important; }
            .services-grid { gap: 0.5rem !important; }
        }
        /* Brand accent overrides: replace default lime/green utilities with brand --accent (yellow/orange) */
        ::selection { background: var(--accent) !important; color: #071012 !important; }
        .bg-lime-400 { background-color: var(--accent) !important; }
        .hover\:bg-lime-300:hover { background-color: var(--accent-33) !important; }
        .hover\:text-green-600:hover { color: var(--accent) !important; }
        .text-green-600 { color: var(--accent) !important; }
        .text-green-800 { color: #071012 !important; }
        .bg-green-100 { background-color: var(--accent-0d) !important; color: #071012 !important; }
        .from-green-200\/20 { --tw-gradient-from: var(--accent-33) !important; }
        .accent-text { color: var(--accent) !important; }
    </style>
</head>
<body class="bg-[#050505] text-white selection:bg-lime-400 selection:text-black antialiased">
    <div class="min-h-screen overflow-x-hidden">

    <!-- Sticky Mobile CTA -->
    <div id="mobileCta" class="sticky-cta md:hidden flex justify-center">
        <button class="w-full btn-green shimmer text-white py-4 rounded-2xl font-bold shadow-lg">
            {{ $settings->hero_cta_primary }}
        </button>
    </div>

    <!-- Top Branding -->
    <header class="p-6 flex justify-between items-center max-w-6xl mx-auto relative z-10">
        <div class="flex items-center gap-2 group cursor-pointer">
                <img src="/hellom/assets/hellom.png" class="w-10 h-10 rounded-xl" alt="{{ $brand->business_name ?? 'Hellom' }}">
            </div>
        <div class="md:hidden flex items-center gap-4">
            <button id="navToggle" aria-label="Open menu" aria-expanded="false" class="p-2 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2" onclick="toggleMobileMenu()">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" class="text-slate-400">
                    <path d="M4 6h16M4 12h16M4 18h16" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </button>
        </div>
        <div class="hidden md:flex items-center gap-8">
            <nav class="flex gap-6 text-sm font-bold text-slate-500">
                <a href="#fitur" class="hover:text-green-600 transition">Fitur</a>
                <a href="#paket" class="hover:text-green-600 transition">Harga Paket</a>
                <a href="#perbandingan" class="hover:text-green-600 transition">Kenapa Kami?</a>
            </nav>
            <a href="#cta" class="bg-lime-400 text-black px-4 py-2 rounded-full text-sm font-semibold hover:bg-lime-300 transition-colors">Mulai Gratis</a>
            <a href="/login" class="text-sm font-bold bg-slate-100 px-5 py-2.5 rounded-xl text-slate-700 hover:bg-slate-200 transition">Masuk</a>
        </div>
    </header>

    <!-- Mobile Menu (hidden on md+) -->
    <div id="mobileMenu" class="fixed inset-0 z-50 hidden md:hidden">
        <div class="absolute inset-0 bg-black/60" onclick="toggleMobileMenu()"></div>
        <div class="absolute top-0 right-0 h-full mobile-menu-panel surface-card p-6 overflow-y-auto">
            <div class="flex items-center justify-between mb-6">
                <div class="flex items-center gap-2">
                    <img src="/hellom/assets/hellom.png" class="w-9 h-9 rounded-lg" alt="{{ $brand->business_name ?? 'Hellom' }}">
                </div>
                <button onclick="toggleMobileMenu()" aria-label="Close menu" class="p-2 rounded-md">✕</button>
            </div>
            <nav class="flex flex-col gap-4">
                <a href="#fitur" class="py-3 border-b border-white/5">Fitur</a>
                <a href="#paket" class="py-3 border-b border-white/5">Harga Paket</a>
                <a href="#perbandingan" class="py-3 border-b border-white/5">Kenapa Kami?</a>
                <a href="/login" class="mt-4 inline-block btn-ghost py-3 px-4 rounded-lg">Masuk</a>
            </nav>
        </div>
    </div>

    <!-- Hero Section with Floating Banner -->
    <section class="hero-section relative w-full pt-32 pb-20 px-6 max-w-7xl mx-auto overflow-hidden">
        <!-- Background decorative elements -->
        <div class="absolute inset-0 -z-10">
            <div class="absolute top-1/4 left-1/4 w-32 h-32 bg-gradient-to-br from-blue-200/20 to-purple-200/20 rounded-full blur-xl vector-float delay-0"></div>
            <div class="absolute bottom-1/4 right-1/4 w-24 h-24 bg-gradient-to-br from-green-200/20 to-blue-200/20 rounded-full blur-lg wobble-float delay-2"></div>
            <div class="absolute top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-1/2 w-40 h-40 bg-gradient-to-br from-pink-200/10 to-yellow-200/10 rounded-full blur-2xl floating delay-4"></div>
        </div>

        <!-- Floating Banner Image -->
        @if($settings->bannerImageUrl())
        <div class="absolute top-1/2 right-8 transform -translate-y-1/2 z-10 vector-float delay-1">
            <img src="{{ $settings->bannerImageUrl() }}"
                 alt="Landing Page Banner"
                 class="w-64 h-64 md:w-80 md:h-80 lg:w-96 lg:h-96 object-contain rounded-3xl shadow-2xl border-4 border-white/20 backdrop-blur-sm">
            <div class="absolute inset-0 bg-gradient-to-t from-white/10 via-transparent to-white/5 rounded-3xl"></div>
        </div>
        @endif

        <!-- Floating Hero Text -->
        <div class="max-w-4xl mx-auto text-left relative z-20 flex flex-col items-start gap-6">
            <div class="absolute top-0 left-0 w-32 h-32 rounded-full blur-3xl floating delay-1 accent-circle"></div>
            <div class="absolute bottom-0 right-0 w-48 h-48 rounded-full blur-3xl floating primary-circle"></div>
            <div class="absolute top-1/2 left-1/4 w-24 h-24 opacity-10 floating delay-2">
                <svg viewBox="0 0 100 100" class="w-full h-full accent-svg">
                    <circle cx="50" cy="50" r="40" fill="none" stroke="currentColor" stroke-width="2"/>
                    <path d="M30 50 L45 65 L70 40" stroke="currentColor" stroke-width="3" fill="none"/>
                </svg>
            </div>

            <div class="reveal active">
                <div class="inline-flex items-center gap-2 px-4 py-1.5 rounded-full text-xs font-bold mb-8 wobble-float hero-badge delay-05">
                    <span class="w-2 h-2 rounded-full animate-pulse accent-dot"></span>
                    {{ $settings->hero_badge }}
                </div>

                <h1 class="text-5xl md:text-7xl lg:text-8xl font-extrabold leading-[1.1] mb-6 tracking-tighter vector-float text-dark delay-08">
                    {{ $settings->hero_title }}
                </h1>

                <p class="text-lg md:text-xl text-neutral-400 max-w-2xl leading-relaxed mt-4 mb-6 font-medium delay-12">
                    {{ $settings->hero_subtitle }}
                </p>

                <div class="flex flex-wrap items-center gap-4 mt-6 mb-8 delay-15">
                        <a href="#cta" class="bg-lime-400 text-black px-6 py-3 rounded-full font-semibold flex items-center gap-2 hover:bg-lime-300 transition-colors">
                            {{ $settings->hero_cta_primary }}
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14"></path><path d="M12 5l7 7-7 7"></path></svg>
                        </a>
                    <a href="#portofolio" class="px-6 py-3 rounded-full font-medium border border-white/20 hover:bg-white/5 transition-colors">
                        {{ $settings->hero_cta_secondary }}
                    </a>
                </div>

                <p class="text-sm font-bold text-slate-400 mb-20 flex items-center gap-2 delay-18">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 accent-svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" /></svg>
                    {{ $settings->hero_trial_text }}
                </p>
            </div>
        </div>
    </section>

    <!-- Problem Section -->
    <section class="py-16 px-6 overflow-hidden">
        <div class="max-w-4xl mx-auto surface-card p-10 md:p-20 rounded-[3rem] soft-shadow border reveal">
            <h2 class="text-3xl md:text-4xl font-extrabold mb-12 text-center tracking-tight text-slate-900">Masalah yang bikin F&B nggak rapi:</h2>
            <div class="space-y-4 max-w-lg mx-auto mb-12">
                @foreach($settings->problems ?? [] as $problem)
                <div class="group flex items-center gap-5 p-3 rounded-2xl hover:bg-red-50 transition-colors">
                    <div class="w-8 h-8 bg-red-100 rounded-full flex items-center justify-center flex-shrink-0 text-red-500 font-bold group-hover:scale-110 transition-transform text-sm">✕</div>
                    <p class="text-slate-700 font-semibold">{{ $problem }}</p>
                </div>
                @endforeach
            </div>
            <div class="p-6 rounded-2xl border text-center accent-panel accent-border">
                <p class="font-extrabold text-xl accent-text">{{ $settings->problems_solution }}</p>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section id="fitur" class="py-20 px-6 max-w-7xl mx-auto">
        <div class="text-center mb-20 reveal">
            <h2 class="text-4xl font-extrabold mb-4 tracking-tight delay-08">{{ $settings->features_title }}</h2>
            <p class="text-slate-500 font-medium delay-12">{{ $settings->features_subtitle }}</p>
        </div>

        <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-6 reveal">
            @foreach($settings->features ?? [] as $index => $feature)
            <div class="feature-card p-10 surface-card rounded-[2.5rem] soft-shadow {{ $index == 1 ? 'border-2 shadow-xl relative overflow-hidden popular' : '' }}">
                @if($index == 1)
                <div class="absolute -right-4 -top-4 w-24 h-24 rounded-full blur-2xl accent-bubble"></div>
                @endif
                <div class="w-14 h-14 rounded-2xl flex items-center justify-center mb-8 font-bold text-2xl feature-icon">
                    @if($feature['icon'] == '🌐')
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 accent-svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9" />
                        </svg>
                    @else
                        {{ $feature['icon'] }}
                    @endif
                </div>
                <h3 class="font-extrabold text-xl mb-3 tracking-tight {{ $index == 1 ? 'accent-text' : '' }}">{{ $feature['title'] }}</h3>
                <p class="text-slate-500 leading-relaxed font-medium">{{ $feature['description'] }}</p>
            </div>
            @endforeach
        </div>
    </section>

    <!-- Pricing Section -->
    <section id="paket" class="py-20 bg-slate-50 px-6">
        <div class="max-w-6xl mx-auto">
            <div class="text-center mb-16 reveal">
                <h2 class="text-4xl font-extrabold mb-4 tracking-tight delay-08">{{ $settings->pricing_title }}</h2>
                <p class="text-slate-500 font-medium delay-12">{{ $settings->pricing_subtitle }}</p>
                
                <!-- Billing Toggle -->
                <div class="flex items-center justify-center mt-8 mb-8">
                    <span class="text-sm font-medium text-slate-600 mr-3">Bulanan</span>
                    <button id="billingToggle" class="relative inline-flex h-6 w-11 items-center rounded-full transition-colors focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 bg-gray-300">
                        <span class="inline-block h-4 w-4 transform rounded-full bg-white transition-transform translate-x-1" id="billingToggleKnob"></span>
                    </button>
                    <span class="text-sm font-medium text-slate-600 ml-3">
                        Tahunan 
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800 ml-1">Hemat hingga 25%</span>
                    </span>
                </div>
            </div>

                <div class="grid md:grid-cols-3 gap-8 reveal">
                @foreach($settings->pricing_plans ?? [] as $plan)
                <div class="pricing-card surface-card p-8 rounded-[2.5rem] soft-shadow border border-slate-100 flex flex-col {{ $plan['popular'] ? 'border-2 relative popular' : '' }}">
                    @if($plan['popular'])
                    <div class="absolute top-4 right-4 text-[10px] font-bold px-3 py-1 rounded-full uppercase tracking-tighter badge-popular">Paling Populer</div>
                    @endif
                    <h3 class="text-lg font-bold text-slate-500 mb-2 uppercase tracking-widest">{{ $plan['name'] }}</h3>
                    <div class="text-4xl font-extrabold mb-2" id="price-{{ $loop->index }}">
                        <span id="current-price-{{ $loop->index }}">{{ $plan['price'] }}</span>
                        <span class="text-sm text-slate-400 font-normal" id="current-period-{{ $loop->index }}">{{ $plan['period'] }}</span>
                    </div>
                    @if(isset($plan['savings']) && $plan['savings'])
                    <div class="text-sm text-green-600 font-medium mb-4 hidden" id="savings-{{ $loop->index }}">{{ $plan['savings'] }}</div>
                    @endif
                    <p class="text-slate-500 mb-8 text-sm">{{ $plan['description'] }}</p>
                    <ul class="space-y-4 mb-10 flex-grow">
                        @foreach($plan['features'] ?? [] as $feature)
                        <li class="flex items-center gap-3 text-sm font-medium {{ $feature ? '' : 'text-slate-300' }}">
                            <span class="{{ $feature ? 'accent-text' : 'text-slate-300' }}">{{ $feature ? '✓' : '✕' }}</span> {{ $feature ?: 'Feature not available' }}
                        </li>
                        @endforeach
                    </ul>
                    <button class="w-full {{ $plan['popular'] ? 'btn-green shimmer text-white' : 'bg-slate-900 text-white' }} py-4 rounded-2xl font-bold hover:bg-slate-800 transition">
                        {{ $plan['button'] }}
                    </button>
                </div>
                @endforeach
            </div>
        </div>
    </section>

    <!-- Comparison Table -->
    <section id="perbandingan" class="py-20 px-6 max-w-5xl mx-auto">
        <h2 class="text-4xl font-extrabold text-center mb-16 tracking-tight reveal delay-08">{{ $settings->comparison_title }}</h2>
        <div class="overflow-hidden border border-slate-200 rounded-[2.5rem] soft-shadow surface-card reveal">
            <table class="w-full text-left">
                <thead class="bg-slate-50 border-b border-slate-200">
                    <tr>
                        <th class="p-6 md:p-8 font-extrabold text-xs md:text-sm uppercase tracking-wider text-slate-400">Fitur</th>
                        <th class="p-6 md:p-8 font-extrabold text-xs md:text-sm uppercase tracking-wider text-slate-400">Aplikasi Lain</th>
                        <th class="p-6 md:p-8 font-extrabold text-xs md:text-sm uppercase tracking-wider text-center accent-panel accent-text">{{ $brand->business_name ?? 'Hellom POS' }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach($settings->comparison_features ?? [] as $comparison)
                    <tr class="hover:bg-slate-50 transition-colors group">
                        <td class="p-6 md:p-8 text-sm md:text-base font-bold text-slate-700">{{ $comparison['feature'] }}</td>
                        <td class="p-6 md:p-8 text-sm text-slate-400">{{ $comparison['competitor'] }}</td>
                        <td class="p-6 md:p-8 text-sm md:text-base font-extrabold text-center accent-panel accent-text">{{ $comparison['hellom'] }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <!-- Final CTA Section -->
    <section class="py-20 px-6 text-center bg-slate-900 text-white rounded-t-[5rem] mt-20 relative overflow-hidden">
        <div class="absolute inset-0 opacity-20 final-cta-pattern"></div>

        <div class="max-w-4xl mx-auto relative z-10 reveal">
            <h2 class="text-4xl md:text-6xl font-extrabold mb-8 leading-[1.1] tracking-tight delay-08">
                {{ $settings->final_cta_title }}
            </h2>
            <p class="text-slate-300 text-xl md:text-2xl mb-12 font-medium delay-12">
                {{ $settings->final_cta_subtitle }}
            </p>
            <div class="flex flex-col items-center gap-6">
                <button class="btn-green shimmer text-white py-6 px-16 rounded-[24px] font-extrabold text-2xl shadow-2xl inline-flex items-center gap-4 delay-15">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-8 h-8" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
                    <span>{{ $settings->final_cta_button }}</span>
                </button>
                <p class="text-sm font-bold text-slate-400 uppercase tracking-widest">
                    {{ $settings->final_cta_footer }}
                </p>
            </div>
        </div>
    </section>

    <!-- Simple Footer -->
    <footer class="bg-slate-900 pt-10 pb-32 md:pb-12 text-center text-[10px] font-extrabold text-slate-400 uppercase tracking-[0.4em] border-t border-white/5">
        {{ $settings->footer_text }}
    </footer>

    <script>
        // Reveal elements on scroll
        const reveals = document.querySelectorAll('.reveal');

        function reveal() {
            reveals.forEach(element => {
                const windowHeight = window.innerHeight;
                const elementTop = element.getBoundingClientRect().top;
                const elementVisible = 100;

                if (elementTop < windowHeight - elementVisible) {
                    element.classList.add('active');
                }
            });
        }

        // Show/Hide Sticky CTA
        const mobileCta = document.getElementById('mobileCta');
        window.addEventListener('scroll', () => {
            reveal();
            if (window.scrollY > 400) {
                mobileCta.classList.add('visible');
            } else {
                mobileCta.classList.remove('visible');
            }
        });

        // Billing Toggle
        const billingToggle = document.getElementById('billingToggle');
        const billingToggleKnob = document.getElementById('billingToggleKnob');
        let isYearly = false;

        billingToggle.addEventListener('click', () => {
            isYearly = !isYearly;
            if (isYearly) {
                billingToggle.style.backgroundColor = '{{ $brand->accent_color ?? "#22C55E" }}';
                billingToggleKnob.style.transform = 'translateX(1.25rem)';
            } else {
                billingToggle.style.backgroundColor = '#d1d5db';
                billingToggleKnob.style.transform = 'translateX(0.25rem)';
            }
            updatePrices();
        });

        function updatePrices() {
            @foreach($settings->pricing_plans ?? [] as $index => $plan)
            let priceElement{{ $index }} = document.getElementById('current-price-{{ $index }}');
            let periodElement{{ $index }} = document.getElementById('current-period-{{ $index }}');
            let savingsElement{{ $index }} = document.getElementById('savings-{{ $index }}');
            
            if (isYearly && '{{ $plan['yearly_price'] ?? '' }}') {
                priceElement{{ $index }}.textContent = '{{ $plan['yearly_price'] }}';
                periodElement{{ $index }}.textContent = '{{ $plan['yearly_period'] }}';
                if (savingsElement{{ $index }}) {
                    savingsElement{{ $index }}.style.display = 'block';
                }
            } else {
                priceElement{{ $index }}.textContent = '{{ $plan['price'] }}';
                periodElement{{ $index }}.textContent = '{{ $plan['period'] }}';
                if (savingsElement{{ $index }}) {
                    savingsElement{{ $index }}.style.display = 'none';
                }
            }
            @endforeach
        }

        // Initial check
        // Mobile menu toggle
        const navToggleBtn = document.getElementById('navToggle');
        function toggleMobileMenu() {
            const menu = document.getElementById('mobileMenu');
            if (!menu) return;
            const isHidden = menu.classList.contains('hidden');
            if (isHidden) {
                menu.classList.remove('hidden');
                document.body.classList.add('overflow-hidden');
                if (navToggleBtn) navToggleBtn.setAttribute('aria-expanded', 'true');
            } else {
                menu.classList.add('hidden');
                document.body.classList.remove('overflow-hidden');
                if (navToggleBtn) navToggleBtn.setAttribute('aria-expanded', 'false');
            }
        }

        // Close mobile menu on ESC
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                const m = document.getElementById('mobileMenu');
                if (m && !m.classList.contains('hidden')) toggleMobileMenu();
            }
        });

        // Close mobile menu when a link is clicked
        document.querySelectorAll('#mobileMenu nav a').forEach(a => a.addEventListener('click', () => toggleMobileMenu()));

        // Initial check
        reveal();
        
    </script>
    </div>
</body>
</html>
