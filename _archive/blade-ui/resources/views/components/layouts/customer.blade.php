@props([
    'showHeader' => true,
    'brand' => null,
])

@php
    if (!$brand) {
        try {
            $brand = \App\Models\BrandSetting::current();
        } catch (\Exception $e) {
            $brand = null;
        }
    }
@endphp

<!DOCTYPE html>
<html lang="id" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $brand?->business_name ?? config('app.name', 'Self Order') }}</title>

    @if($brand?->faviconUrl())
        <link rel="icon" href="{{ $brand->faviconUrl() }}" />
    @endif

    <style>
        :root {
            --primary-color: {{ $brand?->primary_color ?? '#0f172a' }};
            --secondary-color: {{ $brand?->secondary_color ?? '#334155' }};
            --accent-color: {{ $brand?->accent_color ?? '#10b981' }};
            --background-color: {{ $brand?->background_color ?? '#f8fafc' }};
            --button-radius: {{ (int)($brand?->button_radius ?? 18) }}px;
            --font-family: {{ $brand?->font_family ?? 'system-ui' }};
        }
    </style>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen text-slate-900" style="background: var(--background-color); font-family: var(--font-family)">
    <div class="mx-auto max-w-6xl px-4">
        @php
            $headerRightHtml = trim((string) ($headerRight ?? ''));
            $headerRightText = trim(strip_tags($headerRightHtml));
        @endphp

        @if($showHeader)
            <header class="sticky top-0 z-[120] py-4 bg-[color:var(--background-color)]/90 backdrop-blur supports-[backdrop-filter]:bg-[color:var(--background-color)]/70">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        @if($brand?->logoLightUrl())
                            <img src="{{ $brand->logoLightUrl() }}" alt="Logo" class="h-10 w-10 rounded-2xl object-cover border border-slate-200" />
                        @else
                            <div class="h-10 w-10 rounded-2xl text-white grid place-items-center font-bold" style="background: var(--primary-color)">SO</div>
                        @endif
                        <div>
                            <div class="text-lg font-semibold leading-tight">{{ $brand?->business_name ?? 'Self Order' }}</div>
                            <div class="text-xs" style="color: var(--secondary-color)">{{ $brand?->tagline ?? 'Cafe & Resto' }}</div>
                        </div>
                    </div>
                    <div class="flex items-center justify-end gap-3">
                        @if($headerRightText !== '')
                            <div class="text-right text-xs text-slate-500">
                                {!! $headerRightHtml !!}
                            </div>
                        @endif

                        <div x-data="{ open: false }" class="relative z-[130]">
                            @auth
                                @php
                                    $profileUrl = \Illuminate\Support\Facades\Route::has('profile.edit')
                                        ? route('profile.edit')
                                        : (\Illuminate\Support\Facades\Route::has('tenant.profile.edit') && request()->route('tenant')
                                            ? route('tenant.profile.edit', ['tenant' => request()->route('tenant')])
                                            : null);
                                @endphp
                                <button type="button" @click="open = !open" class="flex items-center gap-2 rounded-2xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold" style="border-radius: var(--button-radius)">
                                    <span class="h-7 w-7 rounded-xl grid place-items-center text-white font-black" style="background: var(--primary-color)">
                                        {{ strtoupper(substr(auth()->user()->name ?? 'M', 0, 1)) }}
                                    </span>
                                    <span>Member</span>
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-4 w-4 text-slate-500">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                                    </svg>
                                </button>

                                <div x-show="open" @click.outside="open = false" x-transition
                                    class="absolute right-0 z-[200] mt-2 w-44 rounded-2xl border border-slate-200 bg-white shadow-lg overflow-hidden"
                                    style="border-radius: var(--button-radius)" x-cloak>

                                    <a href="{{ route('member.dashboard') }}" class="block px-4 py-3 text-sm font-semibold text-slate-800 hover:bg-slate-50">Dashboard</a>
                                    @if($profileUrl)
                                        <a href="{{ $profileUrl }}" class="block px-4 py-3 text-sm font-semibold text-slate-800 hover:bg-slate-50">Profil</a>
                                    @endif
                                    <form method="POST" action="{{ route('logout') }}">
                                        @csrf
                                        <button type="submit" class="w-full text-left px-4 py-3 text-sm font-semibold text-rose-700 hover:bg-rose-50">Logout</button>
                                    </form>
                                </div>
                            @endauth
                        </div>
                    </div>
                </div>
            </header>
        @endif

        <main class="pb-28">
            {{ $slot }}
        </main>
    </div>

    @php
        $homeUrl = route('customer.home');
        $orderPageUrl = route('order.page', array_filter(['table' => request('table')]));
        $pesananPageUrl = route('customer.orders', array_filter(['table' => request('table')]));
        $promoUrl = route('customer.promo');

        $defaultActive = 'menu';
        if (request()->routeIs('customer.home')) {
            $defaultActive = 'home';
        } elseif (request()->routeIs('customer.promo')) {
            $defaultActive = 'promo';
        } elseif (request()->routeIs('customer.orders') || request()->routeIs('member.*')) {
            $defaultActive = 'pesanan';
        } elseif (request()->routeIs('order.*')) {
            $defaultActive = 'menu';
        }
    @endphp

    <div class="fixed inset-x-0 bottom-0 z-50">
        <div class="mx-auto max-w-6xl px-4 pb-[calc(env(safe-area-inset-bottom)+12px)] pt-2">
            <nav x-data="{ active: '{{ $defaultActive }}' }"
                class="grid grid-cols-4 gap-1 rounded-3xl border border-slate-200 bg-white/90 backdrop-blur shadow-lg p-2">

                <a href="{{ $homeUrl }}" @click="active='home'"
                    class="flex flex-col items-center justify-center gap-1 rounded-2xl px-2 py-2 text-[11px] font-semibold"
                    :class="active==='home' ? 'text-white' : 'text-slate-600 hover:bg-slate-100'"
                    :style="active==='home' ? 'background: var(--primary-color); border-radius: var(--button-radius)' : 'border-radius: var(--button-radius)'">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-6 w-6">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12l8.954-8.955a1.125 1.125 0 011.591 0L21.75 12M4.5 9.75V19.875c0 .621.504 1.125 1.125 1.125H9.75v-6.75A1.125 1.125 0 0110.875 13.125h2.25A1.125 1.125 0 0114.25 14.25V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75" />
                    </svg>
                    <span>Home</span>
                </a>

                <a href="{{ $orderPageUrl }}#menu" @click="active='menu'"
                    class="flex flex-col items-center justify-center gap-1 rounded-2xl px-2 py-2 text-[11px] font-semibold"
                    :class="active==='menu' ? 'text-white' : 'text-slate-600 hover:bg-slate-100'"
                    :style="active==='menu' ? 'background: var(--primary-color); border-radius: var(--button-radius)' : 'border-radius: var(--button-radius)'">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-6 w-6">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5M3.75 17.25h16.5" />
                    </svg>
                    <span>Menu</span>
                </a>

                <a href="{{ $pesananPageUrl }}" @click="active='pesanan'"
                    class="flex flex-col items-center justify-center gap-1 rounded-2xl px-2 py-2 text-[11px] font-semibold"
                    :class="active==='pesanan' ? 'text-white' : 'text-slate-600 hover:bg-slate-100'"
                    :style="active==='pesanan' ? 'background: var(--primary-color); border-radius: var(--button-radius)' : 'border-radius: var(--button-radius)'">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-6 w-6">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m-7.5 6h9A2.25 2.25 0 0018.75 19.5V6.108c0-.684-.316-1.33-.855-1.75L15.75 2.25H8.25A2.25 2.25 0 006 4.5v15A2.25 2.25 0 008.25 21z" />
                    </svg>
                    <span>Pesanan</span>
                </a>

                <a href="{{ $promoUrl }}" @click="active='promo'"
                    class="flex flex-col items-center justify-center gap-1 rounded-2xl px-2 py-2 text-[11px] font-semibold"
                    :class="active==='promo' ? 'text-white' : 'text-slate-600 hover:bg-slate-100'"
                    :style="active==='promo' ? 'background: var(--primary-color); border-radius: var(--button-radius)' : 'border-radius: var(--button-radius)'">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-6 w-6">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.568 3.16a1.125 1.125 0 011.59 0l1.704 1.705a1.125 1.125 0 00.796.33h2.407c.621 0 1.125.504 1.125 1.125v2.407c0 .298.118.585.33.796l1.705 1.704c.439.44.439 1.151 0 1.591l-1.705 1.704c-.212.211-.33.498-.33.796v2.407c0 .621-.504 1.125-1.125 1.125h-2.407a1.125 1.125 0 00-.796.33l-1.704 1.705c-.44.439-1.151.439-1.591 0l-1.704-1.705a1.125 1.125 0 00-.796-.33H6.75c-.621 0-1.125-.504-1.125-1.125v-2.407a1.125 1.125 0 00-.33-.796L3.59 12.91a1.125 1.125 0 010-1.591l1.705-1.704c.212-.211.33-.498.33-.796V6.412c0-.621.504-1.125 1.125-1.125h2.407c.298 0 .585-.118.796-.33L11.658 3.16z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 9.75h.008v.008H9V9.75zm6 4.5h.008v.008H15v-.008z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.75 14.25l4.5-4.5" />
                    </svg>
                    <span>Promo</span>
                </a>
            </nav>
        </div>
    </div>
</body>
</html>
