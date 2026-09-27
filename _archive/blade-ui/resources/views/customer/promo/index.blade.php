<x-customer-layout>
    <div class="pt-4">
        <!-- Hero section -->
        @if($brand?->homeBannerMediaUrl())
            <div class="relative rounded-3xl overflow-hidden shadow-lg" style="aspect-ratio: 16/9;">
                @if($brand->homeBannerIsVideo())
                    <video class="w-full h-full object-cover" autoplay muted loop playsinline>
                        <source src="{{ $brand->homeBannerMediaUrl() }}" type="{{ $brand->home_banner_media_mime ?? 'video/mp4' }}" />
                    </video>
                @else
                    <img src="{{ $brand->homeBannerMediaUrl() }}" alt="Promo banner" class="w-full h-full object-cover" />
                @endif
                <div class="absolute inset-0 bg-gradient-to-br from-slate-900/70 via-slate-800/70 to-slate-900/70 text-white p-6 flex items-center">
                    <div class="text-center w-full">
                        <div class="text-3xl font-extrabold mb-2">Promo & Diskon</div>
                        <div class="text-slate-300 mb-6">Penawaran spesial untuk hari ini</div>
                        <a href="{{ route('order.page') }}" class="inline-flex items-center gap-2 rounded-2xl px-6 py-3 font-bold text-white shadow-sm" style="background: var(--accent-color)">
                            <span>Pesan Sekarang</span>
                            <span>→</span>
                        </a>
                    </div>
                </div>
            </div>
        @else
            <div class="rounded-3xl bg-gradient-to-br from-slate-900 via-slate-800 to-slate-900 text-white p-6 shadow-lg">
                <div class="text-center">
                    <div class="text-3xl font-extrabold mb-2">Promo & Diskon</div>
                    <div class="text-slate-300 mb-6">Penawaran spesial untuk hari ini</div>
                    <a href="{{ route('order.page') }}" class="inline-flex items-center gap-2 rounded-2xl px-6 py-3 font-bold text-white shadow-sm" style="background: var(--accent-color)">
                        <span>Pesan Sekarang</span>
                        <span>→</span>
                    </a>
                </div>
            </div>
        @endif

        <!-- Promo list -->
        <div class="mt-6">
            <div class="flex items-end justify-between gap-3">
                <div>
                    <div class="text-lg font-semibold">Promo Aktif</div>
                    <div class="text-sm text-slate-600">Manfaatkan diskon dan penawaran spesial.</div>
                </div>
            </div>

            @if($promos->count() > 0)
                <div class="mt-3 grid gap-3">
                    @foreach($promos as $promo)
                        <div class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                            <div class="flex items-start gap-4">
                                @if($promo->thumbnailUrl())
                                    <div class="h-12 w-12 rounded-2xl overflow-hidden border border-slate-200 flex-none">
                                        <img src="{{ $promo->thumbnailUrl() }}" alt="{{ $promo->title }}" class="h-full w-full object-cover" loading="lazy" />
                                    </div>
                                @else
                                    <div class="h-12 w-12 rounded-2xl grid place-items-center text-white font-black" style="background: var(--accent-color)">🎁</div>
                                @endif
                                <div class="flex-1">
                                    <div class="font-semibold">{{ $promo->title }}</div>
                                    <div class="mt-1 text-sm text-slate-600">{{ $promo->description }}</div>
                                    @if($promo->discount_percentage)
                                        <div class="mt-2 inline-flex items-center rounded-full bg-emerald-600 text-white px-3 py-1 text-xs font-extrabold shadow-sm">
                                            Diskon {{ $promo->discount_percentage }}%
                                        </div>
                                    @endif
                                    @if($promo->valid_until)
                                        <div class="mt-2 text-xs text-slate-500">
                                            Berlaku sampai: {{ $promo->valid_until->format('d M Y') }}
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="mt-3 rounded-3xl border border-slate-200 bg-slate-50 p-8 text-center">
                    <div class="h-16 w-16 rounded-3xl grid place-items-center text-slate-400 font-black mx-auto mb-4">🎁</div>
                    <div class="text-slate-500 font-semibold mb-2">Belum ada promo aktif saat ini</div>
                    <div class="text-sm text-slate-400">Tetap pantau untuk penawaran menarik selanjutnya!</div>
                </div>
            @endif
        </div>

        <!-- Call to action -->
        <div class="mt-6 rounded-3xl bg-white p-5 shadow-sm border border-slate-100">
            <div class="text-center">
                <div class="font-semibold mb-2">Siap untuk memesan?</div>
                <div class="text-sm text-slate-600 mb-4">Lihat menu lengkap dan mulai pesan sekarang.</div>
                <a href="{{ route('order.page') }}" class="inline-flex items-center gap-2 rounded-2xl px-6 py-3 font-bold text-white shadow-sm" style="background: var(--primary-color)">
                    <span>Lihat Menu</span>
                    <span>→</span>
                </a>
            </div>
        </div>
    </div>
</x-customer-layout>
