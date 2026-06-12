@props(['brand', 'heroBanners'])

<section id="hero" class="section-shell mx-auto grid max-w-7xl gap-12 px-4 pb-20 pt-16 sm:px-6 lg:grid-cols-[1.1fr_0.9fr] lg:px-8 lg:pb-28 lg:pt-24">
    <div class="max-w-3xl">
        <div class="mb-6 inline-flex items-center gap-2 rounded-full border border-accent-soft bg-accent-soft px-4 py-2 text-xs font-bold uppercase tracking-[0.22em] text-accent">
            <span class="h-2 w-2 rounded-full bg-[var(--brand-accent)]"></span>
            Solusi POS dan landing page
        </div>
        <h1 class="font-display text-4xl font-extrabold leading-none tracking-[-0.05em] text-white sm:text-5xl lg:text-7xl">
            Sistem POS untuk resto yang <span class="text-accent italic">jelas</span>, cepat, dan siap diindeks.
        </h1>
        <p class="mt-6 max-w-2xl text-lg leading-8 text-muted">
            {{ $brand->meta_description ?: 'Hellom membantu tim owner, kasir, dan pelanggan bergerak dalam satu alur yang rapi. Landing publik kini SEO-friendly, sementara dashboard aplikasi tetap berjalan tanpa gangguan.' }}
        </p>
        <div class="mt-8 flex flex-col gap-3 sm:flex-row">
            <a href="{{ route('register') }}" class="rounded-full bg-[var(--brand-accent)] px-6 py-4 text-center text-base font-bold text-black transition hover:brightness-110">Daftar & aktifkan POS</a>
            <a href="{{ route('login') }}" class="rounded-full border border-white/10 px-6 py-4 text-center text-base font-semibold text-white transition hover:border-white/30">Masuk ke akun</a>
        </div>
        <div class="mt-8 flex flex-wrap gap-6 text-sm text-muted">
            <div><span class="font-display text-2xl font-bold text-white">Blade</span> untuk public SEO</div>
            <div><span class="font-display text-2xl font-bold text-white">Alpine</span> untuk interaksi ringan</div>
            <div><span class="font-display text-2xl font-bold text-white">/hellom</span> tetap untuk SPA app</div>
        </div>
    </div>

    <div class="relative">
        <div class="surface-card relative overflow-hidden rounded-[2rem] p-5 sm:p-7">
            <div class="absolute inset-0 bg-gradient-to-br from-[rgba(245,197,24,0.10)] via-transparent to-[rgba(37,99,235,0.12)]"></div>
            <div class="relative z-10">
                <div class="mb-5 flex items-center justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.24em] text-accent">Hero Banner</p>
                        <p class="mt-2 text-xl font-bold text-white">Promosi di sisi kanan header</p>
                    </div>
                    <span class="rounded-full border border-white/10 px-3 py-1 text-xs text-muted">SEO + UI</span>
                </div>

                @if($heroBanners->isNotEmpty())
                    <div x-data="{ current: 0 }" x-init="setInterval(() => current = (current + 1) % {{ max($heroBanners->count(), 1) }}, 4500)" class="space-y-4">
                        @foreach($heroBanners as $index => $banner)
                            <article x-show="current === {{ $index }}" x-transition class="rounded-[1.5rem] border border-accent-soft bg-black/40 p-5">
                                <div class="flex flex-col gap-4 sm:flex-row sm:items-center">
                                    @if($banner->imageUrl())
                                        <img src="{{ $banner->imageUrl() }}" alt="{{ $banner->title }}" class="h-24 w-full rounded-2xl object-cover sm:w-32">
                                    @else
                                        <div class="flex h-24 w-full items-center justify-center rounded-2xl bg-accent-soft text-sm font-bold text-accent sm:w-32">Banner</div>
                                    @endif
                                    <div class="min-w-0 flex-1">
                                        <h2 class="text-2xl font-extrabold text-white">{{ $banner->title }}</h2>
                                        @if($banner->subtitle)
                                            <p class="mt-2 text-sm leading-6 text-muted">{{ $banner->subtitle }}</p>
                                        @endif
                                        @if($banner->link)
                                            <a href="{{ $banner->link }}" class="mt-4 inline-flex rounded-full bg-white px-4 py-2 text-sm font-bold text-black">Lihat promo</a>
                                        @endif
                                    </div>
                                </div>
                            </article>
                        @endforeach
                        <div class="flex gap-2">
                            @foreach($heroBanners as $index => $banner)
                                <button type="button" @click="current = {{ $index }}" class="h-2 w-8 rounded-full transition" :class="current === {{ $index }} ? 'bg-[var(--brand-accent)]' : 'bg-white/15'" aria-label="Banner {{ $index + 1 }}"></button>
                            @endforeach
                        </div>
                    </div>
                @else
                    <div class="grid gap-4">
                        <div class="rounded-[1.5rem] border border-accent-soft bg-black/40 p-5">
                            <p class="text-sm font-semibold uppercase tracking-[0.24em] text-accent">Growth</p>
                            <p class="mt-3 text-3xl font-extrabold text-white">Landing public kini ramah SEO</p>
                            <p class="mt-3 text-sm leading-6 text-muted">Konten utama tidak lagi menunggu React hydrate. Bot pencarian langsung membaca headline, pricing, dan CTA Anda.</p>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div class="rounded-[1.5rem] border border-white/10 bg-white/5 p-4">
                                <p class="text-xs uppercase tracking-[0.2em] text-muted">Public Stack</p>
                                <p class="mt-2 text-2xl font-bold text-white">Blade</p>
                            </div>
                            <div class="rounded-[1.5rem] border border-white/10 bg-white/5 p-4">
                                <p class="text-xs uppercase tracking-[0.2em] text-muted">Interaction</p>
                                <p class="mt-2 text-2xl font-bold text-white">Alpine.js</p>
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>
</section>
