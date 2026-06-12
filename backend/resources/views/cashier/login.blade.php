@php
    $bp = $basePath ?? (isset($tenant) ? '/t/'.$tenant->slug : '');
    $action = $bp === '' ? '/cashier/login' : ($bp.'/cashier/login');
@endphp

<x-layouts.cashier
    :cashierHomeUrl="($bp === '' ? '/cashier' : $bp.'/cashier')"
    :cashierLogoutUrl="($bp === '' ? '/cashier/logout' : $bp.'/cashier/logout')">

    <div class="min-h-screen bg-slate-100 py-10 px-4">
        <div class="mx-auto max-w-5xl grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Left panel: Info (hidden on mobile) -->
            <div class="hidden lg:flex rounded-3xl border border-slate-200 bg-white p-8 shadow-sm flex-col justify-between">
                <div>
                    <div class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs font-medium text-slate-600">
                        <span class="h-2 w-2 rounded-full bg-orange-500"></span>
                        Kasir / Petugas
                    </div>
                    <h1 class="mt-5 text-3xl font-semibold text-slate-900 leading-tight">
                        Layar Kasir
                        <br />
                        {{ $tenant->name }}
                    </h1>
                    <p class="mt-4 text-sm leading-relaxed text-slate-600 max-w-md">
                        Login sebagai kasir untuk mencatat pesanan, memproses pembayaran, dan mengelola transaksi harian.
                    </p>
                </div>
                <div class="space-y-2 text-sm text-slate-700">
                    <div class="flex items-start gap-2">
                        <span class="text-orange-600 font-bold">✓</span>
                        <span>Pencatatan pesanan dari meja/online</span>
                    </div>
                    <div class="flex items-start gap-2">
                        <span class="text-orange-600 font-bold">✓</span>
                        <span>Verifikasi dan pembayaran pesanan</span>
                    </div>
                    <div class="flex items-start gap-2">
                        <span class="text-orange-600 font-bold">✓</span>
                        <span>Pengelolaan status pesanan real-time</span>
                    </div>
                    <div class="flex items-start gap-2">
                        <span class="text-orange-600 font-bold">✓</span>
                        <span>Laporan harian transaksi</span>
                    </div>
                </div>
            </div>

            <!-- Right panel: Form -->
            <div class="rounded-3xl border border-slate-200 bg-white p-6 md:p-8 shadow-sm">
                <div class="mb-6 text-center">
                    <h2 class="text-2xl font-semibold text-slate-900">Login Kasir</h2>
                    <p class="mt-2 text-sm text-slate-600">Masukkan email dan password akun kasir Anda</p>
                    <div class="mt-3 text-xs text-slate-500 rounded-lg bg-slate-50 px-2 py-1.5">
                        Tempat: <strong>{{ $tenant->name }}</strong>
                    </div>
                </div>

                @if($errors->any())
                    <div class="mb-4 rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">
                        Login gagal. Periksa email dan password Anda.
                    </div>
                @endif

                <form method="POST" action="{{ $action }}" class="space-y-4">
                    @csrf

                    <div>
                        <label for="email" class="mb-1 block text-sm font-medium text-slate-700">Email Kasir</label>
                        <input
                            id="email"
                            name="email"
                            type="email"
                            value="{{ old('email') }}"
                            required
                            class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-slate-900 placeholder:text-slate-400 focus:border-slate-500 focus:outline-none"
                            placeholder="kasir@bisnis.com"
                        />
                        @error('email')<div class="text-xs text-rose-600 mt-1">{{ $message }}</div>@enderror
                    </div>

                    <div>
                        <label for="password" class="mb-1 block text-sm font-medium text-slate-700">Password</label>
                        <input
                            id="password"
                            name="password"
                            type="password"
                            required
                            class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-slate-900 placeholder:text-slate-400 focus:border-slate-500 focus:outline-none"
                            placeholder="Masukkan password Anda"
                        />
                        @error('password')<div class="text-xs text-rose-600 mt-1">{{ $message }}</div>@enderror
                    </div>

                    <button
                        type="submit"
                        class="w-full rounded-xl bg-orange-600 px-4 py-3 text-sm font-semibold text-white hover:bg-orange-700 transition-colors"
                    >
                        Login ke Layar Kasir
                    </button>
                </form>

                <div class="mt-6 rounded-2xl border border-slate-200 bg-slate-50 p-4 lg:hidden">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Akses Lain</p>
                    <div class="mt-2 grid gap-2 text-sm">
                        <a href="/login" class="text-slate-700 hover:text-slate-900 font-medium">→ Login Admin POS</a>
                        <a href="/member/login" class="text-slate-700 hover:text-slate-900 font-medium">→ Login Member</a>
                    </div>
                </div>

                <p class="mt-5 text-center text-sm text-slate-600">
                    <a href="/login" class="text-slate-900 hover:underline font-medium">Login sebagai admin</a>
                    atau 
                    <a href="/member/login" class="text-slate-900 hover:underline font-medium">member</a>
                </p>

                <div class="mt-4 text-xs text-slate-500 text-center">Demo: cashier@alpha.test / cashier</div>
            </div>
        </div>
    </div>
</x-layouts.cashier>
