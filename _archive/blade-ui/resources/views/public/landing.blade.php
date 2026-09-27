@php
    $stats = [
        ['value' => '100+', 'label' => 'restoran dan brand aktif'],
        ['value' => '24/7', 'label' => 'akses dashboard dan order'],
        ['value' => '5%', 'label' => 'platform fee transparan'],
        ['value' => 'SEO', 'label' => 'landing siap diindeks'],
    ];

    $products = [
        ['badge' => 'Gratis Selamanya', 'title' => 'Landing Page Builder', 'description' => 'Bangun halaman promosi, menu, dan CTA yang cepat dimuat serta mudah ditemukan di Google.', 'cta' => 'Mulai gratis', 'price' => 'Rp 0'],
        ['badge' => 'Langganan', 'title' => 'POS / Kasir Digital', 'description' => 'Kelola pesanan, produk, pembayaran, staf, dan laporan dari satu dashboard operasional.', 'cta' => 'Coba 14 hari', 'price' => 'Mulai Rp 199rb'],
        ['badge' => 'Terhubung', 'title' => 'Promo & Membership', 'description' => 'Jalankan promo, loyalty, banner, dan engagement pelanggan tanpa setup yang rumit.', 'cta' => 'Lihat fitur', 'price' => 'Built-in'],
    ];

    $services = [
        ['title' => 'Landing SEO-ready', 'copy' => 'Konten utama dirender langsung dari Blade agar crawler membaca semua pesan penting tanpa menunggu JavaScript.'],
        ['title' => 'POS operasional', 'copy' => 'Dashboard order, pembayaran, laporan, dan customer experience tetap berjalan pada layer aplikasi yang sudah ada.'],
        ['title' => 'Branding fleksibel', 'copy' => 'Logo, warna, meta title, meta description, dan kontak publik ditarik dari pengaturan Hellom yang sudah tersedia.'],
        ['title' => 'Banner promosi', 'copy' => 'Banner header dan hero bisa diatur aktif/nonaktif, urutan tampil, periode tayang, dan link tujuan.'],
        ['title' => 'Auth publik rapi', 'copy' => 'Halaman login dan register memakai Blade agar cepat dibuka, mudah dipindai crawler, dan tetap aman di flow Laravel.'],
        ['title' => 'Integrasi tidak terganggu', 'copy' => 'Route `/hellom/*`, endpoint API, middleware, dan dashboard React dibiarkan tetap hidup di tempatnya.'],
    ];

    $reasons = [
        ['number' => '01', 'title' => 'Konten kritikal ada di HTML mentah', 'copy' => 'Heading, copy, CTA, dan pricing tetap terlihat walau JavaScript dimatikan.'],
        ['number' => '02', 'title' => 'Desain mengikuti reference dark SaaS', 'copy' => 'Visual tetap modern dengan emphasis kuning hangat, kartu gelap, dan komposisi kanan-kiri seperti referensi desktop dan mobile.'],
        ['number' => '03', 'title' => 'Data publik tetap dinamis', 'copy' => 'Brand settings dan banner dibaca dari backend sehingga landing tidak menjadi konten statis yang sulit diatur.'],
        ['number' => '04', 'title' => 'Struktur untuk tumbuh', 'copy' => 'Section dipecah menjadi komponen Blade agar mudah dikembangkan tanpa menarik kembali React untuk halaman publik.'],
    ];

    $testimonials = [
        ['name' => 'Rina Sari', 'role' => 'Founder Toko Online, Jakarta', 'quote' => 'Hellom membantu kami berpindah dari catatan manual ke alur operasional yang lebih rapi dan enak dipantau owner.'],
        ['name' => 'Ahmad Fauzi', 'role' => 'Owner Restoran, Padang', 'quote' => 'Yang paling terasa bukan cuma POS, tapi semua promosi dan pengalaman customer jadi terasa satu sistem.'],
        ['name' => 'Dian Putri', 'role' => 'CEO Startup, Bandung', 'quote' => 'Setup cepat, tampilannya tidak malu-maluin, dan jalur onboarding tim terasa jelas sejak hari pertama.'],
    ];

    $plans = [
        ['name' => 'Starter', 'monthly' => 'Rp 0', 'yearly' => 'Rp 0', 'note' => 'Untuk mulai membangun visibilitas online', 'features' => ['Landing page publik', 'SEO meta dasar', 'CTA dan section utama', 'Dashboard brand settings'], 'featured' => false],
        ['name' => 'Growth', 'monthly' => 'Rp 199rb', 'yearly' => 'Rp 1.790.000', 'note' => 'Paling cocok untuk restoran yang mulai aktif berjualan', 'features' => ['Semua fitur Starter', 'POS digital', 'Promo customer', 'Laporan operasional', 'Banner promosi'], 'featured' => true],
        ['name' => 'Custom', 'monthly' => 'Cerita dulu', 'yearly' => 'Cerita dulu', 'note' => 'Untuk integrasi, multi-brand, atau kebutuhan khusus', 'features' => ['Custom workflow', 'Integrasi sistem lama', 'White-label option', 'Pendampingan implementasi'], 'featured' => false],
    ];

    $faqs = [
        ['question' => 'Apakah dashboard React masih dipakai?', 'answer' => 'Ya. Landing publik berpindah ke Blade, sedangkan area `/hellom/*` dan dashboard aplikasi tetap berjalan seperti sebelumnya.'],
        ['question' => 'Apakah halaman tetap bagus tanpa JavaScript?', 'answer' => 'Ya. Semua teks penting, heading, CTA, pricing, dan footer dirender oleh Blade lebih dulu. Alpine hanya menambah interaksi ringan.'],
        ['question' => 'Dari mana warna dan logo diambil?', 'answer' => 'Semua brand utama diambil dari `hellom_brand_settings`, sedangkan banner promosi berasal dari tabel `banners` baru.'],
    ];
@endphp

@extends('layouts.public')

@section('content')
    @php
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'LocalBusiness',
            'name' => $brand->business_name ?: ($brand->app_name ?: 'Hellom'),
            'description' => $metaDescription,
            'url' => $canonicalUrl,
            'image' => $ogImage,
            'telephone' => $brand->support_phone,
            'email' => $brand->support_email,
        ];
    @endphp

    @push('meta')
        <script type="application/ld+json">{!! json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
    @endpush

    <x-public.navbar :brand="$brand" :header-banners="$headerBanners" />

    <main class="section-shell">
        <x-public.hero :brand="$brand" :hero-banners="$heroBanners" />
        <x-public.stats :stats="$stats" />
        <x-public.products :products="$products" />
        <x-public.services :services="$services" />
        <x-public.why :reasons="$reasons" />
        <x-public.testimonials :testimonials="$testimonials" />
        <x-public.pricing :plans="$plans" :faqs="$faqs" />
        <x-public.cta :brand="$brand" />
    </main>

    <x-public.footer :brand="$brand" />
@endsection
