@extends('layouts.admin-sidebar')

@section('header')
    <div class="flex items-center justify-between mb-6">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Promo Member</h2>
        <a href="{{ route('admin.member-promotions.create') }}" class="rounded-xl bg-gray-900 text-white px-4 py-2 font-semibold">Tambah</a>
    </div>
@endsection

@section('content')
            @if(session('success'))
                <div class="mb-4 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-emerald-800">
                    {{ session('success') }}
                </div>
            @endif

            <div class="bg-white shadow-sm rounded-2xl border border-gray-200 overflow-hidden">
                <table class="min-w-full">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="text-left text-xs font-semibold text-gray-600 px-4 py-3">Member</th>
                            <th class="text-left text-xs font-semibold text-gray-600 px-4 py-3">Promo</th>
                            <th class="text-left text-xs font-semibold text-gray-600 px-4 py-3">Expired</th>
                            <th class="text-left text-xs font-semibold text-gray-600 px-4 py-3">Status</th>
                            <th class="text-right text-xs font-semibold text-gray-600 px-4 py-3">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        @forelse($promos as $p)
                            <tr>
                                <td class="px-4 py-3">
                                    <div class="font-semibold">{{ $p->user?->name }}</div>
                                    <div class="text-xs text-gray-500">{{ $p->user?->email }}</div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="font-semibold">{{ $p->title }}</div>
                                    @if($p->description)
                                        <div class="text-xs text-gray-500">{{ $p->description }}</div>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-700">
                                    {{ $p->expires_at?->format('d M Y H:i') ?? '-' }}
                                </td>
                                <td class="px-4 py-3 text-sm">
                                    @if($p->is_redeemed)
                                        <span class="rounded-full bg-slate-100 text-slate-700 px-3 py-1 text-xs font-semibold">Redeemed</span>
                                    @else
                                        <span class="rounded-full bg-emerald-100 text-emerald-700 px-3 py-1 text-xs font-semibold">Active</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex justify-end gap-2">
                                        @if(!$p->is_redeemed)
                                            <form method="POST" action="{{ route('admin.member-promotions.redeem', $p) }}">
                                                @csrf
                                                @method('PATCH')
                                                <button class="rounded-xl border border-gray-300 px-3 py-2 text-sm font-semibold">Redeem</button>
                                            </form>
                                        @endif
                                        <form method="POST" action="{{ route('admin.member-promotions.destroy', $p) }}" onsubmit="return confirm('Hapus promo ini?')">
                                            @csrf
                                            @method('DELETE')
                                            <button class="rounded-xl border border-rose-300 text-rose-700 px-3 py-2 text-sm font-semibold">Hapus</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-6 text-center text-sm text-gray-500">Belum ada promo.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection 
