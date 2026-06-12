<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ $brand?->business_name ?? config('app.name', 'Laravel') }}</title>

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
    <body class="font-sans antialiased" style="font-family: var(--font-family)">
        <div class="min-h-screen bg-gray-100" style="background: var(--background-color)">
            @include('layouts.navigation')

            <!-- Page Heading -->
            <header class="bg-white shadow">
                <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                    @yield('header')
                </div>
            </header>

            <!-- Page Content -->
            <main>
                {{ $slot }}
            </main>
        </div>
    </body>
</html>
