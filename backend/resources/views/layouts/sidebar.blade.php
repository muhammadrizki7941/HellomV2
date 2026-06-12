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
<body class="font-sans antialiased">
    <div class="min-h-screen flex bg-gray-100">
        @include('admin.cashier._sidebar')

        <div class="flex-1">
            <div class="w-full px-4 sm:px-6 lg:px-8 py-6">
                @yield('header')
            </div>

            <main class="w-full px-4 sm:px-6 lg:px-8">
                @yield('content')
            </main>
        </div>
    </div>
</body>
</html>
