@php
    // Determine tenant slug safely — controllers may pass a Tenant object, an array, or a slug string
    $tenantSlug = null;
    if (isset($tenant)) {
        if (is_object($tenant) && property_exists($tenant, 'slug')) {
            $tenantSlug = $tenant->slug;
        } elseif (is_array($tenant) && array_key_exists('slug', $tenant)) {
            $tenantSlug = $tenant['slug'];
        } elseif (is_string($tenant)) {
            $tenantSlug = $tenant;
        }
    }

    $bp = $basePath ?? ($tenantSlug ? '/t/'.$tenantSlug : '');
    $activeLocale = app()->getLocale();
@endphp

<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', $brand?->business_name ?? config('app.name', 'App'))</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @yield('styles')
    @yield('scripts')
</head>
<body class="font-sans antialiased bg-gray-50">
    <div class="min-h-screen flex" x-data="adminSidebar()">
        <!-- Sidebar -->
           <div class="sidebar-transition fixed inset-y-0 left-0 z-50 w-64 bg-white shadow-lg border-r border-gray-200 lg:static lg:inset-0"
               :class="{ 'sidebar-hidden': sidebarCollapsed && isDesktop, 'sidebar-mobile-hidden': sidebarCollapsed && !isDesktop }"
               x-show="!(sidebarCollapsed && !isDesktop)"
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="transform -translate-x-full"
             x-transition:enter-end="transform translate-x-0"
             x-transition:leave="transition ease-in duration-300"
             x-transition:leave-start="transform translate-x-0"
             x-transition:leave-end="transform -translate-x-full">

            <div class="flex flex-col h-full">
                <!-- Logo & Toggle -->
                <div class="flex items-center justify-between h-16 px-4 border-b border-gray-200">
                    <div class="flex items-center space-x-2 flex-1 min-w-0">
                        @php
                            if (!isset($brand) || !$brand) {
                                try {
                                    $brand = \App\Models\BrandSetting::current();
                                } catch (\Exception $e) {
                                    $brand = null;
                                }
                            }
                        @endphp
                        @if($brand?->logoLightUrl())
                            <img src="{{ $brand->logoLightUrl() }}" alt="Logo" class="h-8 w-auto object-contain flex-shrink-0" />
                        @else
                            <div class="w-8 h-8 bg-emerald-500 rounded-lg flex items-center justify-center flex-shrink-0">
                                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                                </svg>
                            </div>
                        @endif
                        <span class="text-gray-900 font-bold text-lg truncate" x-show="!sidebarCollapsed || !isDesktop">{{ $brand?->business_name ?? 'Admin Panel' }}</span>
                    </div>
                    <button @click="toggleSidebar()"
                            class="flex items-center justify-center w-8 h-8 rounded-lg text-gray-500 hover:text-gray-700 hover:bg-gray-100 transition-colors duration-200 lg:flex">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" x-show="!sidebarCollapsed || !isDesktop">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                        </svg>
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" x-show="sidebarCollapsed && isDesktop" x-cloak>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                        </svg>
                    </button>
                </div>

                <!-- Navigation -->
                <nav class="flex-1 px-4 py-6 space-y-1 overflow-y-auto">
                          <a href="{{ route('admin.dashboard') }}"
                              class="nav-link flex items-center px-4 py-3 text-sm font-medium rounded-lg transition-all duration-200 {{ request()->routeIs('admin.dashboard') ? 'bg-emerald-50 text-emerald-700 border-r-2 border-emerald-500' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}">
                        <svg class="w-5 h-5 mr-3 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2H5a2 2 0 00-2-2z"></path>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5a2 2 0 012-2h4a2 2 0 012 2v2H8V5z"></path>
                        </svg>
                        <span x-show="!sidebarCollapsed || !isDesktop">{{ __('ui.dashboard') }}</span>
                    </a>

                                  <a href="{{ route('admin.cashier.index') }}"
                              class="nav-link flex items-center px-4 py-3 text-sm font-medium rounded-lg transition-all duration-200 {{ request()->routeIs('admin.cashier.*') ? 'bg-emerald-50 text-emerald-700 border-r-2 border-emerald-500' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}">
                        <svg class="w-5 h-5 mr-3 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                        </svg>
                        <span x-show="!sidebarCollapsed || !isDesktop">{{ __('ui.cashier') }}</span>
                    </a>

                          <a href="{{ route('admin.kitchen.index') }}"
                              class="nav-link flex items-center px-4 py-3 text-sm font-medium rounded-lg transition-all duration-200 {{ request()->routeIs('admin.kitchen.*') ? 'bg-emerald-50 text-emerald-700 border-r-2 border-emerald-500' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}">
                        <svg class="w-5 h-5 mr-3 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 18.657A8 8 0 016.343 7.343S7 9 9 10c0-2 .5-5 2.986-7C14 5 16.09 5.777 17.656 7.343A7.975 7.975 0 0120 13a7.975 7.975 0 01-2.343 5.657z"></path>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.879 16.121A3 3 0 1012.015 11L11 14H9c0 .768.293 1.536.879 2.121z"></path>
                        </svg>
                        <span x-show="!sidebarCollapsed || !isDesktop">{{ __('ui.kitchen') }}</span>
                    </a>

                          <a href="{{ route('admin.orders.index') }}"
                              class="nav-link flex items-center px-4 py-3 text-sm font-medium rounded-lg transition-all duration-200 {{ request()->routeIs('admin.orders.*') ? 'bg-emerald-50 text-emerald-700 border-r-2 border-emerald-500' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}">
                        <svg class="w-5 h-5 mr-3 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                        <span x-show="!sidebarCollapsed || !isDesktop">{{ __('ui.orders') }}</span>
                    </a>

                          <a href="{{ route('admin.reports.index') }}"
                              class="nav-link flex items-center px-4 py-3 text-sm font-medium rounded-lg transition-all duration-200 {{ request()->routeIs('admin.reports.*') ? 'bg-emerald-50 text-emerald-700 border-r-2 border-emerald-500' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}">
                        <svg class="w-5 h-5 mr-3 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                        </svg>
                        <span x-show="!sidebarCollapsed || !isDesktop">{{ __('ui.reports') }}</span>
                    </a>

                                  <a href="{{ route('admin.tables.index') }}"
                              class="nav-link flex items-center px-4 py-3 text-sm font-medium rounded-lg transition-all duration-200 {{ request()->routeIs('admin.tables.*') ? 'bg-emerald-50 text-emerald-700 border-r-2 border-emerald-500' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}">
                        <svg class="w-5 h-5 mr-3 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M3 14h18m-9-4v8m-7 0h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                        </svg>
                        <span x-show="!sidebarCollapsed || !isDesktop">{{ __('ui.tables') }}</span>
                    </a>

                                  <a href="{{ route('admin.categories.index') }}"
                              class="nav-link flex items-center px-4 py-3 text-sm font-medium rounded-lg transition-all duration-200 {{ request()->routeIs('admin.categories.*') ? 'bg-emerald-50 text-emerald-700 border-r-2 border-emerald-500' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}">
                        <svg class="w-5 h-5 mr-3 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                        </svg>
                        <span x-show="!sidebarCollapsed || !isDesktop">{{ __('ui.categories') }}</span>
                    </a>

                                  <a href="{{ route('admin.products.index') }}"
                              class="nav-link flex items-center px-4 py-3 text-sm font-medium rounded-lg transition-all duration-200 {{ request()->routeIs('admin.products.*') ? 'bg-emerald-50 text-emerald-700 border-r-2 border-emerald-500' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}">
                        <svg class="w-5 h-5 mr-3 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                        </svg>
                        <span x-show="!sidebarCollapsed || !isDesktop">{{ __('ui.products') }}</span>
                    </a>

                                  <a href="{{ route('admin.packages.index') }}"
                              class="nav-link flex items-center px-4 py-3 text-sm font-medium rounded-lg transition-all duration-200 {{ request()->routeIs('admin.packages.*') ? 'bg-emerald-50 text-emerald-700 border-r-2 border-emerald-500' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}">
                        <svg class="w-5 h-5 mr-3 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                        </svg>
                        <span x-show="!sidebarCollapsed || !isDesktop">{{ __('ui.packages') }}</span>
                    </a>

                                  <a href="{{ route('admin.members.index') }}"
                              class="nav-link flex items-center px-4 py-3 text-sm font-medium rounded-lg transition-all duration-200 {{ request()->routeIs('admin.customers.*') || request()->routeIs('admin.members.*') ? 'bg-emerald-50 text-emerald-700 border-r-2 border-emerald-500' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}">
                        <svg class="w-5 h-5 mr-3 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197m13.5-9a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0z"></path>
                        </svg>
                        <span x-show="!sidebarCollapsed || !isDesktop">{{ __('ui.customers') }}</span>
                    </a>

                                  <a href="{{ route('admin.reservations.index') }}"
                              class="nav-link flex items-center px-4 py-3 text-sm font-medium rounded-lg transition-all duration-200 {{ request()->routeIs('admin.reservations.*') || request()->routeIs('admin.reservation-spaces.*') ? 'bg-emerald-50 text-emerald-700 border-r-2 border-emerald-500' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}">
                        <svg class="w-5 h-5 mr-3 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                        </svg>
                        <span x-show="!sidebarCollapsed || !isDesktop">{{ __('ui.reservations') }}</span>
                    </a>

                    <!-- Settings Section -->
                    <div class="pt-6 mt-6 border-t border-gray-200" x-show="!sidebarCollapsed || !isDesktop">
                        <p class="px-4 text-xs font-semibold text-gray-400 uppercase tracking-wider">{{ __('ui.settings') }}</p>
                    </div>

                          <a href="{{ route('admin.brand.edit') }}"
                              class="nav-link flex items-center px-4 py-3 text-sm font-medium rounded-lg transition-all duration-200 {{ request()->routeIs('admin.brand.*') ? 'bg-emerald-50 text-emerald-700 border-r-2 border-emerald-500' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}">
                        <svg class="w-5 h-5 mr-3 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zM21 5a2 2 0 00-2-2h-4a2 2 0 00-2 2v12a4 4 0 004 4h4a2 2 0 002-2V5z"></path>
                        </svg>
                        <span x-show="!sidebarCollapsed || !isDesktop">{{ __('ui.brand') }}</span>
                    </a>

                          <a href="{{ route('admin.payment.edit') }}"
                              class="nav-link flex items-center px-4 py-3 text-sm font-medium rounded-lg transition-all duration-200 {{ request()->routeIs('admin.payment.*') ? 'bg-emerald-50 text-emerald-700 border-r-2 border-emerald-500' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}">
                        <svg class="w-5 h-5 mr-3 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"></path>
                        </svg>
                        <span x-show="!sidebarCollapsed || !isDesktop">{{ __('ui.payment') }}</span>
                    </a>

                          <a href="{{ route('admin.loyalty.edit') }}"
                              class="nav-link flex items-center px-4 py-3 text-sm font-medium rounded-lg transition-all duration-200 {{ request()->routeIs('admin.loyalty.*') ? 'bg-emerald-50 text-emerald-700 border-r-2 border-emerald-500' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}">
                        <svg class="w-5 h-5 mr-3 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"></path>
                        </svg>
                        <span x-show="!sidebarCollapsed || !isDesktop">{{ __('ui.loyalty') }}</span>
                    </a>

                                  <a href="{{ route('admin.site-promotions.index') }}"
                              class="nav-link flex items-center px-4 py-3 text-sm font-medium rounded-lg transition-all duration-200 {{ request()->routeIs('admin.site-promotions.*') ? 'bg-emerald-50 text-emerald-700 border-r-2 border-emerald-500' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}">
                        <svg class="w-5 h-5 mr-3 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"></path>
                        </svg>
                        <span x-show="!sidebarCollapsed || !isDesktop">{{ __('ui.promotions') }}</span>
                    </a>

                                  <a href="{{ route('admin.member-promotions.index') }}"
                              class="nav-link flex items-center px-4 py-3 text-sm font-medium rounded-lg transition-all duration-200 {{ request()->routeIs('admin.member-promotions.*') ? 'bg-emerald-50 text-emerald-700 border-r-2 border-emerald-500' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}">
                        <svg class="w-5 h-5 mr-3 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1"></path>
                        </svg>
                        <span x-show="!sidebarCollapsed || !isDesktop">{{ __('ui.member_promotions') }}</span>
                    </a>
                </nav>

                <!-- User Menu -->
                <div class="p-4 border-t border-gray-200">
                    <div class="flex items-center space-x-3">
                        <div class="w-10 h-10 bg-emerald-100 rounded-full flex items-center justify-center flex-shrink-0">
                            <svg class="w-5 h-5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                            </svg>
                        </div>
                        <div class="flex-1 min-w-0 user-info" x-show="!sidebarCollapsed || !isDesktop">
                            <p class="text-sm font-medium text-gray-900 truncate">{{ auth()->user()->name ?? 'Admin' }}</p>
                            <p class="text-xs text-gray-500 truncate">{{ auth()->user()->email ?? '' }}</p>
                        </div>
                        <form method="POST" action="{{ route('logout') }}" class="inline">
                            @csrf
                            <button type="submit" class="text-gray-400 hover:text-gray-600 p-2 rounded-lg hover:bg-gray-100 transition-colors duration-200" title="{{ __('ui.logout') }}">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
                                </svg>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Mobile Overlay -->
           <div x-show="!sidebarCollapsed && !isDesktop"
             @click="toggleSidebar()"
             class="fixed inset-0 z-40 bg-black bg-opacity-50 lg:hidden"
             x-transition:enter="transition-opacity ease-linear duration-300"
             x-transition:enter-start="opacity-0"
             x-transition:enter-end="opacity-100"
             x-transition:leave="transition-opacity ease-linear duration-300"
             x-transition:leave-start="opacity-100"
             x-transition:leave-end="opacity-0"
               x-cloak></div>

        <!-- Main Content -->
        <div class="flex-1 flex flex-col overflow-hidden main-transition"
             :class="{
                 'ml-64': !sidebarCollapsed && isDesktop,
                 'ml-16': sidebarCollapsed && isDesktop,
                 'ml-0': !isDesktop
             }">
            <!-- Top Bar -->
            <header class="bg-white shadow-sm border-b border-gray-200">
                <div class="px-6 py-4">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center space-x-4">
                            <!-- Mobile menu button -->
                            <button @click="toggleSidebar()"
                                    class="lg:hidden p-2 rounded-lg text-gray-500 hover:text-gray-700 hover:bg-gray-100 transition-colors duration-200">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                                </svg>
                            </button>

                            <!-- Desktop sidebar toggle -->
                            <button @click="toggleSidebar()"
                                    class="hidden lg:flex p-2 rounded-lg text-gray-500 hover:text-gray-700 hover:bg-gray-100 transition-colors duration-200">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" x-show="!sidebarCollapsed">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                                </svg>
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" x-show="sidebarCollapsed" x-cloak>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                </svg>
                            </button>

                            <!-- Breadcrumb -->
                            <div class="hidden md:flex items-center space-x-2 text-sm text-gray-600">
                                <a href="{{ route('admin.dashboard') }}" class="hover:text-gray-900">{{ __('ui.dashboard') }}</a>
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                </svg>
                                <span class="text-gray-900 font-medium">@yield('page-title', 'Page')</span>
                            </div>
                        </div>

                        <div class="flex items-center space-x-4">
                            <!-- Search -->
                            <div class="hidden md:block relative">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                                    </svg>
                                </div>
                                <input type="text"
                                       placeholder="{{ __('ui.search') }}"
                                       class="block w-full pl-10 pr-3 py-2 border border-gray-300 rounded-lg leading-5 bg-white placeholder-gray-500 focus:outline-none focus:placeholder-gray-400 focus:ring-1 focus:ring-emerald-500 focus:border-emerald-500 text-sm">
                            </div>

                            <div class="hidden md:flex items-center gap-2">
                                <span class="text-xs text-gray-500">{{ __('ui.language') }}</span>
                                <form method="POST" action="{{ route('admin.locale.update') }}" class="inline">
                                    @csrf
                                    <input type="hidden" name="locale" value="id">
                                    <button type="submit" class="px-2.5 py-1 rounded-md text-xs font-semibold border {{ $activeLocale === 'id' ? 'bg-emerald-50 border-emerald-200 text-emerald-700' : 'bg-white border-gray-200 text-gray-600 hover:bg-gray-50' }}">ID</button>
                                </form>
                                <form method="POST" action="{{ route('admin.locale.update') }}" class="inline">
                                    @csrf
                                    <input type="hidden" name="locale" value="en">
                                    <button type="submit" class="px-2.5 py-1 rounded-md text-xs font-semibold border {{ $activeLocale === 'en' ? 'bg-emerald-50 border-emerald-200 text-emerald-700' : 'bg-white border-gray-200 text-gray-600 hover:bg-gray-50' }}">EN</button>
                                </form>
                            </div>

                            <!-- Notifications -->
                            <button class="p-2 rounded-lg text-gray-500 hover:text-gray-700 hover:bg-gray-100 transition-colors duration-200 relative">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-5 5v-5zM15 17H9a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V15a2 2 0 01-2 2z"/>
                                </svg>
                                <span class="absolute top-1 right-1 block w-2 h-2 bg-red-500 rounded-full"></span>
                            </button>

                            <!-- User Menu -->
                            <div class="relative" x-data="{ open: false }">
                                <button @click="open = !open" class="flex items-center space-x-2 p-2 rounded-lg hover:bg-gray-100 transition-colors duration-200">
                                    <div class="w-8 h-8 bg-emerald-100 rounded-full flex items-center justify-center">
                                        <svg class="w-4 h-4 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                                        </svg>
                                    </div>
                                    <div class="hidden md:block text-left">
                                        <p class="text-sm font-medium text-gray-900">{{ auth()->user()->name ?? 'Admin' }}</p>
                                        <p class="text-xs text-gray-500">{{ auth()->user()->email ?? '' }}</p>
                                    </div>
                                    <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                                    </svg>
                                </button>

                                <div x-show="open" @click.away="open = false"
                                     class="absolute right-0 mt-2 w-48 bg-white rounded-lg shadow-lg border border-gray-200 py-1 z-50"
                                     x-transition:enter="transition ease-out duration-100"
                                     x-transition:enter-start="transform opacity-0 scale-95"
                                     x-transition:enter-end="transform opacity-100 scale-100"
                                     x-transition:leave="transition ease-in duration-75"
                                     x-transition:leave-start="transform opacity-100 scale-100"
                                     x-transition:leave-end="transform opacity-0 scale-95">
                                    <div class="px-4 py-2">
                                        <p class="text-xs text-gray-500 mb-1">{{ __('ui.language') }}</p>
                                        <div class="flex items-center gap-1">
                                            <form method="POST" action="{{ route('admin.locale.update') }}" class="inline">
                                                @csrf
                                                <input type="hidden" name="locale" value="id">
                                                <button type="submit" class="px-2 py-1 rounded text-xs font-semibold border {{ $activeLocale === 'id' ? 'bg-emerald-50 border-emerald-200 text-emerald-700' : 'bg-white border-gray-200 text-gray-600 hover:bg-gray-50' }}">ID</button>
                                            </form>
                                            <form method="POST" action="{{ route('admin.locale.update') }}" class="inline">
                                                @csrf
                                                <input type="hidden" name="locale" value="en">
                                                <button type="submit" class="px-2 py-1 rounded text-xs font-semibold border {{ $activeLocale === 'en' ? 'bg-emerald-50 border-emerald-200 text-emerald-700' : 'bg-white border-gray-200 text-gray-600 hover:bg-gray-50' }}">EN</button>
                                            </form>
                                        </div>
                                    </div>
                                    <div class="border-t border-gray-100"></div>
                                    <a href="#" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">{{ __('ui.profile_settings') }}</a>
                                    <a href="#" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">{{ __('ui.account_settings') }}</a>
                                    <div class="border-t border-gray-100"></div>
                                    <form method="POST" action="{{ route('logout') }}">
                                        @csrf
                                        <button type="submit" class="block w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">{{ __('ui.logout') }}</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Page Content -->
            <main class="flex-1 overflow-y-auto bg-gray-50" x-cloak>
                <div class="p-6">
                    @yield('header')
                    @yield('content')
                </div>
            </main>
        </div>
    </div>

    <script>
        window.__imgRetry = function (img) {
            try {
                if (!img) return;
                const maxRetry = 4;
                const current = Number(img.dataset.retryCount || '0');
                if (current >= maxRetry) return;

                const next = current + 1;
                img.dataset.retryCount = String(next);

                const src = img.getAttribute('src') || '';
                if (!src) return;

                const url = new URL(src, window.location.origin);
                url.searchParams.set('_rt', String(Date.now()));

                setTimeout(function () {
                    img.src = url.toString();
                }, 250 * next);
            } catch (e) {}
        };

        function adminSidebar() {
            return {
                sidebarCollapsed: false,
                isDesktop: window.innerWidth >= 1024,

                init() {
                    // Load sidebar state from localStorage
                    const savedState = localStorage.getItem('admin-sidebar-collapsed');
                    if (savedState !== null) {
                        this.sidebarCollapsed = JSON.parse(savedState);
                    }

                    // If on mobile, ensure sidebar is collapsed by default
                    if (!this.isDesktop) {
                        this.sidebarCollapsed = true;
                    }

                    // Update isDesktop on resize and adapt sidebar state
                    window.addEventListener('resize', () => {
                        const wasDesktop = this.isDesktop;
                        this.isDesktop = window.innerWidth >= 1024;
                        if (wasDesktop && !this.isDesktop) {
                            // switched to mobile: collapse sidebar
                            this.sidebarCollapsed = true;
                        }
                        if (!wasDesktop && this.isDesktop) {
                            // switched to desktop: restore saved state or expand
                            const saved = localStorage.getItem('admin-sidebar-collapsed');
                            this.sidebarCollapsed = saved !== null ? JSON.parse(saved) : false;
                        }
                    });
                },

                toggleSidebar() {
                    this.sidebarCollapsed = !this.sidebarCollapsed;
                    localStorage.setItem('admin-sidebar-collapsed', JSON.stringify(this.sidebarCollapsed));
                }
            }
        }
    </script>

    <style>
        [x-cloak] { display: none !important; }
        .sidebar-transition {
            transition: transform 0.3s ease-in-out, width 0.3s ease-in-out;
        }

        .main-transition {
            transition: margin-left 0.3s ease-in-out;
        }

        /* Fallback CSS so main content shifts even when JS/Alpine hasn't initialized yet */
        @media (min-width: 1024px) {
            .main-transition { margin-left: 16rem; }
            .sidebar-hidden ~ .main-transition { margin-left: 4rem; }
            .sidebar-mobile-hidden ~ .main-transition { margin-left: 0; }
        }

        .sidebar-hidden {
            width: 4rem !important;
        }

        .sidebar-mobile-hidden {
            transform: translateX(-100%);
        }

        @media (min-width: 1024px) {
            .sidebar-hidden .nav-link span {
                display: none;
            }

            .sidebar-hidden .nav-link {
                justify-content: center;
                padding-left: 0.75rem;
                padding-right: 0.75rem;
            }

            .sidebar-hidden .nav-link svg {
                margin-right: 0;
            }

            .sidebar-hidden .user-info {
                display: none;
            }
        }
    </style>
</body>
</html>