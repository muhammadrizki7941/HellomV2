@props(['brand'])

<footer class="section-shell border-t border-white/10 bg-[#070707]">
    <div class="mx-auto grid max-w-7xl gap-10 px-4 py-12 sm:px-6 lg:grid-cols-[1.2fr_0.8fr_0.8fr] lg:px-8">
        <div>
            <div class="flex items-center gap-3">
                @if($brand->logoUrl())
                    <img src="{{ $brand->logoUrl() }}" alt="{{ $brand->business_name ?: 'Hellom' }}" class="h-10 w-auto rounded-xl">
                @else
                    <span class="flex h-10 w-10 items-center justify-center rounded-2xl bg-accent-soft font-display text-sm font-extrabold text-accent">HM</span>
                @endif
                <div>
                    <p class="font-display text-lg font-extrabold text-white">{{ $brand->app_name ?: 'Hellom' }}</p>
                    <p class="text-sm text-muted">{{ $brand->tagline ?: 'SaaS operasional untuk F&B' }}</p>
                </div>
            </div>
            <p class="mt-5 max-w-md text-sm leading-7 text-muted">{{ $brand->meta_description ?: 'Hellom menyatukan landing publik, promo, POS, dan pengalaman customer dalam satu stack yang lebih rapi dan mudah dikembangkan.' }}</p>
        </div>
        <div>
            <p class="text-sm font-bold uppercase tracking-[0.24em] text-accent">Navigasi</p>
            <div class="mt-4 space-y-3 text-sm text-muted">
                <a href="#produk" class="block transition hover:text-white">Produk</a>
                <a href="#layanan" class="block transition hover:text-white">Layanan</a>
                <a href="#harga" class="block transition hover:text-white">Harga</a>
                <a href="#faq" class="block transition hover:text-white">FAQ</a>
            </div>
        </div>
        <div>
            <p class="text-sm font-bold uppercase tracking-[0.24em] text-accent">Kontak</p>
            <div class="mt-4 space-y-3 text-sm text-muted">
                @if($brand->support_email)
                    <a href="mailto:{{ $brand->support_email }}" class="block transition hover:text-white">{{ $brand->support_email }}</a>
                @endif
                @if($brand->support_phone)
                    <a href="tel:{{ preg_replace('/\s+/', '', $brand->support_phone) }}" class="block transition hover:text-white">{{ $brand->support_phone }}</a>
                @endif
                <a href="{{ route('login') }}" class="block transition hover:text-white">Masuk</a>
                <a href="{{ route('register') }}" class="block transition hover:text-white">Daftar</a>
            </div>
        </div>
    </div>
    <div class="border-t border-white/10 px-4 py-6 text-center text-xs text-muted sm:px-6 lg:px-8">
        {{ $brand->footer_text ?: '© 2026 Hellom. All rights reserved.' }}
    </div>
</footer>
