@php
    /** @var \App\Models\LoyaltySetting|null $setting */
@endphp

@extends('layouts.admin-sidebar')

@section('header')
    <div class="flex items-center justify-between">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Loyalty & Poin</h2>
    </div>
@endsection

@section('content')
    <div class="py-6">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            @if(session('success'))
                <div class="mb-4 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-emerald-800">
                    {{ session('success') }}
                </div>
            @endif

            @if($errors->any())
                <div class="mb-4 rounded-2xl border border-rose-200 bg-rose-50 p-4 text-rose-800">
                    <div class="font-semibold">Periksa input</div>
                    <ul class="list-disc pl-5 mt-2">
                        @foreach($errors->all() as $err)
                            <li>{{ $err }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="bg-white shadow-sm rounded-2xl border border-gray-200 p-5">
                <div class="text-sm text-gray-600">Atur cara poin member dihitung dari total belanja order.</div>

                <form class="mt-5 grid gap-4" method="POST" action="{{ route('admin.loyalty.update') }}">
                    @csrf
                    @method('PUT')

                    <label class="flex items-center gap-3">
                        <input type="checkbox" name="enabled" value="1" {{ old('enabled', (bool)($setting?->enabled ?? true)) ? 'checked' : '' }}>
                        <span class="text-sm font-semibold">Aktifkan poin</span>
                    </label>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="text-sm font-semibold">Metode hitung poin</label>
                            <select name="earn_method" class="mt-1 w-full rounded-xl border border-gray-300 px-4 py-3">
                                @php($m = old('earn_method', (string)($setting?->earn_method ?? 'per_1000')))
                                <option value="per_min_spend" {{ $m==='per_min_spend' ? 'selected' : '' }}>Kelipatan minimal belanja (rekomendasi)</option>
                                <option value="per_unit" {{ $m==='per_unit' ? 'selected' : '' }}>Per nominal tertentu (mis. Rp 10.000 = 1 poin)</option>
                                <option value="flat" {{ $m==='flat' ? 'selected' : '' }}>Flat poin per order (jika memenuhi minimum)</option>
                                <option value="per_1000" {{ $m==='per_1000' ? 'selected' : '' }}>Legacy: Poin per Rp 1.000</option>
                            </select>
                            <div class="mt-1 text-xs text-gray-500">
                                <div><span class="font-semibold">Kelipatan minimal belanja</span>: poin = floor(total/minimal) × poin_per_kelipatan</div>
                                <div><span class="font-semibold">Per nominal</span>: poin = floor(total/unit) × poin_per_unit</div>
                            </div>
                        </div>
                        <div>
                            <label class="text-sm font-semibold">Minimal belanja untuk dapat poin (Rp)</label>
                            <input type="number" min="0" name="min_spend_amount" value="{{ old('min_spend_amount', (int)($setting?->min_spend_amount ?? 0)) }}" class="mt-1 w-full rounded-xl border border-gray-300 px-4 py-3" />
                            <div class="mt-1 text-xs text-gray-500">Jika metode “Kelipatan minimal”, nilai ini juga jadi patokan kelipatan.</div>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="rounded-2xl border border-gray-200 p-4">
                            <div class="text-sm font-semibold">Kelipatan minimal belanja</div>
                            <label class="mt-3 block text-sm font-semibold">Poin per kelipatan minimal</label>
                            <input type="number" min="0" name="points_per_min_spend" value="{{ old('points_per_min_spend', (int)($setting?->points_per_min_spend ?? 0)) }}" class="mt-1 w-full rounded-xl border border-gray-300 px-4 py-3" />
                            <div class="mt-1 text-xs text-gray-500">Contoh: minimal 250.000 & poin 20 → 276.000 dapat 20, 500.000 dapat 40.</div>
                        </div>
                        <div class="rounded-2xl border border-gray-200 p-4">
                            <div class="text-sm font-semibold">Per nominal tertentu</div>
                            <div class="mt-3 grid grid-cols-2 gap-3">
                                <div>
                                    <label class="text-sm font-semibold">Unit (Rp)</label>
                                    <input type="number" min="1" name="points_unit_amount" value="{{ old('points_unit_amount', (int)($setting?->points_unit_amount ?? 1000)) }}" class="mt-1 w-full rounded-xl border border-gray-300 px-4 py-3" />
                                </div>
                                <div>
                                    <label class="text-sm font-semibold">Poin per unit</label>
                                    <input type="number" min="0" name="points_per_unit" value="{{ old('points_per_unit', (int)($setting?->points_per_unit ?? 1)) }}" class="mt-1 w-full rounded-xl border border-gray-300 px-4 py-3" />
                                </div>
                            </div>
                            <div class="mt-1 text-xs text-gray-500">Contoh pasaran: unit 10.000, poin 1 → setiap Rp 10.000 dapat 1 poin.</div>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="rounded-2xl border border-gray-200 p-4">
                            <div class="text-sm font-semibold">Flat poin per order</div>
                            <label class="mt-3 block text-sm font-semibold">Poin flat</label>
                            <input type="number" min="0" name="flat_points_per_order" value="{{ old('flat_points_per_order', (int)($setting?->flat_points_per_order ?? 0)) }}" class="mt-1 w-full rounded-xl border border-gray-300 px-4 py-3" />
                            <div class="mt-1 text-xs text-gray-500">Jika total ≥ minimal belanja, dapat poin tetap (tanpa menghitung nominal).</div>
                        </div>
                        <div class="rounded-2xl border border-gray-200 p-4">
                            <div class="text-sm font-semibold">Legacy</div>
                            <label class="mt-3 block text-sm font-semibold">Poin per Rp 1.000</label>
                            <input type="number" min="0" name="points_per_1000" value="{{ old('points_per_1000', (int)($setting?->points_per_1000 ?? 1)) }}" class="mt-1 w-full rounded-xl border border-gray-300 px-4 py-3" />
                            <div class="mt-1 text-xs text-gray-500">Mode lama. Contoh: 2 → Rp 276.000 dapat 552 poin.</div>
                        </div>
                    </div>

                    <div>
                        <label class="text-sm font-semibold">Batas maksimal poin per order (opsional)</label>
                        <input type="number" min="1" name="max_points_per_order" value="{{ old('max_points_per_order', $setting?->max_points_per_order) }}" class="mt-1 w-full rounded-xl border border-gray-300 px-4 py-3" />
                        <div class="mt-1 text-xs text-gray-500">Kosongkan jika tidak dibatasi.</div>
                    </div>

                    <div class="rounded-2xl border border-gray-200 p-5">
                        <div class="text-sm text-gray-600">Atur penukaran poin menjadi diskon saat checkout (opsional).</div>

                        <div class="mt-4 grid gap-4">
                            <label class="flex items-center gap-3">
                                <input type="checkbox" name="redeem_enabled" value="1" {{ old('redeem_enabled', (bool)($setting?->redeem_enabled ?? false)) ? 'checked' : '' }}>
                                <span class="text-sm font-semibold">Aktifkan penukaran poin → diskon</span>
                            </label>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="text-sm font-semibold">Nilai tukar (Rp per 1 poin)</label>
                                    <input type="number" min="0" name="redeem_rp_per_point" value="{{ old('redeem_rp_per_point', (int)($setting?->redeem_rp_per_point ?? 0)) }}" class="mt-1 w-full rounded-xl border border-gray-300 px-4 py-3" />
                                    <div class="mt-1 text-xs text-gray-500">Contoh: 1 poin = Rp 100 → pakai 50 poin diskon Rp 5.000.</div>
                                </div>
                                <div>
                                    <label class="text-sm font-semibold">Minimal belanja untuk bisa pakai poin (Rp)</label>
                                    <input type="number" min="0" name="redeem_min_spend_amount" value="{{ old('redeem_min_spend_amount', (int)($setting?->redeem_min_spend_amount ?? 0)) }}" class="mt-1 w-full rounded-xl border border-gray-300 px-4 py-3" />
                                </div>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="text-sm font-semibold">Maksimal poin dipakai per order (opsional)</label>
                                    <input type="number" min="1" name="redeem_max_points_per_order" value="{{ old('redeem_max_points_per_order', $setting?->redeem_max_points_per_order) }}" class="mt-1 w-full rounded-xl border border-gray-300 px-4 py-3" />
                                    <div class="mt-1 text-xs text-gray-500">Kosongkan jika tidak dibatasi.</div>
                                </div>
                                <div>
                                    <label class="text-sm font-semibold">Maksimal diskon (Rp) per order (opsional)</label>
                                    <input type="number" min="1" name="redeem_max_discount_rp" value="{{ old('redeem_max_discount_rp', $setting?->redeem_max_discount_rp) }}" class="mt-1 w-full rounded-xl border border-gray-300 px-4 py-3" />
                                    <div class="mt-1 text-xs text-gray-500">Diskon juga otomatis tidak bisa melebihi total order.</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center justify-end gap-2">
                        <button type="submit" class="rounded-xl bg-gray-900 text-white px-5 py-3 font-semibold">Simpan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

