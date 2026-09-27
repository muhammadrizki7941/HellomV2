@extends('layouts.admin-sidebar')

@section('header')
    <div class="flex items-center justify-between gap-4 mb-6">
        <div>
            <div class="text-xs text-gray-500">Settings</div>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Pembayaran</h2>
        </div>
        <a href="{{ route('admin.orders.index') }}" class="px-4 py-2 rounded-xl border bg-white text-sm font-semibold">Kembali</a>
    </div>
@endsection

@section('content')
            @if (session('success'))
                <div class="mb-4 rounded-2xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800">
                    {{ session('success') }}
                </div>
            @endif

            @if($errors->any())
                <div class="mb-4 rounded-2xl border border-red-200 bg-red-50 p-3 text-sm text-red-800">
                    <div class="font-semibold">Ada error:</div>
                    <ul class="mt-1 list-disc ms-5">
                        @foreach($errors->all() as $e)
                            <li>{{ $e }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-2xl">
                <form method="POST" action="{{ route('admin.payments.update') }}" enctype="multipart/form-data" class="p-6 space-y-6">
                    @csrf
                    @method('PUT')

                    <div class="grid sm:grid-cols-2 gap-4">
                        <div class="rounded-2xl border border-gray-200 p-4">
                            <div class="font-semibold">Metode Aktif</div>
                            <div class="mt-3 grid gap-2 text-sm">
                                <label class="flex items-center gap-2">
                                    <input type="hidden" name="cash_enabled" value="0" />
                                    <input type="checkbox" name="cash_enabled" value="1" class="rounded" @checked(old('cash_enabled', $setting?->cash_enabled) ? true : false) />
                                    <span>Tunai (Cash)</span>
                                </label>
                                <label class="flex items-center gap-2">
                                    <input type="hidden" name="qris_static_enabled" value="0" />
                                    <input type="checkbox" name="qris_static_enabled" value="1" class="rounded" @checked(old('qris_static_enabled', $setting?->qris_static_enabled) ? true : false) />
                                    <span>QRIS Statis</span>
                                </label>
                                <label class="flex items-center gap-2">
                                    <input type="hidden" name="qris_dynamic_enabled" value="0" />
                                    <input type="checkbox" name="qris_dynamic_enabled" value="1" class="rounded" @checked(old('qris_dynamic_enabled', $setting?->qris_dynamic_enabled) ? true : false) />
                                    <span>QRIS Dinamis (API)</span>
                                </label>
                            </div>
                            <div class="mt-3 text-xs text-gray-500">
                                QRIS Dinamis butuh provider (mis. Midtrans). Rahasia API tetap di <code>.env</code>.
                            </div>
                        </div>

                        <div class="rounded-2xl border border-gray-200 p-4">
                            <div class="font-semibold">Default</div>
                            <div class="mt-3">
                                <label class="text-xs font-semibold text-gray-600">Metode default</label>
                                <select name="default_method" class="mt-1 w-full rounded-xl border-gray-300 text-sm">
                                    <option value="cash" @selected(old('default_method', $setting?->default_method) === 'cash')>Cash</option>
                                    <option value="qris_static" @selected(old('default_method', $setting?->default_method) === 'qris_static')>QRIS Statis</option>
                                    <option value="qris_dynamic" @selected(old('default_method', $setting?->default_method) === 'qris_dynamic')>QRIS Dinamis</option>
                                </select>
                            </div>
                            <div class="mt-4 space-y-2 text-sm">
                                <label class="flex items-start gap-2">
                                    <input type="hidden" name="auto_complete_when_paid" value="0" />
                                    <input type="checkbox" name="auto_complete_when_paid" value="1" class="mt-1 rounded" @checked(old('auto_complete_when_paid', $setting?->auto_complete_when_paid) ? true : false) />
                                    <div>
                                        <div class="font-semibold">Otomatis complete jika Paid</div>
                                        <div class="text-xs text-gray-500">Jika aktif, order kasir berstatus complete segera setelah payment status = paid.</div>
                                    </div>
                                </label>
                                <label class="flex items-start gap-2">
                                    <input type="hidden" name="require_paid_before_complete" value="0" />
                                    <input type="checkbox" name="require_paid_before_complete" value="1" class="mt-1 rounded" @checked(old('require_paid_before_complete', $setting?->require_paid_before_complete) ? true : false) />
                                    <div>
                                        <div class="font-semibold">Wajib Paid sebelum Complete</div>
                                        <div class="text-xs text-gray-500">Jika aktif, status order tidak bisa di-set complete ketika payment status masih unpaid.</div>
                                    </div>
                                </label>
                                <label class="flex items-start gap-2">
                                    <input type="hidden" name="require_paid_before_submit" value="0" />
                                    <input type="checkbox" name="require_paid_before_submit" value="1" class="mt-1 rounded" @checked(old('require_paid_before_submit', $setting?->require_paid_before_submit) ? true : false) />
                                    <div>
                                        <div class="font-semibold">Wajib Paid sebelum Proses Order (Kasir)</div>
                                        <div class="text-xs text-gray-500">Jika aktif, kasir tidak bisa submit pesanan sebagai unpaid (kecuali QRIS Dynamic yang memang generate QR).</div>
                                    </div>
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-2xl border border-gray-200 p-4">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <div class="font-semibold">QRIS Statis</div>
                                <div class="text-xs text-gray-500">Upload gambar QR atau isi payload EMVCo (opsional).</div>
                            </div>
                            @if($setting?->qris_static_image_path)
                                <label class="flex items-center gap-2 text-xs">
                                    <input type="hidden" name="remove_qris_static_image" value="0" />
                                    <input type="checkbox" name="remove_qris_static_image" value="1" class="rounded" />
                                    <span>Hapus gambar</span>
                                </label>
                            @endif
                        </div>

                        @if($setting?->qris_static_image_path)
                            <div class="mt-3">
                                <div class="text-xs text-gray-600 mb-2">Preview:</div>
                                <img src="{{ \Illuminate\Support\Facades\Storage::url($setting->qris_static_image_path) }}" alt="QRIS Statis" class="w-56 rounded-xl border bg-white" />
                            </div>
                        @endif

                        <div class="mt-4 grid sm:grid-cols-2 gap-4">
                            <div>
                                <label class="text-xs font-semibold text-gray-600">Gambar QRIS (png/jpg)</label>
                                <input type="file" name="qris_static_image" class="mt-1 block w-full text-sm" />
                            </div>
                            <div>
                                <label class="text-xs font-semibold text-gray-600">Payload (opsional)</label>
                                <textarea name="qris_static_payload" rows="4" class="mt-1 w-full rounded-xl border-gray-300 text-sm" placeholder="000201010212...">{{ old('qris_static_payload', $setting?->qris_static_payload) }}</textarea>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-2xl border border-gray-200 p-4">
                        <div class="font-semibold">QRIS Dinamis (API)</div>
                        <div class="mt-2 grid sm:grid-cols-2 gap-4">
                            <div>
                                <label class="text-xs font-semibold text-gray-600">Provider</label>
                                <select name="dynamic_provider" class="mt-1 w-full rounded-xl border-gray-300 text-sm">
                                    <option value="midtrans" @selected(old('dynamic_provider', $setting?->dynamic_provider) === 'midtrans')>Midtrans</option>
                                </select>
                                <div class="mt-2 text-xs text-gray-500">
                                    Status konfigurasi Midtrans:
                                    @if($midtransConfigured)
                                        <span class="font-semibold text-emerald-700">Terdeteksi</span>
                                    @else
                                        <span class="font-semibold text-red-700">Belum</span> (set <code>MIDTRANS_SERVER_KEY</code>)
                                    @endif
                                </div>
                            </div>

                            <div>
                                <label class="text-xs font-semibold text-gray-600">Mode</label>
                                <div class="mt-2 grid gap-2 text-sm">
                                    <label class="flex items-center gap-2">
                                        <input type="hidden" name="dynamic_sandbox" value="0" />
                                        <input type="checkbox" name="dynamic_sandbox" value="1" class="rounded" @checked(old('dynamic_sandbox', $setting?->dynamic_sandbox) ? true : false) />
                                        <span>Sandbox/Test Mode</span>
                                    </label>
                                </div>
                                <div class="mt-2 text-xs text-gray-500">
                                    Midtrans environment ditentukan oleh <code>MIDTRANS_IS_PRODUCTION</code>.
                                </div>
                            </div>
                        </div>

                        <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900">
                            Catatan: integrasi QRIS dinamis dibuat via API Midtrans (aggregator) supaya kompatibel dengan banyak channel pembayaran di Indonesia. Untuk provider lain (Xendit/dll) bisa ditambahkan nanti.
                        </div>
                    </div>

                    <div class="flex items-center justify-end gap-2">
                        <button type="submit" class="px-5 py-3 rounded-xl bg-slate-900 text-white font-semibold">Simpan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection

