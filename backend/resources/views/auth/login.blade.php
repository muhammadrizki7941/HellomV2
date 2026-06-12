@php
    $currentTenant = request()->route('tenant');
    $businessName = $brand?->business_name ?? ($brand?->app_name ?? config('app.name', 'Hellom'));
    $logoUrl = method_exists($brand, 'logoUrl')
        ? $brand->logoUrl()
        : (method_exists($brand, 'logoLightUrl') ? $brand->logoLightUrl() : null);
    $pageTitle = ($brand?->login_title ?: 'Masuk ke Hellom') . ' | ' . $businessName;
    $metaDescription = $brand?->login_subtitle ?: 'Masuk ke akun Hellom untuk mengakses dashboard dan operasional bisnis Anda.';
    $canonicalUrl = url('/login');
    $ogImage = $logoUrl ?: url('/hellom/assets/logo-hellom.png');
    $isMemberLogin = request()->routeIs('customer.member.login');
    $loginAction = $isMemberLogin
        ? route('customer.member.login.submit')
        : ($currentTenant ? route('login', ['tenant' => $currentTenant]) : route('auth.login.submit'));
    $registerUrl = $isMemberLogin
        ? route('customer.member.register')
        : ($currentTenant ? route('register', ['tenant' => $currentTenant]) : route('register'));
@endphp

@extends('layouts.public')

@section('content')
    <main class="section-shell mx-auto grid min-h-screen max-w-7xl gap-8 px-4 py-10 sm:px-6 lg:grid-cols-[1.05fr_0.95fr] lg:px-8 lg:py-14">
        <section class="surface-card hidden rounded-[2rem] p-8 lg:flex lg:flex-col lg:justify-between">
            <div>
                <div class="inline-flex items-center gap-2 rounded-full border border-accent-soft bg-accent-soft px-4 py-2 text-xs font-bold uppercase tracking-[0.18em] text-accent">
                    <span class="h-2 w-2 rounded-full bg-[var(--brand-accent)]"></span>
                    {{ $isMemberLogin ? 'Member Access' : 'Admin Access' }}
                </div>
                <h1 class="mt-6 font-display text-5xl font-extrabold leading-none tracking-[-0.05em] text-white">
                    {{ $brand?->login_title ?: 'Masuk ke akun Hellom' }}
                </h1>
                <p class="mt-5 max-w-xl text-lg leading-8 text-muted">
                    {{ $brand?->login_subtitle ?: 'Lanjutkan operasional bisnis Anda dengan tampilan yang lebih rapi dan alur kerja yang tetap sama.' }}
                </p>
            </div>
            <div class="space-y-4">
                <div class="rounded-[1.5rem] border border-white/10 bg-white/5 p-5">
                    <p class="text-sm font-semibold text-white">Yang tetap aman</p>
                    <p class="mt-2 text-sm leading-7 text-muted">Flow login, middleware, redirect setelah autentikasi, dan dashboard aplikasi tetap memakai backend yang sama.</p>
                </div>
                <div class="rounded-[1.5rem] border border-white/10 bg-white/5 p-5">
                    <p class="text-sm font-semibold text-white">Akses cepat</p>
                    <div class="mt-3 space-y-2 text-sm text-muted">
                        <a href="/cashier/login" class="block transition hover:text-white">Login kasir</a>
                        <a href="/member/login" class="block transition hover:text-white">Login member</a>
                        <a href="/hellom/login" class="block transition hover:text-white">Login SPA Hellom</a>
                    </div>
                </div>
            </div>
        </section>

        <section class="surface-card rounded-[2rem] p-6 sm:p-8 lg:p-10">
            <div class="mx-auto max-w-md">
                <a href="{{ route('landing') }}" class="mb-8 inline-flex items-center gap-3">
                    @if($logoUrl)
                        <img src="{{ $logoUrl }}" alt="{{ $businessName }}" class="h-11 w-auto rounded-xl">
                    @else
                        <span class="flex h-11 w-11 items-center justify-center rounded-2xl bg-accent-soft font-display text-sm font-extrabold text-accent">HM</span>
                    @endif
                    <div>
                        <p class="font-display text-lg font-extrabold text-white">{{ $businessName }}</p>
                        <p class="text-xs text-muted">Secure login portal</p>
                    </div>
                </a>

                <h2 class="font-display text-3xl font-extrabold tracking-[-0.04em] text-white">
                    {{ $isMemberLogin ? 'Masuk Member' : ($brand?->login_title ?: 'Masuk') }}
                </h2>
                <p class="mt-3 text-sm leading-7 text-muted">{{ $metaDescription }}</p>

                @if($errors->any())
                    <div class="mt-6 rounded-2xl border border-red-500/20 bg-red-500/10 px-4 py-3 text-sm text-red-200">
                        Login gagal. Periksa email dan password Anda.
                    </div>
                @endif

                <form method="POST" action="{{ $loginAction }}" class="mt-8 space-y-5">
                    @csrf
                    <div>
                        <label for="email" class="mb-2 block text-sm font-semibold text-white">Email</label>
                        <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus class="w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-white placeholder:text-white/35 focus:border-[var(--brand-accent)] focus:outline-none" placeholder="nama@email.com">
                    </div>
                    <div>
                        <label for="password" class="mb-2 block text-sm font-semibold text-white">Password</label>
                        <input id="password" name="password" type="password" required class="w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-white placeholder:text-white/35 focus:border-[var(--brand-accent)] focus:outline-none" placeholder="Masukkan password">
                    </div>
                    <div class="flex items-center justify-between gap-4 text-sm">
                        <label class="inline-flex items-center gap-2 text-muted">
                            <input type="checkbox" name="remember" class="rounded border-white/20 bg-white/10">
                            <span>Ingat saya</span>
                        </label>
                        @if (Route::has('password.request'))
                            <a class="font-semibold text-white hover:text-accent" href="{{ route('password.request') }}">Lupa password?</a>
                        @endif
                    </div>
                    <button type="submit" class="w-full rounded-full bg-[var(--brand-accent)] px-5 py-4 text-sm font-bold text-black transition hover:brightness-110">
                        {{ $isMemberLogin ? 'Masuk ke Dashboard Member' : 'Masuk ke Dashboard' }}
                    </button>
                </form>

                <p class="mt-6 text-sm text-muted">
                    Belum punya akun?
                    <a href="{{ $registerUrl }}" class="font-semibold text-white hover:text-accent">Daftar sekarang</a>
                </p>
            </div>
        </section>
    </main>
@endsection
