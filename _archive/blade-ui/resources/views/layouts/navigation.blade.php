@php
    $realtimePublicUrl = (string) config('realtime.public_url');

    $dummyAuth = app(\App\Services\Auth\DummyAuthService::class);
    $globalUser = $dummyAuth->currentGlobalUser(request());
    $cashierUser = $dummyAuth->currentCashierUser(request());
    $sessionUser = $globalUser ?: $cashierUser;

    $role = strtolower((string) ($sessionUser['role'] ?? ''));
    $isAdmin = in_array($role, ['admin', 'tenant_admin', 'super_admin'], true);

    $baseUrl = url('/');

    // Resolve tenant context early so we can generate tenant-aware admin routes safely
    $tenantParam = request()->route('tenant') ?? null;
    if (!$tenantParam && app()->bound(\App\Services\Tenancy\TenantContext::class)) {
        $tenantParam = app(\App\Services\Tenancy\TenantContext::class)->id ?? null;
    }

    if ($tenantParam && \Illuminate\Support\Facades\Route::has('admin.dashboard')) {
        try {
            $dashboardUrl = route('admin.dashboard', ['tenant' => $tenantParam]);
        } catch (\Exception $e) {
            $dashboardUrl = $baseUrl;
        }
    } else {
        $dashboardUrl = \Illuminate\Support\Facades\Route::has('dashboard') ? route('dashboard') : $baseUrl;
    }

    $countsUrl = null;
    // Safely build admin routes only when tenant param exists (or when a non-tenant version is available)
    $safeAdminRoute = function ($name) use ($tenantParam) {
        try {
            if ($tenantParam && \Illuminate\Support\Facades\Route::has($name)) {
                return route($name, ['tenant' => $tenantParam]);
            }

            if (\Illuminate\Support\Facades\Route::has($name)) {
                return route($name);
            }
        } catch (\Exception $e) {
            return null;
        }

        return null;
    };

    if ($isAdmin) {
        $countsUrl = $safeAdminRoute('admin.notifications.counts');
    }

    $logoutRouteName = \Illuminate\Support\Facades\Route::has('auth.logout')
        ? 'auth.logout'
        : (\Illuminate\Support\Facades\Route::has('logout') ? 'logout' : null);

    $profileUrl = \Illuminate\Support\Facades\Route::has('profile.edit') ? route('profile.edit') : null;
    $userName = (string) ($sessionUser['name'] ?? '');
    $userEmail = (string) ($sessionUser['email'] ?? '');

    // tenantId for JS socket room joining
    $tenantId = $tenantParam ?? null;

    // Precompute admin section URLs and only render links when we could build them
    $adminUrls = [];
    if ($isAdmin) {
        $adminUrls['cashier'] = $safeAdminRoute('admin.cashier.index');
        $adminUrls['kitchen'] = $safeAdminRoute('admin.kitchen.index');
        $adminUrls['orders_history'] = $safeAdminRoute('admin.orders.history');
        $adminUrls['reports'] = $safeAdminRoute('admin.reports.index');
        $adminUrls['tables'] = $safeAdminRoute('admin.tables.index');
        $adminUrls['categories'] = $safeAdminRoute('admin.categories.index');
        $adminUrls['products'] = $safeAdminRoute('admin.products.index');
        $adminUrls['customers'] = $safeAdminRoute('admin.customers.index');
        $adminUrls['reservations'] = $safeAdminRoute('admin.reservations.index');
        $adminUrls['brand'] = $safeAdminRoute('admin.brand.edit');
        $adminUrls['payments'] = $safeAdminRoute('admin.payments.edit');
        $adminUrls['loyalty'] = $safeAdminRoute('admin.loyalty.edit');
        $adminUrls['site_promotions'] = $safeAdminRoute('admin.site-promotions.index');
        $adminUrls['member_promotions'] = $safeAdminRoute('admin.member-promotions.index');
        $adminUrls['manage_menu'] = $safeAdminRoute('admin.manage-menu');
    }
@endphp

<nav x-data="adminNav(@js($isAdmin), @js($realtimePublicUrl), @js($baseUrl), @js($countsUrl), @js($tenantId))" class="sticky top-0 z-50 bg-white border-b border-gray-100">
    <!-- Primary Navigation Menu -->
    <div class="w-full mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
            <div class="flex min-w-0 flex-1">
                <!-- Logo -->
                <div class="shrink-0 flex items-center">
                    @php
                        if (!isset($brand) || !$brand) {
                            try {
                                $brand = \App\Models\BrandSetting::current();
                            } catch (\Exception $e) {
                                $brand = null;
                            }
                        }
                    @endphp
                    <a href="{{ $dashboardUrl }}">
                        @if($brand?->logoLightUrl())
                            <img src="{{ $brand->logoLightUrl() }}" alt="Logo" class="block h-9 w-auto object-contain" />
                        @else
                            <x-application-logo class="block h-9 w-auto fill-current text-gray-800" />
                        @endif
                    </a>
                </div>

                <!-- Navigation Links -->
                <div class="hidden lg:flex lg:ms-10 lg:-my-px min-w-0 flex-1 items-center gap-6 overflow-x-auto whitespace-nowrap">
                    <x-nav-link :href="$dashboardUrl" :active="request()->routeIs('admin.*')">{{ __('Dashboard') }}</x-nav-link>

                    @if($isAdmin)
                        @if(!empty($adminUrls['cashier']))
                            <x-nav-link :href="$adminUrls['cashier']" :active="request()->routeIs('admin.cashier.*')">{{ __('Kasir') }}</x-nav-link>
                        @endif
                        @if(!empty($adminUrls['kitchen']))
                            <x-nav-link :href="$adminUrls['kitchen']" :active="request()->routeIs('admin.kitchen.*')">{{ __('Kitchen') }}</x-nav-link>
                        @endif
                        @if(!empty($adminUrls['orders_history']))
                            <x-nav-link :href="$adminUrls['orders_history']" :active="request()->routeIs('admin.orders.history')">{{ __('History') }}</x-nav-link>
                        @endif
                        @if(!empty($adminUrls['reports']))
                            <x-nav-link :href="$adminUrls['reports']" :active="request()->routeIs('admin.reports.*')">{{ __('Laporan') }}</x-nav-link>
                        @endif
                        @if(!empty($adminUrls['tables']))
                            <x-nav-link :href="$adminUrls['tables']" :active="request()->routeIs('admin.tables.*')">{{ __('Tables') }}</x-nav-link>
                        @endif
                        @if(!empty($adminUrls['categories']))
                            <x-nav-link :href="$adminUrls['categories']" :active="request()->routeIs('admin.categories.*')">{{ __('Categories') }}</x-nav-link>
                        @endif
                        @if(!empty($adminUrls['products']))
                            <x-nav-link :href="$adminUrls['products']" :active="request()->routeIs('admin.products.*')">{{ __('Products') }}</x-nav-link>
                        @endif

                        @if(!empty($adminUrls['customers']))
                            <x-nav-link :href="$adminUrls['customers']" :active="request()->routeIs('admin.customers.*')">{{ __('Customers') }}</x-nav-link>
                        @endif

                        @if(!empty($adminUrls['reservations']))
                            <x-nav-link :href="$adminUrls['reservations']" :active="request()->routeIs('admin.reservations.*') || request()->routeIs('admin.reservation-spaces.*')">
                                <span class="inline-flex items-center gap-2">
                                    <span>{{ __('Reservasi') }}</span>
                                    <span
                                        x-show="reservationsPendingCount > 0"
                                        x-cloak
                                        class="min-w-[1.25rem] h-5 px-1.5 inline-flex items-center justify-center rounded-full bg-amber-600 text-white text-[11px] font-bold leading-none"
                                        x-text="reservationsPendingCount > 99 ? '99+' : String(reservationsPendingCount)"></span>
                                </span>
                            </x-nav-link>
                        @endif

                        @if(!empty($adminUrls['brand']))
                            <x-nav-link :href="$adminUrls['brand']" :active="request()->routeIs('admin.brand.*')">{{ __('Brand') }}</x-nav-link>
                        @endif
                        @if(!empty($adminUrls['payments']))
                            <x-nav-link :href="$adminUrls['payments']" :active="request()->routeIs('admin.payments.*')">{{ __('Payment') }}</x-nav-link>
                        @endif
                        @if(!empty($adminUrls['loyalty']))
                            <x-nav-link :href="$adminUrls['loyalty']" :active="request()->routeIs('admin.loyalty.*')">{{ __('Loyalty') }}</x-nav-link>
                        @endif
                        @if(!empty($adminUrls['site_promotions']))
                            <x-nav-link :href="$adminUrls['site_promotions']" :active="request()->routeIs('admin.site-promotions.*')">{{ __('Promo/Event') }}</x-nav-link>
                        @endif
                        @if(!empty($adminUrls['member_promotions']))
                            <x-nav-link :href="$adminUrls['member_promotions']" :active="request()->routeIs('admin.member-promotions.*')">{{ __('Promo Member') }}</x-nav-link>
                        @endif
                    @endif
                </div>
            </div>

            <!-- Settings Dropdown -->
            <div class="hidden lg:flex lg:items-center lg:ms-6">
                @if($sessionUser)
                <x-dropdown align="right" width="48">
                    <x-slot name="trigger">
                        <button class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-gray-500 bg-white hover:text-gray-700 focus:outline-none transition ease-in-out duration-150">
                            <div>{{ $userName !== '' ? $userName : 'User' }}</div>

                            <div class="ms-1">
                                <svg class="fill-current h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                </svg>
                            </div>
                        </button>
                    </x-slot>

                    <x-slot name="content">
                        @if($profileUrl)
                            <x-dropdown-link :href="$profileUrl">
                                {{ __('Profile') }}
                            </x-dropdown-link>
                        @endif

                        <!-- Authentication -->
                        @if($logoutRouteName)
                            <form method="POST" action="{{ route($logoutRouteName) }}">
                                @csrf

                                <x-dropdown-link :href="route($logoutRouteName)"
                                        onclick="event.preventDefault();
                                                    this.closest('form').submit();">
                                    {{ __('Log Out') }}
                                </x-dropdown-link>
                            </form>
                        @endif
                    </x-slot>
                </x-dropdown>
                @else
                    <a href="{{ \Illuminate\Support\Facades\Route::has('auth.login') ? route('auth.login') : $baseUrl }}" class="px-4 py-2 rounded-xl border bg-white text-sm font-semibold">Login</a>
                @endif
            </div>

            <!-- Hamburger -->
            <div class="-me-2 flex items-center lg:hidden">
                <button @click="open = ! open" class="inline-flex items-center justify-center p-2 rounded-md text-gray-400 hover:text-gray-500 hover:bg-gray-100 focus:outline-none focus:bg-gray-100 focus:text-gray-500 transition duration-150 ease-in-out">
                    <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                        <path :class="{'hidden': open, 'inline-flex': ! open }" class="inline-flex" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        <path :class="{'hidden': ! open, 'inline-flex': open }" class="hidden" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>
    </div>

    <!-- Responsive Navigation Menu -->
    <div :class="{'block': open, 'hidden': ! open}" class="hidden lg:hidden max-h-[calc(100vh-4rem)] overflow-y-auto">
        <div class="pt-2 pb-3 space-y-1">
            <x-responsive-nav-link :href="$dashboardUrl" :active="request()->routeIs('admin.*')">{{ __('Dashboard') }}</x-responsive-nav-link>

            @if($isAdmin)
                @if(!empty($adminUrls['cashier']))
                    <x-responsive-nav-link :href="$adminUrls['cashier']" :active="request()->routeIs('admin.cashier.*')">{{ __('Kasir') }}</x-responsive-nav-link>
                @endif
                @if(!empty($adminUrls['kitchen']))
                    <x-responsive-nav-link :href="$adminUrls['kitchen']" :active="request()->routeIs('admin.kitchen.*')">{{ __('Kitchen') }}</x-responsive-nav-link>
                @endif
                @if(!empty($adminUrls['orders_history']))
                    <x-responsive-nav-link :href="$adminUrls['orders_history']" :active="request()->routeIs('admin.orders.history')">{{ __('History') }}</x-responsive-nav-link>
                @endif
                @if(!empty($adminUrls['reports']))
                    <x-responsive-nav-link :href="$adminUrls['reports']" :active="request()->routeIs('admin.reports.*')">{{ __('Laporan') }}</x-responsive-nav-link>
                @endif
                @if(!empty($adminUrls['tables']))
                    <x-responsive-nav-link :href="$adminUrls['tables']" :active="request()->routeIs('admin.tables.*')">{{ __('Tables') }}</x-responsive-nav-link>
                @endif
                @if(!empty($adminUrls['categories']))
                    <x-responsive-nav-link :href="$adminUrls['categories']" :active="request()->routeIs('admin.categories.*')">{{ __('Categories') }}</x-responsive-nav-link>
                @endif
                @if(!empty($adminUrls['products']))
                    <x-responsive-nav-link :href="$adminUrls['products']" :active="request()->routeIs('admin.products.*')">{{ __('Products') }}</x-responsive-nav-link>
                @endif

                @if(!empty($adminUrls['manage_menu']))
                    <x-responsive-nav-link :href="$adminUrls['manage_menu']" :active="request()->routeIs('admin.manage-menu*')">{{ __('Manage Menu') }}</x-responsive-nav-link>
                @endif

                @if(!empty($adminUrls['customers']))
                    <x-responsive-nav-link :href="$adminUrls['customers']" :active="request()->routeIs('admin.customers.*')">{{ __('Customers') }}</x-responsive-nav-link>
                @endif

                @if(!empty($adminUrls['reservations']))
                    <x-responsive-nav-link :href="$adminUrls['reservations']" :active="request()->routeIs('admin.reservations.*') || request()->routeIs('admin.reservation-spaces.*')">
                        <span class="inline-flex items-center justify-between w-full gap-3">
                            <span>{{ __('Reservasi') }}</span>
                            <span
                                x-show="reservationsPendingCount > 0"
                                x-cloak
                                class="min-w-[1.25rem] h-5 px-1.5 inline-flex items-center justify-center rounded-full bg-amber-600 text-white text-[11px] font-bold leading-none"
                                x-text="reservationsPendingCount > 99 ? '99+' : String(reservationsPendingCount)"></span>
                        </span>
                    </x-responsive-nav-link>
                @endif
                @if(!empty($adminUrls['brand']))
                    <x-responsive-nav-link :href="$adminUrls['brand']" :active="request()->routeIs('admin.brand.*')">{{ __('Brand') }}</x-responsive-nav-link>
                @endif
                @if(!empty($adminUrls['payments']))
                    <x-responsive-nav-link :href="$adminUrls['payments']" :active="request()->routeIs('admin.payments.*')">{{ __('Payment') }}</x-responsive-nav-link>
                @endif
                @if(!empty($adminUrls['loyalty']))
                    <x-responsive-nav-link :href="$adminUrls['loyalty']" :active="request()->routeIs('admin.loyalty.*')">{{ __('Loyalty') }}</x-responsive-nav-link>
                @endif
                @if(!empty($adminUrls['site_promotions']))
                    <x-responsive-nav-link :href="$adminUrls['site_promotions']" :active="request()->routeIs('admin.site-promotions.*')">{{ __('Promo/Event') }}</x-responsive-nav-link>
                @endif
                @if(!empty($adminUrls['member_promotions']))
                    <x-responsive-nav-link :href="$adminUrls['member_promotions']" :active="request()->routeIs('admin.member-promotions.*')">{{ __('Promo Member') }}</x-responsive-nav-link>
                @endif
            @endif
        </div>

        <!-- Responsive Settings Options -->
        <div class="pt-4 pb-1 border-t border-gray-200">
            <div class="px-4">
                <div class="font-medium text-base text-gray-800">{{ $userName !== '' ? $userName : 'User' }}</div>
                <div class="font-medium text-sm text-gray-500">{{ $userEmail }}</div>
            </div>

            <div class="mt-3 space-y-1">
                @if($profileUrl)
                    <x-responsive-nav-link :href="$profileUrl">
                        {{ __('Profile') }}
                    </x-responsive-nav-link>
                @endif

                <!-- Authentication -->
                @if($logoutRouteName)
                    <form method="POST" action="{{ route($logoutRouteName) }}">
                        @csrf

                        <x-responsive-nav-link :href="route($logoutRouteName)"
                                onclick="event.preventDefault();
                                            this.closest('form').submit();">
                            {{ __('Log Out') }}
                        </x-responsive-nav-link>
                    </form>
                @endif
            </div>
        </div>
    </div>
</nav>

<script>
    function adminNav(isAdmin, realtimePublicUrl, baseUrl, countsUrl, tenantId) {
        return {
            open: false,
            isAdmin: !!isAdmin,
            realtimePublicUrl: realtimePublicUrl || null,
            baseUrl: baseUrl || '',
            countsUrl: countsUrl || null,
            tenantId: tenantId || null,

            ordersNewCount: 0,
            reservationsPendingCount: 0,

            _countsTimer: null,
            _refreshTimer: null,
            _socket: null,

            async fetchCounts() {
                if (!this.isAdmin || !this.countsUrl) return;
                try {
                    const res = await fetch(this.countsUrl, {
                        method: 'GET',
                        headers: { 'Accept': 'application/json' },
                        credentials: 'same-origin',
                    });
                    if (!res.ok) return;
                    const json = await res.json();
                    this.ordersNewCount = Number(json?.orders_new || 0);
                    this.reservationsPendingCount = Number(json?.reservations_pending || 0);
                } catch (e) {
                    // ignore
                }
            },

            scheduleFetchCounts() {
                clearTimeout(this._countsTimer);
                this._countsTimer = setTimeout(() => this.fetchCounts(), 250);
            },

            async ensureSocketIoScript() {
                if (window.io) return true;
                if (!this.realtimePublicUrl) return false;

                const scriptUrl = `${this.realtimePublicUrl.replace(/\/$/, '')}/socket.io/socket.io.js`;
                return await new Promise((resolve) => {
                    const s = document.createElement('script');
                    s.src = scriptUrl;
                    s.async = true;
                    s.onload = () => resolve(true);
                    s.onerror = () => resolve(false);
                    document.head.appendChild(s);
                });
            },

            async connectSocket() {
                if (!this.isAdmin) return;
                const ok = await this.ensureSocketIoScript();
                if (!ok || !window.io || !this.realtimePublicUrl) return;

                try {
                    this._socket = window.io(this.realtimePublicUrl, {
                        transports: ['websocket', 'polling'],
                        timeout: 2000,
                    });

                    // Join tenant-specific room if tenantId is available
                    if (this.tenantId) {
                        this._socket.emit('join', `tenant_${this.tenantId}`);
                    }

                    const onAnyUpdate = () => this.scheduleFetchCounts();
                    this._socket.on('connect', onAnyUpdate);
                    this._socket.on('order.created', onAnyUpdate);
                    this._socket.on('order.updated', onAnyUpdate);
                    this._socket.on('reservation.created', onAnyUpdate);
                    this._socket.on('reservation.updated', onAnyUpdate);
                } catch (e) {
                    // ignore
                }
            },

            init() {
                if (!this.isAdmin) return;
                this.fetchCounts();
                this.connectSocket();
                this._refreshTimer = setInterval(() => this.fetchCounts(), 30000);
            },
        }
    }
</script>
