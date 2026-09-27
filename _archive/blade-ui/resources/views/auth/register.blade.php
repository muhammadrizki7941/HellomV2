@php
    /** @var array<string,mixed>|null $reservationIntent */
    $businessName = $brand?->business_name ?? ($brand?->app_name ?? config('app.name', 'Hellom'));
    $logoUrl = method_exists($brand, 'logoUrl')
        ? $brand->logoUrl()
        : (method_exists($brand, 'logoLightUrl') ? $brand->logoLightUrl() : null);
    $pageTitle = ($brand?->register_title ?: 'Daftar Hellom') . ' | ' . $businessName;
    $metaDescription = $brand?->register_subtitle ?: 'Buat akun Hellom untuk mulai mengelola bisnis Anda.';
    $canonicalUrl = url('/register');
    $ogImage = $logoUrl ?: url('/hellom/assets/logo-hellom.png');
    $isMemberRegister = request()->routeIs('customer.member.register');
    $submitUrl = $isMemberRegister ? route('customer.member.register.submit') : route('auth.register.submit');
    $loginUrl = $isMemberRegister ? route('customer.member.login') : route('login');
@endphp

@extends('layouts.public')

@section('content')
    <main class="section-shell mx-auto grid min-h-screen max-w-7xl gap-8 px-4 py-10 sm:px-6 lg:grid-cols-[0.95fr_1.05fr] lg:px-8 lg:py-14">
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
                        <p class="text-xs text-muted">Registration portal</p>
                    </div>
                </a>

                <h1 class="font-display text-3xl font-extrabold tracking-[-0.04em] text-white">{{ $brand?->register_title ?: 'Buat akun Hellom' }}</h1>
                <p class="mt-3 text-sm leading-7 text-muted">{{ $metaDescription }}</p>

                <form method="POST" action="{{ $submitUrl }}" class="mt-8 space-y-5">
                    @csrf
                    <div>
                        <label for="name" class="mb-2 block text-sm font-semibold text-white">Nama lengkap</label>
                        <input id="name" type="text" name="name" value="{{ old('name', $reservationIntent['customer_name'] ?? '') }}" required autofocus class="w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-white placeholder:text-white/35 focus:border-[var(--brand-accent)] focus:outline-none" placeholder="Budi Santoso">
                        @error('name')<div class="mt-1 text-xs text-red-200">{{ $message }}</div>@enderror
                    </div>
                    <div>
                        <label for="email" class="mb-2 block text-sm font-semibold text-white">Email</label>
                        <input id="email" type="email" name="email" value="{{ old('email', $reservationIntent['customer_email'] ?? '') }}" required class="w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-white placeholder:text-white/35 focus:border-[var(--brand-accent)] focus:outline-none" placeholder="nama@email.com">
                        @error('email')<div class="mt-1 text-xs text-red-200">{{ $message }}</div>@enderror
                    </div>
                    <div>
                        <label for="phone" class="mb-2 block text-sm font-semibold text-white">No. HP / WhatsApp</label>
                        <input id="phone" type="text" name="phone" value="{{ old('phone', $reservationIntent['customer_phone'] ?? '') }}" required class="w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-white placeholder:text-white/35 focus:border-[var(--brand-accent)] focus:outline-none" placeholder="08xxxxxxxxxx">
                        @error('phone')<div class="mt-1 text-xs text-red-200">{{ $message }}</div>@enderror
                    </div>
                    <div>
                        <label for="password" class="mb-2 block text-sm font-semibold text-white">Password</label>
                        <input id="password" type="password" name="password" required class="w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-white placeholder:text-white/35 focus:border-[var(--brand-accent)] focus:outline-none" placeholder="Minimal 8 karakter">
                        @error('password')<div class="mt-1 text-xs text-red-200">{{ $message }}</div>@enderror
                    </div>
                    <div>
                        <label for="password_confirmation" class="mb-2 block text-sm font-semibold text-white">Konfirmasi password</label>
                        <input id="password_confirmation" type="password" name="password_confirmation" required class="w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-white placeholder:text-white/35 focus:border-[var(--brand-accent)] focus:outline-none" placeholder="Ulangi password">
                    </div>
                    <button type="submit" class="w-full rounded-full bg-[var(--brand-accent)] px-5 py-4 text-sm font-bold text-black transition hover:brightness-110">Daftar akun</button>
                </form>

                <p class="mt-6 text-sm text-muted">
                    Sudah punya akun?
                    <a href="{{ $loginUrl }}" class="font-semibold text-white hover:text-accent">Masuk di sini</a>
                </p>
            </div>
        </section>

        <section class="surface-card hidden rounded-[2rem] p-8 lg:flex lg:flex-col lg:justify-between">
            <div>
                <div class="inline-flex items-center gap-2 rounded-full border border-accent-soft bg-accent-soft px-4 py-2 text-xs font-bold uppercase tracking-[0.18em] text-accent">
                    <span class="h-2 w-2 rounded-full bg-[var(--brand-accent)]"></span>
                    Start fast
                </div>
                <h2 class="mt-6 font-display text-5xl font-extrabold leading-none tracking-[-0.05em] text-white">Masuk ke ekosistem yang menautkan landing, POS, dan promo dalam satu alur.</h2>
                <p class="mt-5 max-w-xl text-lg leading-8 text-muted">{{ $brand?->tagline ?: 'Daftar sekarang lalu lanjut atur branding, banner, dan operasional bisnis Anda tanpa setup yang terasa berat.' }}</p>
            </div>
            <div class="space-y-4">
                <div class="rounded-[1.5rem] border border-white/10 bg-white/5 p-5 text-sm leading-7 text-muted">
                    Semua halaman publik baru dirender dengan Blade, tetapi dashboard aplikasi dan API tetap menggunakan stack yang sudah ada.
                </div>
                <div class="rounded-[1.5rem] border border-white/10 bg-white/5 p-5 text-sm leading-7 text-muted">
                    Setelah registrasi selesai, redirect dan alur autentikasi tetap ditangani controller Laravel yang sama.
                </div>
            </div>
        </section>
    </main>
@endsection
