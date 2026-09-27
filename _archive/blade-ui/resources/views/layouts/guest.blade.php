<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        @php
            // Resolve active brand: prefer tenant-specific, otherwise global (super-admin)
            $brand = $brand ?? \App\Models\BrandSetting::current() ?? \App\Models\BrandSetting::global();
        @endphp
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

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans text-white antialiased bg-[#050505]" style="font-family: var(--font-family)">
        <div class="min-h-screen flex flex-col sm:justify-center items-center py-10 px-4">
            @php
                $tenantParam = request()->route('tenant') ?? (app()->bound(\App\Services\Tenancy\TenantContext::class) ? app(\App\Services\Tenancy\TenantContext::class)->id : null);
                try {
                    $homeUrl = $tenantParam ? route('customer.home', ['tenant' => $tenantParam]) : url('/');
                } catch (\Exception $e) {
                    $homeUrl = url('/');
                }
            @endphp

            <div class="flex items-center gap-4 mb-6">
                <a href="{{ $homeUrl }}" class="flex items-center gap-4">
                    <div class="rounded-full bg-white/5 p-2 shadow-sm">
                        <x-application-logo class="w-16 h-16" />
                    </div>
                    <div class="text-left">
                        <div class="text-lg font-extrabold text-white">{{ $brand?->business_name ?? 'Self Order' }}</div>
                        <div class="text-xs text-slate-400">{{ $brand?->tagline ?? 'Cafe & Resto' }}</div>
                    </div>
                </a>
            </div>

            <div class="w-full sm:max-w-md mt-2 px-6 py-6 bg-[#0b0b0b] shadow-lg overflow-hidden text-white" style="border-radius: calc(var(--button-radius) + 4px)">
                {{ $slot }}
            </div>
        </div>
    </body>
</html>
