@php
    /** @var \App\Models\User $user */
    /** @var \Illuminate\Support\Collection<int,\App\Models\Order> $orders */
    /** @var \Illuminate\Support\Collection<int,\App\Models\Reservation> $reservations */
    /** @var \Illuminate\Support\Collection<int,\App\Models\MemberPromotion> $promotions */
@endphp

<x-customer-layout>
    <x-slot name="headerRight">
        <a href="{{ route('customer.home') }}" class="text-xs font-semibold text-slate-600">Home</a>
    </x-slot>

    <div class="mt-2 grid gap-4">
        <div class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm" style="border-radius: var(--button-radius)">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <div class="text-sm text-slate-500">Member</div>
                    <div class="text-lg font-extrabold">{{ $user->name }}</div>
                    <div class="text-xs text-slate-600">{{ $user->email }}</div>
                    @if($user->phone)
                        <div class="text-xs text-slate-600">{{ $user->phone }}</div>
                    @endif
                </div>
                <div class="text-right">
                    <div class="text-sm text-slate-500">Poin</div>
                    <div class="text-2xl font-extrabold" style="color: var(--primary-color)">{{ number_format((int) $user->points_balance, 0, ',', '.') }}</div>
                </div>
            </div>

            <div class="mt-4 flex flex-wrap gap-2">
                @php
                    $profileUrl = \Illuminate\Support\Facades\Route::has('profile.edit')
                        ? route('profile.edit')
                        : (\Illuminate\Support\Facades\Route::has('tenant.profile.edit') && request()->route('tenant')
                            ? route('tenant.profile.edit', ['tenant' => request()->route('tenant')])
                            : null);
                @endphp
                @if($profileUrl)
                    <a href="{{ $profileUrl }}" class="rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold" style="border-radius: var(--button-radius)">Edit Profil</a>
                @endif
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold" style="border-radius: var(--button-radius)">Logout</button>
                </form>
            </div>
        </div>

        <div class="grid md:grid-cols-2 gap-4">
            <div class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm" style="border-radius: var(--button-radius)">
                <div class="flex items-center justify-between">
                    <div class="text-sm font-semibold">Reservasi</div>
                    <a class="text-xs font-semibold text-slate-600" href="{{ route('reservation.index') }}">Tambah</a>
                </div>

                <div class="mt-3 grid gap-2">
                    @forelse($reservations as $r)
                        <div class="rounded-2xl border border-slate-200 p-4" style="border-radius: var(--button-radius)">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <div class="text-sm font-semibold">{{ $r->space_name }}</div>
                                    <div class="text-xs text-slate-600">{{ $r->scheduled_at?->format('d M Y, H:i') }} · {{ (int) $r->duration_minutes }} menit</div>
                                </div>
                                <div class="text-xs font-bold uppercase" style="color: var(--secondary-color)">{{ $r->status }}</div>
                            </div>
                            <div class="mt-2 text-sm font-semibold">Rp {{ number_format((int) $r->total_price, 0, ',', '.') }}</div>
                        </div>
                    @empty
                        <div class="text-sm text-slate-600">Belum ada reservasi.</div>
                    @endforelse
                </div>
            </div>

            <div class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm" style="border-radius: var(--button-radius)">
                <div class="text-sm font-semibold">Pesanan</div>
                <div class="mt-3 grid gap-2">
                    @forelse($orders as $o)
                        <div class="rounded-2xl border border-slate-200 p-4" style="border-radius: var(--button-radius)">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <div class="text-sm font-semibold">{{ $o->order_number }}</div>
                                    <div class="text-xs text-slate-600">{{ $o->created_at?->format('d M Y, H:i') }}</div>
                                </div>
                                <div class="text-xs font-bold uppercase" style="color: var(--secondary-color)">{{ $o->status }}</div>
                            </div>
                            <div class="mt-2 text-sm font-semibold">Rp {{ number_format((int) $o->total_amount, 0, ',', '.') }}</div>
                        </div>
                    @empty
                        <div class="text-sm text-slate-600">Belum ada pesanan yang terhubung ke akun ini.</div>
                    @endforelse
                </div>

                <div class="mt-4 rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700" style="border-radius: var(--button-radius)">
                    Tips: Untuk order dari QR meja, login dulu sebelum checkout agar pesanan tersimpan ke akun.
                </div>
            </div>
        </div>

        <div class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm" style="border-radius: var(--button-radius)">
            <div class="text-sm font-semibold">Promo</div>
            <div class="mt-3 grid gap-2">
                @forelse($promotions as $p)
                    <div class="rounded-2xl border border-slate-200 p-4" style="border-radius: var(--button-radius)">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <div class="text-sm font-semibold">{{ $p->title }}</div>
                                @if($p->description)
                                    <div class="mt-1 text-xs text-slate-600">{{ $p->description }}</div>
                                @endif
                                @if($p->expires_at)
                                    <div class="mt-1 text-xs text-slate-500">Expired: {{ $p->expires_at->format('d M Y H:i') }}</div>
                                @endif
                            </div>
                            <div class="text-xs font-bold uppercase" style="color: var(--secondary-color)">
                                {{ $p->is_redeemed ? 'redeemed' : 'active' }}
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="text-sm text-slate-600">Belum ada promo untuk akun ini.</div>
                @endforelse
            </div>
        </div>
    </div>
</x-customer-layout>
