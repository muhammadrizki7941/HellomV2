<x-customer-layout>
    <div class="pt-6 px-4 md:px-6 max-w-4xl mx-auto" style="background: #FFFFFF;">
        <style>
            :root {
                --accent-color: #F5C518;
                --primary-color: #1A1A1A;
            }
        </style>
        <!-- Hero section -->
        @php
            $bgUrl = $brand?->homeBannerMediaUrl();
            $title = $brand?->business_name ?? 'AKFE';
            $tagline = $brand?->tagline ?? 'Where Every Sip Tells a Story';
        @endphp

        <div class="relative rounded-3xl overflow-hidden shadow-lg" style="aspect-ratio: 16/9;">
            @if($bgUrl)
                @if($brand->homeBannerIsVideo())
                    <video class="w-full h-full object-cover" autoplay muted loop playsinline>
                        <source src="{{ $bgUrl }}" type="{{ $brand->home_banner_media_mime ?? 'video/mp4' }}" />
                    </video>
                @else
                    <img src="{{ $bgUrl }}" alt="Home banner" class="w-full h-full object-cover" />
                @endif
            @else
                <div class="w-full h-full bg-gradient-to-br from-slate-700 via-slate-800 to-slate-900"></div>
            @endif

            <div class="absolute inset-0 bg-black/55 backdrop-blur-sm"></div>

            <div class="absolute inset-0 flex items-center justify-center p-6">
                <div class="max-w-xl text-center text-white">
                    <div class="mx-auto mb-4 w-24 h-24 rounded-full grid place-items-center text-3xl font-extrabold text-white hero-logo" style="background: linear-gradient(180deg, rgba(255,255,255,0.06), rgba(255,255,255,0.03));">
                        <!-- Cup icon -->
                        <svg width="44" height="44" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <path d="M4 7h12v3a5 5 0 0 1-5 5H9a5 5 0 0 1-5-5V7z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M17 8v2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </div>

                    <div class="text-4xl sm:text-5xl font-bold tracking-tight mb-2" style="color: #FFFFFF; text-shadow: 0 2px 8px rgba(0,0,0,0.8);">{{ $title }}</div>
                    <div class="mb-6" style="color: #F5C518; font-weight: 500;">✨ {{ $tagline }} ✨</div>

                    <div class="flex items-center justify-center gap-4">
                        <a href="{{ route('order.page') }}" class="cta-primary btn-anim relative inline-flex items-center gap-3 rounded-full px-8 py-3 font-bold" style="background: #F5C518; color: #1A1A1A; box-shadow: 0 10px 30px rgba(245,197,24,0.18);">
                            <span>Pesan Sekarang</span>
                        </a>

                        <button id="reservasiBtn" type="button" class="btn-anim inline-flex items-center gap-3 rounded-full px-6 py-3 font-semibold" style="background: transparent; border: 2px solid #FFFFFF; color: #FFFFFF;" aria-haspopup="dialog">
                            Reservasi
                        </button>
                    </div>
                </div>
            </div>

            <!-- rating badge top-right -->
            <div class="absolute top-4 right-4">
                <div class="flex items-center gap-2 bg-white/10 text-white rounded-full px-3 py-1 backdrop-blur-sm shadow-sm">
                    <svg class="w-4 h-4 text-yellow-400" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        <path d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z" fill="currentColor"/>
                    </svg>
                    <span class="text-sm font-semibold">
                        @php
                            $ratingData = $brand->getGoogleMapsRating();
                        @endphp
                        @if($ratingData && isset($ratingData['rating']))
                            {{ number_format($ratingData['rating'], 1) }} Rating
                            @if(isset($ratingData['user_ratings_total']))
                                ({{ number_format($ratingData['user_ratings_total']) }} reviews)
                            @endif
                        @else
                            4.8 Rating
                        @endif
                    </span>
                </div>
            </div>

            <!-- small scroller indicator -->
            <div class="absolute left-1/2 -translate-x-1/2 bottom-4">
                <div class="w-10 h-16 rounded-full border-2 border-white/25 flex items-start justify-center p-1">
                    <div class="w-2 h-2 rounded-full bg-white/60 animate-bounce"></div>
                </div>
            </div>

            <!-- Modal / Popup for reservation -->
            <div id="reservasiModal" class="fixed inset-0 z-50 hidden items-center justify-center p-4">
                <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" data-close="true"></div>
                <div class="relative max-w-md w-full bg-white rounded-2xl p-6 text-center shadow-2xl">
                    <div id="modalGlow" class="absolute -top-6 left-1/2 -translate-x-1/2 w-40 h-40 rounded-full opacity-0 pointer-events-none" aria-hidden="true"></div>
                    <div class="text-lg font-extrabold text-slate-900 mb-2">Reservasi</div>
                    <div class="text-sm text-slate-600 mb-4">Pilih tanggal dan jam, atau hubungi kami untuk bantuan.</div>
                    <div class="flex gap-3 justify-center">
                        <a href="{{ route('reservation.index') }}" class="rounded-full px-5 py-2 bg-[var(--accent-color,#ff8a00)] text-white font-extrabold">Lanjut ke Reservasi</a>
                        <button id="closeModal" class="rounded-full px-5 py-2 border border-slate-200">Tutup</button>
                    </div>
                </div>
            </div>

            <style>
                :root { --accent: var(--accent-color, #ff8a00); }
                .cta-primary{ background: var(--accent); box-shadow: 0 10px 30px rgba(255,138,0,0.18); overflow: hidden; box-sizing: border-box; }
                /* Ensure CTA text never overflows the pill on small devices */
                .cta-primary > span{ display: inline-block; min-width: 0; max-width: 14rem; white-space: normal; word-break: break-word; overflow: hidden; }
                /* On larger screens keep single-line with ellipsis if too long */
                @media (min-width: 640px){
                    .cta-primary > span{ max-width: 20rem; white-space: nowrap; text-overflow: ellipsis; }
                }
                .cta-primary:active, .cta-primary.is-glow{ animation: pulseGlow 1.4s infinite; }
                @keyframes pulseGlow{ 0%{ box-shadow: 0 10px 30px rgba(255,138,0,0.18); }50%{ box-shadow: 0 18px 50px rgba(255,138,0,0.28); }100%{ box-shadow: 0 10px 30px rgba(255,138,0,0.18); } }
                #modalGlow{ background: radial-gradient(circle at center, rgba(255,138,0,0.28), rgba(255,138,0,0.08), transparent); filter: blur(14px); transform: scale(1.1); transition: opacity .28s ease; }
                #modalGlow.show{ opacity: 1; }
                @media (min-width: 640px){ .cta-primary { padding-left: 2.25rem; padding-right: 2.25rem; } }
                /* Entrance + floating animations inspired by referensi */
                .hero-enter{ transform: translateY(12px) scale(.98); opacity: 0; transition: transform .5s ease, opacity .5s ease; }
                .hero-enter.hero-ready{ transform: translateY(0) scale(1); opacity: 1; }
                .hero-logo{ transition: transform 1.2s ease; }
                .hero-logo.hero-float{ transform: translateY(-6px) rotate(4deg); }
                .floating-rating{ animation: floatRating 3s ease-in-out infinite; }
                @keyframes floatRating{ 0%{ transform: translateY(0) rotate(0) }50%{ transform: translateY(-8px) rotate(4deg) }100%{ transform: translateY(0) rotate(0) } }
                .rotate-slow{ animation: rotate360 20s linear infinite; }
                @keyframes rotate360 { from{ transform: rotate(0deg) } to{ transform: rotate(360deg) } }
                .sparkle{ animation: sparklePulse 2s ease-in-out infinite; }
                @keyframes sparklePulse{ 0%{ opacity: 0; transform: scale(.5) }50%{ opacity: 1; transform: scale(1) }100%{ opacity: 0; transform: scale(.5) } }
                .reveal-up{ opacity: 0; transform: translateY(16px); transition: opacity .5s ease, transform .5s ease; }
                .reveal-up.show{ opacity: 1; transform: translateY(0); }
                /* Additional home layout polish to match Figma */
                .container-home { max-width: 980px; margin: 0 auto; }
                .card-quick { border-radius: 18px; }
                .card-quick .icon { width: 56px; height: 56px; border-radius: 12px; }
                .featured-promo { border-radius: 18px; }
                .featured-promo .promo-thumb { position: absolute; right: 1rem; bottom: 1rem; width: 112px; height: 112px; border-radius: 14px; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.18); }
                @media (min-width: 768px){ .featured-promo { padding-right: 6.5rem; } }
                /* Subtle container background block behind promo for Figma-like card */
                .promo-wrapper{ background: rgba(255,255,255,0.35); border-radius: 20px; padding: .75rem; }
            </style>

            <script>
                (function(){
                    const reservasiBtn = document.getElementById('reservasiBtn');
                    const modal = document.getElementById('reservasiModal');
                    const closeModal = document.getElementById('closeModal');
                    const modalGlow = document.getElementById('modalGlow');
                    const hero = document.querySelector('.max-w-xl');
                    const heroLogo = document.querySelector('.hero-logo');
                    const floatingRating = document.querySelector('.floating-rating');

                    function openModal(){
                        modal.classList.remove('hidden');
                        modal.classList.add('flex');
                        modalGlow.classList.add('show');
                        setTimeout(()=> modalGlow.classList.remove('show'), 1400);
                    }

                    function close(){
                        modal.classList.add('hidden');
                        modal.classList.remove('flex');
                    }

                    // CTA interactions
                    reservasiBtn.addEventListener('click', function(e){
                        e.preventDefault();
                        reservasiBtn.classList.add('is-glow');
                        setTimeout(()=> reservasiBtn.classList.remove('is-glow'), 1400);
                        openModal();
                    });

                    reservasiBtn.addEventListener('touchstart', function(){
                        reservasiBtn.classList.add('is-glow');
                        setTimeout(()=> reservasiBtn.classList.remove('is-glow'), 900);
                    }, {passive:true});

                    closeModal.addEventListener('click', close);
                    modal.querySelectorAll('[data-close]').forEach(el=> el.addEventListener('click', close));
                    document.addEventListener('keydown', (e)=>{ if(e.key==='Escape') close(); });

                    // On load, reveal hero and floating elements (simple JS-driven animation to mimic motion/react)
                    document.addEventListener('DOMContentLoaded', function(){
                        // reveal blocks with stagger
                        document.querySelectorAll('.reveal-up').forEach((el, i) => {
                            setTimeout(()=> el.classList.add('show'), 120 + i * 80);
                        });

                        if(hero){
                            hero.classList.add('hero-ready');
                            setTimeout(()=> heroLogo && heroLogo.classList.add('hero-float'), 600);
                        }

                        if(floatingRating){
                            floatingRating.classList.add('floating-rating');
                        }
                    });
                })();
            </script>

            <style>
                /* Button hover + scroll-floating animations */
                @media (prefers-reduced-motion: no-preference) {
                    .btn-anim{ transition: transform .18s ease, box-shadow .18s ease, opacity .18s ease; will-change: transform; }
                    .btn-anim:hover, .btn-anim.hovered{ transform: translateY(-4px) scale(1.02); box-shadow: 0 12px 30px rgba(0,0,0,0.12); }

                    @keyframes floatSlight {
                        0%{ transform: translateY(0) rotate(0deg); }
                        25%{ transform: translateY(-4px) rotate(-0.6deg); }
                        50%{ transform: translateY(0) rotate(0.4deg); }
                        75%{ transform: translateY(-3px) rotate(-0.4deg); }
                        100%{ transform: translateY(0) rotate(0deg); }
                    }

                    .floating{ animation: floatSlight 3.2s ease-in-out infinite; }
                }

                /* Make primary CTA more prominent on hover */
                .cta-primary.btn-anim:hover{ filter: brightness(1.03); transform: translateY(-3px) scale(1.02); }
            </style>

            <script>
                (function(){
                    // Attach animation helpers to interactive buttons in this view
                    const container = document.querySelector('.pt-6') || document;
                    const selector = '.cta-primary, #reservasiBtn, a.rounded-2xl, a.rounded-full, button';
                    const elems = Array.from(container.querySelectorAll(selector));

                    // Filter duplicates and invisible elements
                    const buttons = elems.filter((el, i) => {
                        return el && el.offsetParent !== null; // visible
                    });

                    buttons.forEach(el => el.classList.add('btn-anim'));

                    // IntersectionObserver to add floating class when in viewport
                    if ('IntersectionObserver' in window) {
                        const io = new IntersectionObserver((entries) => {
                            entries.forEach(entry => {
                                const el = entry.target;
                                if (entry.isIntersecting) {
                                    el.classList.add('floating');
                                } else {
                                    el.classList.remove('floating');
                                }
                            });
                        }, { threshold: 0.2 });

                        buttons.forEach(b => io.observe(b));
                    }

                    // Add small hovered class on pointerenter for devices allowing hover
                    buttons.forEach(b => {
                        b.addEventListener('pointerenter', () => b.classList.add('hovered'));
                        b.addEventListener('pointerleave', () => b.classList.remove('hovered'));
                    });
                })();
            </script>

            <style>
                /* Card animations: hover lift, stronger shadow, and left-right float when visible */
                @media (prefers-reduced-motion: no-preference) {
                    .card-anim{ transition: transform .22s cubic-bezier(.2,.9,.2,1), box-shadow .22s ease, opacity .22s ease; will-change: transform; }
                    .card-anim.hovered, .card-anim:hover{ transform: translateY(-6px) scale(1.01); box-shadow: 0 20px 40px rgba(2,6,23,0.18); }

                    @keyframes floatLR { 
                        0%{ transform: translateX(0) translateY(0) rotate(0deg); }
                        25%{ transform: translateX(-6px) translateY(-2px) rotate(-0.6deg); }
                        50%{ transform: translateX(0) translateY(0) rotate(0.3deg); }
                        75%{ transform: translateX(6px) translateY(-2px) rotate(0.6deg); }
                        100%{ transform: translateX(0) translateY(0) rotate(0deg); }
                    }

                    .floating-lr{ animation: floatLR 4s ease-in-out infinite; }
                }

                /* Slightly stronger default shadows for cards */
                .rounded-2xl, .rounded-3xl { box-shadow: 0 8px 20px rgba(2,6,23,0.06); }
            </style>

            <script>
                (function(){
                    // Apply card animation behavior to promo cards, quick action cards and contact card
                    const cardSelector = '.rounded-2xl.p-4, .featured-promo, .rounded-3xl.p-6.bg-slate-900, .min-w-[280px]';
                    const cards = Array.from(document.querySelectorAll(cardSelector)).filter(el => el && el.offsetParent !== null);

                    cards.forEach(c => c.classList.add('card-anim'));

                    // Intersection observer: add floating-lr when visible
                    if ('IntersectionObserver' in window && cards.length){
                        const cio = new IntersectionObserver((entries)=>{
                            entries.forEach(en => {
                                if (en.isIntersecting){
                                    en.target.classList.add('floating-lr');
                                } else {
                                    en.target.classList.remove('floating-lr');
                                }
                            });
                        }, { threshold: 0.18 });

                        cards.forEach(c => cio.observe(c));
                    }

                    // Hover handlers for pointer devices
                    cards.forEach(c => {
                        c.addEventListener('pointerenter', () => c.classList.add('hovered'));
                        c.addEventListener('pointerleave', () => c.classList.remove('hovered'));
                    });
                })();
            </script>

            <style>
                /* When user is touching/dragging, pause animations to avoid interference */
                body.touching .floating, body.touching .floating-lr, body.touching .btn-anim, body.touching .card-anim {
                    animation: none !important;
                    transition: none !important;
                    transform: none !important;
                    will-change: auto !important;
                }
            </style>

            <script>
                (function(){
                    let touchTimer = null;

                    function setTouching(){
                        document.body.classList.add('touching');
                        if (touchTimer) clearTimeout(touchTimer);
                        touchTimer = setTimeout(()=> document.body.classList.remove('touching'), 300);
                    }

                    // Add immediate pause while pointer is down (covers touch and pen)
                    document.addEventListener('pointerdown', (e)=>{
                        // ignore right-click/contextmenu
                        if (e.button && e.button !== 0) return;
                        setTouching();
                        // remove hovered state from targets to avoid stuck hover styles
                        document.querySelectorAll('.hovered').forEach(el => el.classList.remove('hovered'));
                    }, {passive:true});

                    // ensure animations resume after pointer up
                    document.addEventListener('pointerup', ()=>{
                        if (touchTimer) clearTimeout(touchTimer);
                        touchTimer = setTimeout(()=> document.body.classList.remove('touching'), 150);
                    }, {passive:true});
                })();
            </script>
        </div>

        <!-- Top nav shortcuts (old-style layout) -->
        @php
            $lastOrderNumber = session('last_order_number');
        @endphp

        <div class="mt-6">
            <div class="rounded-3xl bg-white p-4 shadow-sm border border-slate-100">
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                    <a href="{{ route('customer.home') }}" class="btn-anim rounded-2xl px-4 py-3 text-center text-sm font-semibold border border-slate-200 bg-slate-50">
                        Home
                    </a>
                    <a href="{{ route('order.page') }}" class="btn-anim rounded-2xl px-4 py-3 text-center text-sm font-semibold border border-slate-200 bg-white">
                        Order
                    </a>
                    <a href="{{ route('customer.orders') }}" class="btn-anim rounded-2xl px-4 py-3 text-center text-sm font-semibold border border-slate-200 bg-white">
                        Pesanan
                    </a>
                    @if($lastOrderNumber)
                        <a href="{{ route('customer.order.success', ['orderNumber' => $lastOrderNumber]) }}" class="btn-anim rounded-2xl px-4 py-3 text-center text-sm font-semibold border border-slate-200 bg-white">
                            Thanks
                        </a>
                    @else
                        <div class="rounded-2xl px-4 py-3 text-center text-sm font-semibold border border-slate-200 bg-white opacity-60 cursor-not-allowed">
                            Thanks
                        </div>
                    @endif
                    <a href="{{ route('reservation.index') }}" class="btn-anim rounded-2xl px-4 py-3 text-center text-sm font-semibold border border-slate-200 bg-white">
                        Reservasi
                    </a>
                    <a href="{{ route('customer.promo') }}" class="btn-anim rounded-2xl px-4 py-3 text-center text-sm font-semibold border border-slate-200 bg-white">
                        Promo
                    </a>
                    @if(Auth::check())
                        <a href="{{ url('/t/' . request()->route('tenant') . '/member') }}" class="btn-anim rounded-2xl px-4 py-3 text-center text-sm font-semibold border border-slate-200 bg-white">
                            Member
                        </a>
                    @else
                        <a href="{{ url('/t/' . request()->route('tenant') . '/login') }}" class="btn-anim rounded-2xl px-4 py-3 text-center text-sm font-semibold border border-slate-200 bg-white">
                            Login
                        </a>
                    @endif
                </div>
            </div>
        </div>

        @if($featuredPackages->count() > 0)
        <div class="mt-6">
            <div class="flex items-end justify-between gap-3">
                <div>
                    <div class="text-lg font-semibold">Paket Spesial</div>
                    <div class="text-sm text-slate-600">Lebih hemat untuk hari spesial / event.</div>
                </div>
            </div>

            <div class="mt-3 flex gap-3 overflow-auto pb-2">
                @foreach($featuredPackages as $pkg)
                    @php
                        $normalPrice = (int) $pkg->packageItems->sum(fn ($pi) => (int) $pi->qty * (int) ($pi->itemProduct?->price ?? 0));
                        $packagePrice = (int) $pkg->price;
                        $savings = max(0, $normalPrice - $packagePrice);
                    @endphp

                    <div class="min-w-[280px] card-anim rounded-3xl overflow-hidden border border-slate-200 bg-white shadow-sm">
                        <div class="p-4">
                            <div class="flex items-start gap-3">
                                <div class="h-16 w-16 rounded-2xl bg-slate-100 overflow-hidden border border-slate-200 flex-none">
                                    @if($pkg->imageUrl())
                                        <img src="{{ $pkg->imageUrl() }}" alt="{{ $pkg->name }}" class="h-full w-full object-cover" loading="lazy" />
                                    @else
                                        <div class="h-full w-full grid place-items-center text-slate-400 text-xs">No Image</div>
                                    @endif
                                </div>

                                <div class="min-w-0 flex-1">
                                    <div class="text-sm font-semibold leading-tight">{{ $pkg->name }}</div>
                                    <div class="mt-1 text-xs text-slate-500">{{ $pkg->description }}</div>
                                </div>
                            </div>

                            <div class="mt-3 flex items-center justify-between gap-3">
                                <div class="min-w-0">
                                    @if($savings > 0)
                                        <div class="text-xs text-slate-500 line-through">Rp {{ number_format($normalPrice, 0, ',', '.') }}</div>
                                    @endif
                                    <div class="text-sm font-extrabold text-slate-900">Rp {{ number_format((int) $pkg->price, 0, ',', '.') }}</div>
                                    @if($savings > 0)
                                        <div class="mt-1 text-xs font-semibold text-emerald-700">Hemat Rp {{ number_format($savings, 0, ',', '.') }}</div>
                                    @endif
                                </div>

                                <a href="{{ route('order.page') }}" class="btn-anim card-anim rounded-2xl px-4 py-2 text-xs font-extrabold text-white shadow-sm" style="background: var(--accent-color)">
                                    Pesan
                                </a>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
        @endif

        <!-- Quick actions (referensi style) -->
        <div class="mt-6 px-2">
            <div class="space-y-3">
                <a href="{{ route('customer.home') }}" class="card-anim bg-white rounded-2xl p-4 shadow-md border border-gray-100 hover:shadow-xl transition-all flex items-center gap-4">
                    <div class="p-3 rounded-2xl shadow-lg flex items-center justify-center" style="background: linear-gradient(135deg, #F5C518, #E6A800);">
                        <span class="text-black font-extrabold">H</span>
                    </div>
                    <div class="flex-1">
                        <div class="font-semibold text-gray-900">Home</div>
                        <div class="text-sm text-gray-500">Halaman depan tenant</div>
                    </div>
                    <div class="text-gray-400">→</div>
                </a>
                <a href="{{ route('order.page') }}" class="card-anim bg-white rounded-2xl p-4 shadow-md border border-gray-100 hover:shadow-xl transition-all flex items-center gap-4">
                    <div class="p-3 rounded-2xl shadow-lg flex items-center justify-center" style="background: linear-gradient(135deg, #F5C518, #E6A800);">
                           <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 14 14" id="Burger-1--Streamline-Core" height="14" width="14">
                                <desc>
                                    Burger 1 Streamline Icon: https://streamlinehq.com
                                </desc>
                                <g id="burger-1--burger-fast-cook-cooking-nutrition-food">
                                <path id="Union" fill="#F5C518" fill-rule="evenodd" d="M10 0.625H4c-1.65685 0 -3 1.34315 -3 3 0 0.55228 0.44772 1 1 1h10c0.5523 0 1 -0.44772 1 -1 0 -1.65685 -1.3431 -3 -3 -3Zm3 10.125c0 1.4497 -1.1753 2.625 -2.625 2.625h-6.75C2.17525 13.375 1 12.1997 1 10.75c0 -0.4832 0.39175 -0.875 0.875 -0.875H3.5l2.5 1.5 1 -1.5h5.125c0.4832 0 0.875 0.3918 0.875 0.875Z" clip-rule="evenodd" stroke-width="1"></path>
                                <path id="Union_2" fill="#1A1A1A" fill-rule="evenodd" d="M0.375 3.625C0.375 1.62297 1.99797 0 4 0h6c2.002 0 3.625 1.62297 3.625 3.625 0 0.89746 -0.7275 1.625 -1.625 1.625H2c-0.89746 0 -1.625 -0.72754 -1.625 -1.625ZM4 1.25c-1.31168 0 -2.375 1.06332 -2.375 2.375 0 0.20711 0.16789 0.375 0.375 0.375h10c0.2071 0 0.375 -0.16789 0.375 -0.375 0 -1.31168 -1.0633 -2.375 -2.375 -2.375H4Zm-3.875 6c0 -0.48325 0.391751 -0.875 0.875 -0.875h12c0.4832 0 0.875 0.39175 0.875 0.875s-0.3918 0.875 -0.875 0.875H1c-0.483249 0 -0.875 -0.39175 -0.875 -0.875Zm3.5 6.75c-1.79493 0 -3.25 -1.4551 -3.25 -3.25 0 -0.82843 0.67157 -1.5 1.5 -1.5H3.5c0.11328 0 0.22443 0.03079 0.32156 0.08907l1.98898 1.19343 0.66943 -1.00419C6.59588 9.35444 6.79103 9.25 7 9.25h5.125c0.8284 0 1.5 0.67157 1.5 1.5 0 1.7949 -1.4551 3.25 -3.25 3.25h-6.75Zm-2 -3.25c0 1.1046 0.89543 2 2 2h6.75c1.1046 0 2 -0.8954 2 -2 0 -0.1381 -0.1119 -0.25 -0.25 -0.25H7.33449l-0.81446 1.2217c-0.18506 0.2776 -0.55551 0.3609 -0.84159 0.1892L3.32688 10.5H1.875c-0.13807 0 -0.25 0.1119 -0.25 0.25Z" clip-rule="evenodd" stroke-width="1"></path>
                                </g>
                         </svg>
                        </div>
                    <div class="flex-1">
                        <div class="font-semibold text-gray-900">Lihat Menu Lengkap</div>
                        <div class="text-sm text-gray-500">Pilih dari ratusan menu favorit</div>
                    </div>
                    <div class="text-gray-400">→</div>
                </a>

                <a href="{{ route('customer.orders') }}" class="card-anim bg-white rounded-2xl p-4 shadow-md border border-gray-100 hover:shadow-xl transition-all flex items-center gap-4">
                    <div class="p-3 rounded-2xl shadow-lg flex items-center justify-center" style="background: linear-gradient(135deg, #F5C518, #E6A800);">
                        <span class="text-black font-extrabold">P</span>
                    </div>
                    <div class="flex-1">
                        <div class="font-semibold text-gray-900">Status Pesanan</div>
                        <div class="text-sm text-gray-500">Cek progress order</div>
                    </div>
                    <div class="text-gray-400">→</div>
                </a>

                @if($lastOrderNumber)
                    <a href="{{ route('customer.order.success', ['orderNumber' => $lastOrderNumber]) }}" class="card-anim bg-white rounded-2xl p-4 shadow-md border border-gray-100 hover:shadow-xl transition-all flex items-center gap-4">
                        <div class="p-3 rounded-2xl shadow-lg flex items-center justify-center" style="background: linear-gradient(135deg, #F5C518, #E6A800);">
                            <span class="text-black font-extrabold">T</span>
                        </div>
                        <div class="flex-1">
                            <div class="font-semibold text-gray-900">Thanks</div>
                            <div class="text-sm text-gray-500">Konfirmasi order</div>
                        </div>
                        <div class="text-gray-400">→</div>
                    </a>
                @else
                    <div class="card-anim bg-white rounded-2xl p-4 shadow-md border border-gray-100 opacity-60 cursor-not-allowed flex items-center gap-4">
                        <div class="p-3 rounded-2xl shadow-lg flex items-center justify-center" style="background: linear-gradient(135deg, #F5C518, #E6A800);">
                            <span class="text-black font-extrabold">T</span>
                        </div>
                        <div class="flex-1">
                            <div class="font-semibold text-gray-900">Thanks</div>
                            <div class="text-sm text-gray-500">Muncul setelah order</div>
                        </div>
                        <div class="text-gray-300">→</div>
                    </div>
                @endif

                <a href="{{ route('reservation.index') }}" class="card-anim bg-white rounded-2xl p-4 shadow-md border border-gray-100 hover:shadow-xl transition-all flex items-center gap-4">
                    <div class="p-3 rounded-2xl shadow-lg flex items-center justify-center" style="background: linear-gradient(135deg, #F5C518, #E6A800);">
                           <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 14 14" id="Chair--Streamline-Core" height="14" width="14">
                                <desc>
                                    Chair Streamline Icon: https://streamlinehq.com
                                </desc>
                                <g id="chair--chair-business-product-comfort-decoration-sit-furniture">
                                     <path id="Vector" fill="#F5C518" d="M11.5 1.5h-9v3.25h9V1.5Z" stroke-width="1"></path>
                                     <path id="Union" fill="#1A1A1A" fill-rule="evenodd" d="M2.5 0c0.34518 0 0.625 0.279822 0.625 0.625v0.25h7.75v-0.25c0 -0.345178 0.2798 -0.625 0.625 -0.625s0.625 0.279822 0.625 0.625V7.25h0.375c0.4142 0 0.75 0.33579 0.75 0.75s-0.3358 0.75 -0.75 0.75h-0.8812l-3.47386 2.2633 2.82126 1.838c0.2892 0.1885 0.3709 0.5757 0.1825 0.8649 -0.1885 0.2892 -0.5757 0.3709 -0.8649 0.1825L7 11.7592l-3.28382 2.1395c-0.28922 0.1884 -0.67642 0.1067 -0.86484 -0.1825 -0.18843 -0.2892 -0.10673 -0.6764 0.18248 -0.8649l2.82124 -1.838L2.38122 8.75H1.5c-0.41421 0 -0.75 -0.33579 -0.75 -0.75s0.33579 -0.75 0.75 -0.75h0.375V0.625C1.875 0.279822 2.15482 0 2.5 0ZM7 10.2673 9.3289 8.75H4.6711L7 10.2673ZM10.875 7.25V5.375h-7.75V7.25h7.75Zm0 -3.125v-2h-7.75v2h7.75Z" clip-rule="evenodd" stroke-width="1"></path>
                                </g>
                            </svg>
                        </div>
                    <div class="flex-1">
                        <div class="font-semibold text-gray-900">Reservasi Tempat</div>
                        <div class="text-sm text-gray-500">Booking jadwal + pre-order menu</div>
                    </div>
                    <div class="text-gray-400">→</div>
                </a>

                <a href="#promo" class="card-anim bg-white rounded-2xl p-4 shadow-md border border-gray-100 hover:shadow-xl transition-all flex items-center gap-4">
                    <div class="p-3 rounded-2xl shadow-lg flex items-center justify-center" style="background: linear-gradient(135deg, #F5C518, #E6A800);">
                           <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 14 14" id="Megaphone-2--Streamline-Core" height="14" width="14">
                                <desc>
                                    Megaphone 2 Streamline Icon: https://streamlinehq.com
                                </desc>
                                <g id="megaphone-2--bullhorn-loud-megaphone-share-speaker-transmit">
                                     <path id="Vector 4144" fill="#F5C518" d="M4.54834 4.04785v4.90428l7.72286 2.41337c0.0822 0.0257 0.1678 0.0388 0.2539 0.0388 0.47 0 0.851 -0.381 0.851 -0.851V2.44669c0 -0.46999 -0.381 -0.85099 -0.851 -0.85099 -0.0861 0 -0.1717 0.01306 -0.2539 0.03874L4.54834 4.04785Z" stroke-width="1"></path>
                                     <path id="Union" fill="#1A1A1A" fill-rule="evenodd" d="M12.4577 2.23099c0.0218 -0.00682 0.0445 -0.01029 0.0674 -0.01029 0.1248 0 0.226 0.10118 0.226 0.22599v8.10661c0 0.1248 -0.1012 0.226 -0.226 0.226 -0.0229 0 -0.0456 -0.0035 -0.0674 -0.0103L5.17334 8.49264v-3.9853l7.28436 -2.27635Zm0.0674 -1.260287c-0.1493 0 -0.2978 0.022654 -0.4403 0.067187L4.45294 3.42285H3.07665c-0.40409 0 -0.80423 0.07959 -1.17757 0.23424 -0.37333 0.15464 -0.71255 0.3813 -0.998294 0.66704 -0.577076 0.57707 -0.901274281 1.35976 -0.901274281 2.17586 0 0.4041 0.079592681 0.80424 0.234233281 1.17758 0.154641 0.37333 0.381302 0.71255 0.667041 0.99829 0.408674 0.40868 0.920464 0.69053 1.474214 0.82023V10c0 1.7259 1.39911 3.125 3.125 3.125 0.34518 0 0.625 -0.2798 0.625 -0.625s-0.27982 -0.625 -0.625 -0.625c-1.03553 0 -1.875 -0.8395 -1.875 -1.875v-0.42286h0.82798l7.63182 2.38496c0.1425 0.0445 0.291 0.0672 0.4403 0.0672 0.8151 0 1.476 -0.6608 1.476 -1.476V2.44669c0 -0.81516 -0.6609 -1.475987 -1.476 -1.475987Z" clip-rule="evenodd" stroke-width="1"></path>
                                </g>
                            </svg>
                        </div>
                    <div class="flex-1">
                        <div class="font-semibold text-gray-900">Lihat Promo</div>
                        <div class="text-sm text-gray-500">Diskon dan penawaran spesial</div>
                    </div>
                    <div class="text-gray-400">→</div>
                </a>

                @if(Auth::check())
                    <a href="{{ url('/t/' . request()->route('tenant') . '/member') }}" class="card-anim bg-white rounded-2xl p-4 shadow-md border border-gray-100 hover:shadow-xl transition-all flex items-center gap-4">
                        <div class="p-3 rounded-2xl shadow-lg flex items-center justify-center" style="background: linear-gradient(135deg, #F5C518, #E6A800);">
                            <svg class="w-6 h-6 text-white" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                                <path d="M12 2C13.1 2 14 2.9 14 4C14 5.1 13.1 6 12 6C10.9 6 10 5.1 10 4C10 2.9 10.9 2 12 2ZM21 9V7L15 1H5C3.89 1 3 1.89 3 3V21C3 22.11 3.89 23 5 23H19C20.11 23 21 22.11 21 21V9M19 9H14V4H19V9Z" />
                            </svg>
                        </div>
                        <div class="flex-1">
                            <div class="font-semibold text-gray-900">Dashboard Member</div>
                            <div class="text-sm text-gray-500">Poin: {{ Auth::user()->points_balance ?? 0 }}</div>
                        </div>
                        <div class="text-gray-400">→</div>
                    </a>
                @else
                    <a href="{{ url('/t/' . request()->route('tenant') . '/login') }}" class="card-anim bg-white rounded-2xl p-4 shadow-md border border-gray-100 hover:shadow-xl transition-all flex items-center gap-4">
                        <div class="p-3 rounded-2xl shadow-lg flex items-center justify-center" style="background: linear-gradient(135deg, #F5C518, #E6A800);">
                            <svg class="w-6 h-6 text-white" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                                <path d="M12 2C13.1 2 14 2.9 14 4C14 5.1 13.1 6 12 6C10.9 6 10 5.1 10 4C10 2.9 10.9 2 12 2ZM21 9V7L15 1H5C3.89 1 3 1.89 3 3V21C3 22.11 3.89 23 5 23H19C20.11 23 21 22.11 21 21V9M19 9H14V4H19V9Z" />
                            </svg>
                        </div>
                        <div class="flex-1">
                            <div class="font-semibold text-gray-900">Login Member</div>
                            <div class="text-sm text-gray-500">Dapatkan poin & promo eksklusif</div>
                        </div>
                        <div class="text-gray-400">→</div>
                    </a>
                @endif
            </div>
        </div>

        <!-- Promo section (referensi style) -->
        <div id="promo" class="mt-6 px-2">
            <div class="flex items-end justify-between gap-3">
                <div>
                    <div class="text-lg font-semibold">Promo & Diskon</div>
                    <div class="text-sm text-slate-600">Penawaran spesial untuk hari ini.</div>
                </div>
                <a href="#" class="text-orange-600 font-semibold text-sm flex items-center gap-1">Lihat Semua →</a>
            </div>

            @if($promos->count() > 0)
                @php
                    $firstPromo = $promos->first();
                    $otherPromos = $promos->slice(1);
                @endphp

                <div class="mt-4 space-y-4">
                    <style>
                        /* Light-strip glow that orbits promo cards */
                        @media (prefers-reduced-motion: no-preference) {
                            .promo-wrap{ position: relative; }
                            .promo-light{ position: absolute; inset: -6px; border-radius: inherit; pointer-events: none; z-index: 5; mix-blend-mode: screen; opacity: .95; filter: blur(9px); background: conic-gradient(from 0deg, rgba(255,255,255,0.06), rgba(255,210,120,0.18), rgba(255,255,255,0.04), rgba(255,140,40,0.22)); transform-origin: center center; animation: promoSpin 4s linear infinite; }
                            .promo-light.small{ inset: -5px; filter: blur(7px); animation-duration: 4.8s; }
                            @keyframes promoSpin{ to{ transform: rotate(360deg); } }
                        }

                        /* Respect reduced motion */
                        @media (prefers-reduced-motion: reduce) {
                            .promo-light{ animation: none !important; opacity: .6; }
                        }
                    </style>
                    <!-- Featured gradient promo -->
                    <div class="featured-promo card-anim rounded-3xl p-6 shadow-2xl relative overflow-hidden cursor-pointer mb-6 z-0" style="background: linear-gradient(135deg, var(--accent-color), var(--primary-color));">
                        <div class="promo-light" aria-hidden="true"></div>
                        <div class="absolute -top-8 -right-8 w-36 h-36 bg-white/10 rounded-full z-0"></div>
                        <div class="absolute -bottom-8 -left-8 w-36 h-36 bg-white/10 rounded-full z-0"></div>

                        <!-- subtle left dark overlay to improve text contrast -->
                        <div class="absolute inset-y-0 left-0 w-2/3 bg-gradient-to-r from-black/30 to-transparent z-10 pointer-events-none"></div>

                        <div class="relative z-30 flex items-center gap-4">
                            <div class="flex-1 text-white">
                                <div class="inline-block bg-yellow-400 text-yellow-900 px-3 py-1 rounded-full text-xs font-bold mb-2">PROMO SPESIAL</div>
                                <h4 class="text-2xl md:text-3xl font-bold leading-tight">{{ $firstPromo->title }}</h4>
                                <p class="text-white/95 text-sm mt-1">{{ $firstPromo->description }}</p>
                            </div>
                            <div class="text-white text-2xl">→</div>
                        </div>

                        @if($firstPromo->thumbnailUrl())
                            <div class="promo-thumb" style="z-index:20;">
                                <img src="{{ $firstPromo->thumbnailUrl() }}" alt="{{ $firstPromo->title }}" class="w-full h-full object-cover" />
                            </div>
                        @endif
                    </div>

                    <!-- Other promos list -->
                    <div class="space-y-3">
                        @if($otherPromos->count() > 0)
                                @foreach($otherPromos as $promo)
                                <div class="card-anim promo-wrap bg-white rounded-2xl p-4 shadow-md border border-orange-50 cursor-pointer">
                                    <div class="promo-light small" aria-hidden="true"></div>
                                    <div class="flex items-center gap-4">
                                        <div class="w-16 h-16 rounded-xl flex items-center justify-center" style="background: linear-gradient(135deg, #F5C518, #E6A800);">
                                            <div class="text-black font-bold">%</div>
                                        </div>
                                        <div class="flex-1">
                                            <h4 class="font-bold text-gray-900 mb-1">{{ $promo->title }}</h4>
                                            <p class="text-sm text-gray-500">{{ $promo->description }}</p>
                                        </div>
                                        <div class="text-gray-400">→</div>
                                    </div>
                                </div>
                            @endforeach
                        @endif
                    </div>
                </div>
            @else
                <div class="mt-3 rounded-3xl border border-slate-200 bg-slate-50 p-6 text-center">
                    <div class="text-slate-500">Belum ada promo aktif saat ini.</div>
                </div>
            @endif

            <!-- Contact / Info card (referensi desain) -->
                <div class="mt-6 relative z-20">
                <div class="card-anim rounded-3xl p-6 bg-slate-900 text-white shadow-2xl overflow-hidden">
                    <div class="flex items-start justify-between gap-4">
                        <div class="flex-1">
                            <h3 class="text-xl font-extrabold mb-2">{{ $brand->business_name ?? 'Our Place' }}</h3>
                            <div class="text-sm text-slate-300 space-y-2">
                                <div class="flex items-center gap-3">
                                    <svg class="w-5 h-5 flex-none" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" style="color: #F5C518;">
                                        <path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5A2.5 2.5 0 1 1 12 6.5a2.5 2.5 0 0 1 0 5z" />
                                    </svg>
                                    <div>{{ $brand->address ?? 'Jl. Sudirman No. 123, Jakarta Pusat' }}</div>
                                </div>

                                <div class="flex items-center gap-3">
                                    <svg class="w-5 h-5 flex-none" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" style="color: #F5C518;">
                                        <path d="M6.6 10.2a15.05 15.05 0 0 0 7.2 7.2l2-2a1 1 0 0 1 1-.25c1.1.45 2.3.7 3.5.7a1 1 0 0 1 1 1V20a1 1 0 0 1-1 1C9.1 21 3 14.9 3 6a1 1 0 0 1 1-1h2.5a1 1 0 0 1 1 1c0 1.2.25 2.4.7 3.5a1 1 0 0 1-.25 1l-2 2z"/>
                                    </svg>
                                    @if($brand->phone)
                                        <a href="tel:{{ preg_replace('/\s+/', '', $brand->phone) }}" class="hover:underline">{{ $brand->phone }}</a>
                                    @elseif($brand->whatsapp)
                                        <a href="https://wa.me/{{ preg_replace('/[^0-9]/','',$brand->whatsapp) }}" class="hover:underline">{{ $brand->whatsapp }}</a>
                                    @else
                                        <span>-</span>
                                    @endif
                                </div>
                            </div>
                        </div>

                        <div class="flex-shrink-0 text-slate-400 text-sm">
                            <div class="mb-1">Hubungi Kami:</div>
                            <div class="flex items-center gap-2">
                                @if($brand->whatsapp)
                                    <a href="https://wa.me/{{ preg_replace('/[^0-9]/','',$brand->whatsapp) }}" class="w-9 h-9 rounded-full bg-white/6 flex items-center justify-center text-white/90 hover:bg-white/10" aria-label="WhatsApp">
                                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M16.7 7.3a5 5 0 0 0-7.07 0L9 8l-1.2-.6A6 6 0 1 0 18 15.2L16.4 14l-.7.7a5 5 0 0 0 .99-7.4z"/></svg>
                                    </a>
                                @endif

                                @if($brand->instagram)
                                    <a href="{{ \Illuminate\Support\Str::startsWith($brand->instagram, ['http://','https://']) ? $brand->instagram : 'https://instagram.com/'.$brand->instagram }}" class="w-9 h-9 rounded-full bg-white/6 flex items-center justify-center text-white/90 hover:bg-white/10" aria-label="Instagram">
                                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M7 2h10a5 5 0 0 1 5 5v10a5 5 0 0 1-5 5H7a5 5 0 0 1-5-5V7a5 5 0 0 1 5-5zm5 6.5A4.5 4.5 0 1 0 16.5 13 4.5 4.5 0 0 0 12 8.5zM18 7.5a1.25 1.25 0 1 1-1.25-1.25A1.25 1.25 0 0 1 18 7.5z"/></svg>
                                    </a>
                                @endif

                                @if($brand->website)
                                    <a href="{{ \Illuminate\Support\Str::startsWith($brand->website, ['http://','https://']) ? $brand->website : 'https://'.$brand->website }}" class="w-9 h-9 rounded-full bg-white/6 flex items-center justify-center text-white/90 hover:bg-white/10" aria-label="Website">
                                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M12 2a10 10 0 1 0 .001 20.001A10 10 0 0 0 12 2zm1 17.93V20h-2v-.07A8.001 8.001 0 0 1 4.07 13H4v-2h.07A8.001 8.001 0 0 1 11 4.07V4h2v.07A8.001 8.001 0 0 1 19.93 11H20v2h-.07A8.001 8.001 0 0 1 13 19.93z"/></svg>
                                    </a>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</x-customer-layout>
