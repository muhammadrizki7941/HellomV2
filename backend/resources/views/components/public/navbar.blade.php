@props(['brand', 'headerBanners'])

<header class="section-shell sticky top-0 z-50 border-b border-white/10 bg-[#050505]/90 backdrop-blur-xl" x-data="{ open: false }">
    <div class="mx-auto flex max-w-7xl items-center justify-between gap-6 px-4 py-4 sm:px-6 lg:px-8">
        <a href="{{ route('landing') }}" class="flex items-center gap-3">
            @if($brand->logoUrl())
                <img src="{{ $brand->logoUrl() }}" alt="{{ $brand->business_name ?: 'Hellom' }}" class="h-10 w-auto rounded-xl">
            @else
                <span class="flex h-10 w-10 items-center justify-center rounded-2xl bg-accent-soft font-display text-sm font-extrabold text-accent">HM</span>
            @endif
            <div>
                <div class="font-display text-lg font-extrabold tracking-tight">{{ $brand->app_name ?: 'Hellom' }}</div>
                <div class="text-xs text-muted">{{ $brand->tagline ?: 'Satu platform untuk POS dan growth resto' }}</div>
            </div>
        </a>

        <nav class="hidden items-center gap-8 text-sm font-medium text-muted lg:flex">
            <a href="#hero" class="transition hover:text-white">Home</a>
            <a href="#produk" class="transition hover:text-white">Produk</a>
            <a href="#layanan" class="transition hover:text-white">Layanan</a>
            <a href="#harga" class="transition hover:text-white">Harga</a>
            <a href="#faq" class="transition hover:text-white">FAQ</a>
        </nav>

        <div class="hidden items-center gap-3 lg:flex">
            @if($headerBanners->isNotEmpty())
                @php($banner = $headerBanners->first())
                <div x-data="{ show: true }" x-show="show" x-transition class="surface-card accent-ring flex max-w-xs items-center gap-3 rounded-full px-4 py-2 text-sm">
                    <div class="min-w-0">
                        <p class="truncate font-semibold text-white">{{ $banner->title }}</p>
                        @if($banner->subtitle)
                            <p class="truncate text-xs text-muted">{{ $banner->subtitle }}</p>
                        @endif
                    </div>
                    @if($banner->link)
                        <a href="{{ $banner->link }}" class="rounded-full bg-[var(--brand-accent)] px-3 py-1 text-xs font-bold text-black">Buka</a>
                    @endif
                    <button type="button" @click="show = false" class="text-muted transition hover:text-white" aria-label="Tutup banner">&times;</button>
                </div>
            @endif
            <a href="{{ route('login') }}" class="rounded-full border border-white/10 px-4 py-2 text-sm font-semibold text-white transition hover:border-white/30">Masuk</a>
            <a href="{{ route('register') }}" class="rounded-full bg-[var(--brand-accent)] px-5 py-2 text-sm font-bold text-black transition hover:brightness-110">Daftar</a>
        </div>

        <button type="button" class="inline-flex rounded-2xl border border-white/10 p-2 text-white lg:hidden" @click="open = !open" :aria-expanded="open.toString()" aria-label="Buka menu">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M4 6h16M4 12h16M4 18h16" />
            </svg>
        </button>
    </div>

    <div x-cloak x-show="open" x-transition class="border-t border-white/10 bg-[#0a0a0a] lg:hidden">
        <nav class="mx-auto flex max-w-7xl flex-col gap-4 px-4 py-5 text-sm font-medium text-white sm:px-6">
            <a href="#hero" @click="open = false">Home</a>
            <a href="#produk" @click="open = false">Produk</a>
            <a href="#layanan" @click="open = false">Layanan</a>
            <a href="#harga" @click="open = false">Harga</a>
            <a href="#faq" @click="open = false">FAQ</a>
            <div class="flex gap-3 pt-2">
                <a href="{{ route('login') }}" class="flex-1 rounded-full border border-white/10 px-4 py-3 text-center">Masuk</a>
                <a href="{{ route('register') }}" class="flex-1 rounded-full bg-[var(--brand-accent)] px-4 py-3 text-center font-bold text-black">Daftar</a>
            </div>
        </nav>
    </div>
</header>
