@php
    $activeLocale = app()->getLocale();
@endphp

<button id="cashier-sidebar-reopen"
        type="button"
        class="hidden fixed top-4 left-4 z-50 items-center gap-2 px-3 py-2 rounded-lg border border-gray-200 bg-white text-slate-700 shadow-sm hover:bg-gray-50"
        onclick="cashierToggleSidebar()"
        title="Tampilkan menu kasir">
    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
    </svg>
    <span class="text-xs font-semibold">Menu Kasir</span>
</button>

<div id="cashier-sidebar" class="w-64 lg:w-64 md:w-56 sm:w-48 bg-white border-r border-gray-200 flex flex-col shrink-0 shadow-sm">
    <div class="p-5 border-b bg-gradient-to-r from-slate-900 to-slate-800 text-white flex items-center justify-between">
        <div class="flex items-center gap-3">
            @if(isset($brand) && $brand?->logoLightUrl())
                <div class="w-10 h-10 rounded-xl bg-white/10 border border-white/20 flex items-center justify-center overflow-hidden p-1">
                    <img src="{{ $brand->logoLightUrl() }}" alt="{{ $brand?->business_name ?? config('app.name') }}" class="w-full h-full object-contain" />
                </div>
            @else
                <div class="w-10 h-10 rounded-xl bg-emerald-500 border border-emerald-400 flex items-center justify-center">
                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
                    </svg>
                </div>
            @endif
            <div>
                <div class="font-bold text-white">{{ __('ui.cashier_mode') }}</div>
                <div class="text-xs text-slate-300">{{ __('ui.cashier_ops') }}</div>
            </div>
        </div>
        <div class="hidden lg:block">
            <button type="button" class="px-3 py-1.5 rounded-lg border border-slate-600 text-xs text-slate-200 hover:bg-slate-700" onclick="cashierToggleSidebar()">Sembunyikan</button>
        </div>
    </div>

    <div class="px-4 pt-4">
        <div class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-emerald-50 text-emerald-700 text-xs font-semibold">
            <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
            {{ __('ui.cashier_active') }}
        </div>
    </div>

    <nav class="flex-1 p-4 space-y-1 overflow-y-auto">
        <p class="px-2 pb-2 text-[11px] tracking-wide uppercase text-gray-400 font-semibold">{{ __('ui.cashier_ops') }}</p>
        <a href="{{ route('admin.cashier.index') }}" class="group relative flex items-center gap-3 px-4 py-3 rounded-xl transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-emerald-200 {{ request()->routeIs('admin.cashier.index') ? 'bg-emerald-50 text-emerald-700 font-semibold' : 'text-gray-600 hover:bg-gray-50' }}" @if(request()->routeIs('admin.cashier.index')) aria-current="page" @endif>
            <span class="absolute left-0 top-0 bottom-0 w-1 rounded-r-lg bg-emerald-500 {{ request()->routeIs('admin.cashier.index') ? 'opacity-100' : 'opacity-0' }} transition-opacity duration-200" aria-hidden="true"></span>
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
            </svg>
            <span>{{ __('ui.cashier_manual') }}</span>
        </a>

        <a href="{{ route('admin.cashier.orders') }}" class="group relative flex items-center gap-3 px-4 py-3 rounded-xl transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-emerald-200 {{ request()->routeIs('admin.cashier.orders') ? 'bg-emerald-50 text-emerald-700 font-semibold' : 'text-gray-600 hover:bg-gray-50' }}" @if(request()->routeIs('admin.cashier.orders')) aria-current="page" @endif>
            <span class="absolute left-0 top-0 bottom-0 w-1 rounded-r-lg bg-emerald-500 {{ request()->routeIs('admin.cashier.orders') ? 'opacity-100' : 'opacity-0' }} transition-opacity duration-200" aria-hidden="true"></span>
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
            <span>{{ __('ui.order_list') }}</span>
            <span x-transition class="ml-auto inline-flex items-center justify-center min-w-6 h-6 px-2 rounded-full text-xs font-bold transform-gpu transition-transform duration-200 group-hover:scale-105"
                  :class="typeof ordersCount !== 'undefined' ? (ordersCount > 0 ? 'bg-emerald-100 text-emerald-800' : 'bg-gray-100 text-gray-600') : (typeof totalOrdersCount === 'function' && totalOrdersCount() > 0 ? 'bg-emerald-100 text-emerald-800' : 'bg-gray-100 text-gray-600')"
                  x-text="typeof ordersCount !== 'undefined' ? ordersCount : (typeof totalOrdersCount === 'function' ? totalOrdersCount() : '')"></span>
        </a>

        <p class="px-2 pt-4 pb-2 text-[11px] tracking-wide uppercase text-gray-400 font-semibold">{{ __('ui.admin_nav') }}</p>

        <a href="{{ route('admin.cashier.menu') }}" class="group relative flex items-center gap-3 px-4 py-3 rounded-xl transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-emerald-200 {{ request()->routeIs('admin.cashier.menu*') ? 'bg-emerald-50 text-emerald-700 font-semibold' : 'text-gray-600 hover:bg-gray-50' }}" @if(request()->routeIs('admin.cashier.menu*')) aria-current="page" @endif>
            <span class="absolute left-0 top-0 bottom-0 w-1 rounded-r-lg bg-emerald-500 {{ request()->routeIs('admin.cashier.menu*') ? 'opacity-100' : 'opacity-0' }} transition-opacity duration-200" aria-hidden="true"></span>
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
            </svg>
            <span>{{ __('ui.products') }}</span>
        </a>

        <a href="{{ route('admin.kitchen.index') }}" class="flex items-center gap-3 px-4 py-3 rounded-xl {{ request()->routeIs('admin.kitchen.*') ? 'bg-emerald-50 text-emerald-700 font-semibold' : 'text-gray-600 hover:bg-gray-50' }}" @if(request()->routeIs('admin.kitchen.*')) aria-current="page" @endif>
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 18.657A8 8 0 016.343 7.343S7 9 9 10c0-2 .5-5 2.986-7C14 5 16.09 5.777 17.656 7.343A7.975 7.975 0 0120 13a7.975 7.975 0 01-2.343 5.657z"/>
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.879 16.121A3 3 0 1012.015 11L11 14H9c0 .768.293 1.536.879 2.121z"/>
            </svg>
            <span>{{ __('ui.kitchen') }}</span>
        </a>

    </nav>

    <div class="p-4 border-t">
        <button id="cashier-fullscreen-btn"
                type="button"
                class="flex items-center gap-3 px-4 py-3 rounded-xl text-gray-700 bg-gray-50 border border-gray-200 hover:bg-gray-100 w-full mb-2"
                onclick="cashierToggleFullscreen(event)">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 3H5a2 2 0 00-2 2v3m16-5h-3m3 0a2 2 0 012 2v3M3 16v3a2 2 0 002 2h3m11-5v3a2 2 0 01-2 2h-3"/>
            </svg>
            <span id="cashier-fullscreen-label" class="font-semibold">Masuk Fullscreen</span>
        </button>

        <div class="flex items-center justify-between gap-2 mb-2">
            <span class="text-xs text-gray-500">{{ __('ui.language') }}</span>
            <div class="flex items-center gap-1">
                <form method="POST" action="{{ route('admin.locale.update') }}" class="inline">
                    @csrf
                    <input type="hidden" name="locale" value="id">
                    <button type="submit" class="px-2 py-1 rounded-md text-[11px] font-semibold border {{ $activeLocale === 'id' ? 'bg-emerald-50 border-emerald-200 text-emerald-700' : 'bg-white border-gray-200 text-gray-600 hover:bg-gray-50' }}">ID</button>
                </form>
                <form method="POST" action="{{ route('admin.locale.update') }}" class="inline">
                    @csrf
                    <input type="hidden" name="locale" value="en">
                    <button type="submit" class="px-2 py-1 rounded-md text-[11px] font-semibold border {{ $activeLocale === 'en' ? 'bg-emerald-50 border-emerald-200 text-emerald-700' : 'bg-white border-gray-200 text-gray-600 hover:bg-gray-50' }}">EN</button>
                </form>
            </div>
        </div>

        <a href="{{ route('admin.dashboard') }}"
           class="flex items-center gap-3 px-4 py-3 rounded-xl text-amber-700 bg-amber-50 border border-amber-200 hover:bg-amber-100 w-full mb-2"
           onclick="cashierExitModeConfirm(event)">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
            </svg>
            <span class="font-semibold">{{ __('ui.exit_cashier_mode') }}</span>
        </a>
        <form id="cashier-logout-form" method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="flex items-center gap-3 px-4 py-3 rounded-xl text-gray-600 hover:bg-gray-50 w-full" onclick="cashierLogoutConfirm(event)">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                </svg>
                <span>{{ __('ui.logout_account') }}</span>
            </button>
        </form>
    </div>
</div>

<div id="cashier-ui-toast" class="fixed top-4 right-4 z-[90] pointer-events-none"></div>

<div id="cashier-ui-confirm" class="hidden fixed inset-0 z-[95]">
    <div class="absolute inset-0 bg-slate-900/55"></div>
    <div class="relative min-h-full flex items-center justify-center p-4">
        <div class="w-full max-w-md bg-white rounded-2xl shadow-2xl border border-slate-100 overflow-hidden">
            <div class="px-6 py-5 border-b border-slate-100">
                <h3 id="cashier-ui-confirm-title" class="text-lg font-bold text-slate-900">{{ __('ui.confirm_title') }}</h3>
                <p id="cashier-ui-confirm-message" class="mt-1 text-sm text-slate-600"></p>
            </div>
            <div class="px-6 py-4 flex items-center justify-end gap-2 bg-slate-50">
                <button type="button" id="cashier-ui-cancel" class="px-4 py-2 rounded-lg border border-slate-300 text-slate-700 hover:bg-white text-sm font-semibold">{{ __('ui.cancel') }}</button>
                <button type="button" id="cashier-ui-confirm-btn" class="px-4 py-2 rounded-lg bg-emerald-600 text-white hover:bg-emerald-700 text-sm font-semibold">{{ __('ui.confirm') }}</button>
            </div>
        </div>
    </div>
</div>

<style>
    #cashier-ui-toast {
        position: fixed !important;
        z-index: 2147483646 !important;
    }

    #cashier-ui-confirm {
        position: fixed !important;
        z-index: 2147483647 !important;
    }

    /* Sidebar core transition */
    #cashier-sidebar{
        transition: transform .25s ease, opacity .2s ease, width .25s ease, flex-basis .25s ease, border-color .2s ease;
        width: 16rem;
        flex: 0 0 16rem;
        min-width: 0;
    }

    #cashier-sidebar-reopen { display: none !important; }

    @media (min-width: 1024px){
        body.cashier-sidebar-hidden #cashier-sidebar{
            transform: translateX(-24px);
            opacity: 0;
            pointer-events: none;
            width: 0 !important;
            flex-basis: 0 !important;
            border-right-width: 0;
            overflow: hidden;
        }
    }

    /* Active / hover transitions */
    #cashier-sidebar a.group{ transition: background-color .25s ease, color .25s ease; }
    #cashier-sidebar a.group svg{ transition: transform .25s ease, color .25s ease, stroke .25s ease; transform-origin: center; }
    #cashier-sidebar a.group:hover svg{ transform: translateX(3px) scale(1.02); }
    #cashier-sidebar a.group[aria-current="page"]{ background-color: rgba(16,185,129,0.06); }
    #cashier-sidebar a.group[aria-current="page"] svg{ transform: translateX(4px) scale(1.06); color: inherit; }

    /* Left indicator subtle animation */
    #cashier-sidebar a.group .indicator{ transition: opacity .25s ease, transform .25s ease; }
    #cashier-sidebar a.group[aria-current="page"] .indicator{ opacity: 1; transform: scaleY(1); }
    #cashier-sidebar a.group .indicator{ opacity: 0; transform: scaleY(0.9); }

    /* Badge micro animation */
    #cashier-sidebar .inline-flex{ transition: transform .15s ease, background-color .2s ease, color .2s ease; }
    #cashier-sidebar .inline-flex:active, #cashier-sidebar .inline-flex:focus{ transform: scale(0.98); }

    /* Fade-in for active highlight on page load */
    @keyframes cashierActiveFade { from{ opacity: 0; transform: translateY(-4px); } to{ opacity: 1; transform: translateY(0);} }
    #cashier-sidebar a.group[aria-current="page"]{ animation: cashierActiveFade .22s ease; }

    /* Pulse attention for next-action hints */
    .pulse-attention { animation: pulse-attention 1.1s infinite; }
    @keyframes pulse-attention {
        0% { box-shadow: 0 0 0 0 rgba(245,158,11,0.00); }
        50% { box-shadow: 0 0 0 10px rgba(245,158,11,0.08); }
        100% { box-shadow: 0 0 0 0 rgba(245,158,11,0.00); }
    }
    @media (prefers-reduced-motion: reduce) {
        .pulse-attention { animation: none !important; }
    }

    /* Mobile responsive improvements */
    @media (max-width: 1023px) {
        #cashier-sidebar {
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            z-index: 40;
            box-shadow: 2px 0 10px rgba(0,0,0,0.1);
            width: 16rem;
            flex-basis: 16rem;
            transform: translateX(-100%);
            opacity: 0;
            pointer-events: none;
        }

        body.cashier-sidebar-open #cashier-sidebar {
            transform: translateX(0);
            opacity: 1;
            pointer-events: auto;
            border-right-width: 1px;
        }

        body:not(.cashier-sidebar-open) #cashier-sidebar-reopen {
            display: inline-flex !important;
        }
    }
</style>

<script>
    const CASHIER_MOBILE_BREAKPOINT = 1024;

    function cashierIsMobileViewport() {
        return window.innerWidth < CASHIER_MOBILE_BREAKPOINT;
    }

    function cashierSidebarOverlay() {
        return document.getElementById('cashier-sidebar-overlay');
    }

    function cashierEnsureSidebarOverlay() {
        let overlay = cashierSidebarOverlay();
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.id = 'cashier-sidebar-overlay';
            overlay.className = 'fixed inset-0 bg-black bg-opacity-50 z-30 lg:hidden';
            overlay.onclick = cashierToggleSidebar;
            document.body.appendChild(overlay);
        }
        return overlay;
    }

    function cashierSyncSidebarState(options = {}) {
        const isMobile = cashierIsMobileViewport();
        const forceHideOnMobile = options.forceHideOnMobile === true;
        const hiddenClass = 'cashier-sidebar-hidden';
        const openClass = 'cashier-sidebar-open';
        const isOpen = document.body.classList.contains(openClass);

        if (isMobile) {
            document.body.classList.remove(hiddenClass);
            if (forceHideOnMobile || !isOpen) {
                document.body.classList.remove(openClass);
            }
        } else {
            document.body.classList.remove(openClass);
        }

        if (!isMobile) {
            const overlay = cashierSidebarOverlay();
            if (overlay) {
                overlay.remove();
            }
            return;
        }

        const overlay = cashierEnsureSidebarOverlay();
        const shouldShowOverlay = document.body.classList.contains(openClass);
        overlay.style.display = shouldShowOverlay ? 'block' : 'none';
    }

    function cashierEnsureUi() {
        if (window.cashierUi) return window.cashierUi;

        const confirmRoot = document.getElementById('cashier-ui-confirm');
        const confirmTitle = document.getElementById('cashier-ui-confirm-title');
        const confirmMessage = document.getElementById('cashier-ui-confirm-message');
        const confirmBtn = document.getElementById('cashier-ui-confirm-btn');
        const cancelBtn = document.getElementById('cashier-ui-cancel');
        const toastRoot = document.getElementById('cashier-ui-toast');

        try {
            if (toastRoot && toastRoot.parentElement !== document.body) {
                document.body.appendChild(toastRoot);
            }
            if (confirmRoot && confirmRoot.parentElement !== document.body) {
                document.body.appendChild(confirmRoot);
            }
        } catch (e) {}

        let resolver = null;
        let rejecter = null;
        let keyHandler = null;

        function closeConfirm(result) {
            confirmRoot.classList.add('hidden');
            if (keyHandler) {
                document.removeEventListener('keydown', keyHandler);
                keyHandler = null;
            }
            if (resolver) {
                resolver(result);
            }
            resolver = null;
            rejecter = null;
        }

        function openConfirm(options = {}) {
            const title = options.title || @js(__('ui.confirm_title'));
            const message = options.message || '';
            const confirmText = options.confirmText || @js(__('ui.confirm'));
            const cancelText = options.cancelText || @js(__('ui.cancel'));

            confirmTitle.textContent = title;
            confirmMessage.textContent = message;
            confirmBtn.textContent = confirmText;
            cancelBtn.textContent = cancelText;
            confirmRoot.classList.remove('hidden');

            return new Promise((resolve, reject) => {
                resolver = resolve;
                rejecter = reject;

                keyHandler = function (event) {
                    if (event.key === 'Escape') {
                        closeConfirm(false);
                    }
                };

                document.addEventListener('keydown', keyHandler);
            });
        }

        confirmBtn.addEventListener('click', function () { closeConfirm(true); });
        cancelBtn.addEventListener('click', function () { closeConfirm(false); });
        confirmRoot.addEventListener('click', function (event) {
            if (event.target === confirmRoot || event.target.classList.contains('bg-slate-900/55')) {
                closeConfirm(false);
            }
        });

        function toast(message, type = 'info') {
            if (!toastRoot) return;

            const palette = type === 'success'
                ? 'border-emerald-200 bg-emerald-50 text-emerald-800'
                : (type === 'error'
                    ? 'border-red-200 bg-red-50 text-red-800'
                    : 'border-slate-200 bg-white text-slate-800');

            const el = document.createElement('div');
            el.className = `pointer-events-auto mb-2 min-w-[260px] max-w-sm rounded-xl border px-4 py-3 shadow-lg text-sm font-semibold ${palette}`;
            el.textContent = message;

            toastRoot.appendChild(el);
            setTimeout(() => {
                el.classList.add('opacity-0', 'translate-y-1', 'transition');
                setTimeout(() => el.remove(), 220);
            }, 2600);
        }

        window.cashierUi = {
            confirm: openConfirm,
            toast,
        };

        return window.cashierUi;
    }

    function cashierToggleSidebar(){
        if (cashierIsMobileViewport()) {
            document.body.classList.toggle('cashier-sidebar-open');
            cashierSyncSidebarState();
            return;
        }

        const hidden = document.body.classList.toggle('cashier-sidebar-hidden');
        try{ localStorage.setItem('cashier.sidebarHidden', String(hidden)); } catch(e){}
        cashierSyncSidebarState();
    }

    function cashierGetFullscreenElement() {
        return document.fullscreenElement || document.webkitFullscreenElement || document.msFullscreenElement || null;
    }

    function cashierIsFullscreenActive() {
        return !!cashierGetFullscreenElement();
    }

    async function cashierEnterFullscreen() {
        const root = document.documentElement;
        if (!root) return false;

        try {
            if (root.requestFullscreen) {
                await root.requestFullscreen({ navigationUI: 'hide' });
                return true;
            }

            if (root.webkitRequestFullscreen) {
                root.webkitRequestFullscreen();
                return true;
            }

            if (root.msRequestFullscreen) {
                root.msRequestFullscreen();
                return true;
            }
        } catch (e) {
            return false;
        }

        return false;
    }

    async function cashierExitFullscreen() {
        try {
            if (document.exitFullscreen) {
                await document.exitFullscreen();
                return true;
            }

            if (document.webkitExitFullscreen) {
                document.webkitExitFullscreen();
                return true;
            }

            if (document.msExitFullscreen) {
                document.msExitFullscreen();
                return true;
            }
        } catch (e) {
            return false;
        }

        return false;
    }

    function cashierSyncFullscreenUi() {
        const label = document.getElementById('cashier-fullscreen-label');
        const button = document.getElementById('cashier-fullscreen-btn');
        if (!label || !button) return;

        const active = cashierIsFullscreenActive();
        label.textContent = active ? 'Keluar Fullscreen' : 'Masuk Fullscreen';

        if (active) {
            button.classList.remove('bg-gray-50', 'border-gray-200', 'text-gray-700');
            button.classList.add('bg-emerald-50', 'border-emerald-200', 'text-emerald-700');
            return;
        }

        button.classList.remove('bg-emerald-50', 'border-emerald-200', 'text-emerald-700');
        button.classList.add('bg-gray-50', 'border-gray-200', 'text-gray-700');
    }

    async function cashierToggleFullscreen(event) {
        if (event) event.preventDefault();

        const ui = cashierEnsureUi();
        const active = cashierIsFullscreenActive();
        const ok = active ? await cashierExitFullscreen() : await cashierEnterFullscreen();

        if (!ok) {
            ui.toast('Browser menolak fullscreen otomatis. Untuk desktop, bisa pakai tombol F11.', 'error');
            return;
        }

        cashierSyncFullscreenUi();
        ui.toast(active ? 'Keluar dari fullscreen.' : 'Fullscreen aktif.', 'success');
    }

        function cashierCloseSidebarOnMobile() {
            if (!cashierIsMobileViewport()) return;
            document.body.classList.remove('cashier-sidebar-open');
            cashierSyncSidebarState();
        }

        async function cashierExitModeConfirm(event) {
            if (event) event.preventDefault();
            const targetUrl = event?.currentTarget?.getAttribute('href') || @js(route('admin.dashboard'));
            const ui = cashierEnsureUi();
            const ok = await ui.confirm({
                title: @js(__('ui.confirm_title')),
                message: @js(__('ui.confirm_exit_cashier')),
                confirmText: @js(__('ui.exit_cashier_mode')),
                cancelText: @js(__('ui.cancel')),
            });

            if (ok) {
                window.location.href = targetUrl;
            }
        }

        async function cashierLogoutConfirm(event) {
            if (event) event.preventDefault();
            const form = event?.currentTarget?.form || document.getElementById('cashier-logout-form');
            const ui = cashierEnsureUi();
            const ok = await ui.confirm({
                title: @js(__('ui.confirm_title')),
                message: @js(__('ui.confirm_logout')),
                confirmText: @js(__('ui.logout')),
                cancelText: @js(__('ui.cancel')),
            });

            if (ok && form) {
                form.submit();
            }
        }
        
    // Initialize sidebar state
    (function(){ 
        try{ 
            cashierEnsureUi();
            const shouldHide = localStorage.getItem('cashier.sidebarHidden') === 'true';
            if (!cashierIsMobileViewport() && shouldHide) {
                document.body.classList.add('cashier-sidebar-hidden');
            } else {
                document.body.classList.remove('cashier-sidebar-hidden');
            }

            document.body.classList.remove('cashier-sidebar-open');

            cashierSyncSidebarState({ forceHideOnMobile: true });

            const sidebar = document.getElementById('cashier-sidebar');
            if (sidebar) {
                sidebar.addEventListener('click', function (event) {
                    const targetLink = event.target instanceof Element ? event.target.closest('a[href]') : null;
                    if (targetLink) {
                        cashierCloseSidebarOnMobile();
                    }
                });
            }

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    cashierCloseSidebarOnMobile();
                }

                if ((event.key === 'f' || event.key === 'F') && event.altKey) {
                    event.preventDefault();
                    cashierToggleFullscreen();
                }
            });

            document.addEventListener('fullscreenchange', cashierSyncFullscreenUi);
            document.addEventListener('webkitfullscreenchange', cashierSyncFullscreenUi);
            document.addEventListener('msfullscreenchange', cashierSyncFullscreenUi);
            cashierSyncFullscreenUi();

            window.cashierFullscreen = {
                isActive: cashierIsFullscreenActive,
                enter: cashierEnterFullscreen,
                exit: cashierExitFullscreen,
                toggle: cashierToggleFullscreen,
            };

            let resizeTimer = null;
            const handleViewportChange = () => {
                if (resizeTimer) {
                    window.clearTimeout(resizeTimer);
                }
                resizeTimer = window.setTimeout(() => {
                    cashierSyncSidebarState({ forceHideOnMobile: true });
                }, 120);
            };

            window.addEventListener('resize', handleViewportChange, { passive: true });
            window.addEventListener('orientationchange', handleViewportChange, { passive: true });
        } catch(e){} 
    })();
</script>