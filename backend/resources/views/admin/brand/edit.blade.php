@php
    /** @var \App\Models\BrandSetting|null $brand */
    /** @var array<string,string> $fontOptions */
@endphp

@extends('layouts.admin-sidebar')

@section('header')
    <div class="flex items-center justify-between">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Brand Identity</h2>
    </div>
@endsection

@section('content')
    <div class="py-6" x-data="brandForm(@js([
        'business_name' => $brand?->business_name ?? 'Self Order',
        'tagline' => $brand?->tagline ?? 'Cafe & Resto',
        'about' => $brand?->about ?? '',
        'phone' => $brand?->phone ?? '',
        'whatsapp' => $brand?->whatsapp ?? '',
        'address' => $brand?->address ?? '',
        'instagram' => $brand?->instagram ?? '',
        'website' => $brand?->website ?? '',
        'primary_color' => $brand?->primary_color ?? '#0f172a',
        'secondary_color' => $brand?->secondary_color ?? '#334155',
        'accent_color' => $brand?->accent_color ?? '#10b981',
        'background_color' => $brand?->background_color ?? '#f8fafc',
        'background_gradient' => $brand?->background_gradient ?? '',
        'background_pattern' => $brand?->background_pattern ?? 'mesh',
        'background_overlay_opacity' => (float) ($brand?->background_overlay_opacity ?? 0.00),
        'button_radius' => (int) ($brand?->button_radius ?? 18),
        'font_family' => $brand?->font_family ?? 'system-ui',
        'customer_demo_mode_enabled' => (bool) ($brand?->customer_demo_mode_enabled ?? false),
        'google_maps_place_id' => $brand?->google_maps_place_id ?? '',
    ]))">
        <div class="w-full">
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

            <div id="brand-editor-layout" class="grid grid-cols-1 lg:grid-cols-12 gap-5">
                <div class="lg:col-span-7 xl:col-span-8 min-w-0">
                    <form class="space-y-4" method="POST" action="{{ route('admin.brand.update') }}" enctype="multipart/form-data">
                        @csrf
                        @method('PUT')

                        <div class="bg-white shadow-sm rounded-2xl border border-gray-200 p-5">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <h3 class="text-base font-semibold text-gray-900">Editor Branding</h3>
                                    <p class="mt-1 text-sm text-gray-600">Ubah data di kiri, lihat hasilnya langsung di preview kanan.</p>
                                </div>
                                <div class="inline-flex rounded-xl bg-gray-100 p-1">
                                    <button type="button" @click="activeTab='identitas'" class="px-3 py-1.5 text-xs font-semibold rounded-lg transition" :class="activeTab==='identitas' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-600'">Identitas</button>
                                    <button type="button" @click="activeTab='tampilan'" class="px-3 py-1.5 text-xs font-semibold rounded-lg transition" :class="activeTab==='tampilan' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-600'">Tampilan</button>
                                    <button type="button" @click="activeTab='media'" class="px-3 py-1.5 text-xs font-semibold rounded-lg transition" :class="activeTab==='media' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-600'">Media</button>
                                </div>
                            </div>
                        </div>

                        <div x-show="activeTab==='identitas'" x-cloak class="space-y-4">
                            <div class="bg-white rounded-2xl border border-gray-200 p-5">
                                <div class="text-sm font-semibold text-gray-900">Profil Usaha</div>
                                <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <label class="text-sm font-medium text-gray-700">Nama Usaha</label>
                                        <input name="business_name" class="mt-1 w-full rounded-xl border border-gray-300 px-4 py-3" x-model="form.business_name" />
                                    </div>
                                    <div>
                                        <label class="text-sm font-medium text-gray-700">Tagline</label>
                                        <input name="tagline" class="mt-1 w-full rounded-xl border border-gray-300 px-4 py-3" x-model="form.tagline" placeholder="Tagline singkat" />
                                    </div>
                                    <div class="sm:col-span-2">
                                        <label class="text-sm font-medium text-gray-700">Tentang Usaha</label>
                                        <textarea name="about" rows="4" class="mt-1 w-full rounded-xl border border-gray-300 px-4 py-3" x-model="form.about" placeholder="Deskripsi singkat usaha"></textarea>
                                    </div>
                                </div>
                            </div>

                            <div class="bg-white rounded-2xl border border-gray-200 p-5">
                                <div class="text-sm font-semibold text-gray-900">Kontak & Lokasi</div>
                                <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <label class="text-sm font-medium text-gray-700">Telepon</label>
                                        <input name="phone" class="mt-1 w-full rounded-xl border border-gray-300 px-4 py-3" x-model="form.phone" placeholder="0812..." />
                                    </div>
                                    <div>
                                        <label class="text-sm font-medium text-gray-700">WhatsApp</label>
                                        <input name="whatsapp" class="mt-1 w-full rounded-xl border border-gray-300 px-4 py-3" x-model="form.whatsapp" placeholder="62812..." />
                                    </div>
                                    <div>
                                        <label class="text-sm font-medium text-gray-700">Instagram</label>
                                        <input name="instagram" class="mt-1 w-full rounded-xl border border-gray-300 px-4 py-3" x-model="form.instagram" placeholder="@akun" />
                                    </div>
                                    <div>
                                        <label class="text-sm font-medium text-gray-700">Website</label>
                                        <input name="website" class="mt-1 w-full rounded-xl border border-gray-300 px-4 py-3" x-model="form.website" placeholder="https://" />
                                    </div>
                                    <div class="sm:col-span-2">
                                        <label class="text-sm font-medium text-gray-700">Alamat</label>
                                        <input name="address" class="mt-1 w-full rounded-xl border border-gray-300 px-4 py-3" x-model="form.address" />
                                    </div>
                                    <div class="sm:col-span-2">
                                        <label class="text-sm font-medium text-gray-700">Google Maps Place ID</label>
                                        <input name="google_maps_place_id" class="mt-1 w-full rounded-xl border border-gray-300 px-4 py-3" x-model="form.google_maps_place_id" placeholder="ChIJ..." />
                                        <div class="mt-1 text-xs text-gray-500">Dipakai untuk menampilkan rating Google di halaman customer.</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div x-show="activeTab==='tampilan'" x-cloak class="space-y-4">
                            <div class="bg-white rounded-2xl border border-gray-200 p-5">
                                <div class="flex items-center justify-between gap-3">
                                    <div>
                                        <div class="text-sm font-semibold text-gray-900">Warna Brand</div>
                                        <div class="text-xs text-gray-500">Pilih cepat lewat preset, lalu sesuaikan manual.</div>
                                    </div>
                                    <div class="flex flex-wrap gap-2">
                                        <button type="button" @click="applyPreset('modern')" class="px-3 py-1.5 rounded-lg border border-gray-200 text-xs font-semibold text-gray-700 hover:bg-gray-50">Modern</button>
                                        <button type="button" @click="applyPreset('coffee')" class="px-3 py-1.5 rounded-lg border border-gray-200 text-xs font-semibold text-gray-700 hover:bg-gray-50">Coffee</button>
                                        <button type="button" @click="applyPreset('fresh')" class="px-3 py-1.5 rounded-lg border border-gray-200 text-xs font-semibold text-gray-700 hover:bg-gray-50">Fresh</button>
                                    </div>
                                </div>

                                <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div class="rounded-xl border border-gray-200 p-3">
                                        <label class="text-xs font-semibold text-gray-600">Primary</label>
                                        <div class="mt-2 flex items-center gap-2">
                                            <input type="color" name="primary_color" class="h-10 w-12 rounded border border-gray-300" x-model="form.primary_color" />
                                            <input type="text" class="flex-1 rounded-lg border border-gray-300 px-3 py-2 text-sm uppercase" x-model="form.primary_color" @blur="normalizeHex('primary_color')" />
                                        </div>
                                    </div>
                                    <div class="rounded-xl border border-gray-200 p-3">
                                        <label class="text-xs font-semibold text-gray-600">Secondary</label>
                                        <div class="mt-2 flex items-center gap-2">
                                            <input type="color" name="secondary_color" class="h-10 w-12 rounded border border-gray-300" x-model="form.secondary_color" />
                                            <input type="text" class="flex-1 rounded-lg border border-gray-300 px-3 py-2 text-sm uppercase" x-model="form.secondary_color" @blur="normalizeHex('secondary_color')" />
                                        </div>
                                    </div>
                                    <div class="rounded-xl border border-gray-200 p-3">
                                        <label class="text-xs font-semibold text-gray-600">Accent</label>
                                        <div class="mt-2 flex items-center gap-2">
                                            <input type="color" name="accent_color" class="h-10 w-12 rounded border border-gray-300" x-model="form.accent_color" />
                                            <input type="text" class="flex-1 rounded-lg border border-gray-300 px-3 py-2 text-sm uppercase" x-model="form.accent_color" @blur="normalizeHex('accent_color')" />
                                        </div>
                                    </div>
                                    <div class="rounded-xl border border-gray-200 p-3">
                                        <label class="text-xs font-semibold text-gray-600">Background</label>
                                        <div class="mt-2 flex items-center gap-2">
                                            <input type="color" name="background_color" class="h-10 w-12 rounded border border-gray-300" x-model="form.background_color" />
                                            <input type="text" class="flex-1 rounded-lg border border-gray-300 px-3 py-2 text-sm uppercase" x-model="form.background_color" @blur="normalizeHex('background_color')" />
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="bg-white rounded-2xl border border-gray-200 p-5">
                                <div class="text-sm font-semibold text-gray-900">Gaya UI</div>
                                <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <label class="text-sm">
                                        <div class="font-medium text-gray-700">Radius Tombol</div>
                                        <input type="range" min="0" max="40" name="button_radius" class="mt-2 w-full" x-model.number="form.button_radius" />
                                        <div class="mt-1 text-xs text-gray-500" x-text="form.button_radius + 'px'"></div>
                                    </label>
                                    <label class="text-sm">
                                        <div class="font-medium text-gray-700">Font</div>
                                        <select name="font_family" class="mt-2 w-full rounded-xl border border-gray-300 px-4 py-3" x-model="form.font_family">
                                            @foreach($fontOptions as $value => $label)
                                                <option value="{{ $value }}">{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                </div>
                            </div>

                            <details class="bg-white rounded-2xl border border-gray-200 p-5">
                                <summary class="cursor-pointer text-sm font-semibold text-gray-900">Pengaturan Background Lanjutan (opsional)</summary>
                                <div class="mt-4 space-y-4">
                                    <label class="text-sm block">
                                        <div class="font-medium text-gray-700">Gradient CSS</div>
                                        <input type="text" name="background_gradient" class="mt-2 w-full rounded-xl border border-gray-300 px-4 py-3" x-model="form.background_gradient" placeholder="linear-gradient(135deg, #667eea 0%, #764ba2 100%)" />
                                    </label>
                                    <label class="text-sm block">
                                        <div class="font-medium text-gray-700">Pattern</div>
                                        <select name="background_pattern" class="mt-2 w-full rounded-xl border border-gray-300 px-4 py-3" x-model="form.background_pattern">
                                            <option value="none">Tanpa pattern</option>
                                            <option value="mesh">Mesh</option>
                                            <option value="dots">Dots</option>
                                            <option value="lines">Lines</option>
                                            <option value="waves">Waves</option>
                                        </select>
                                    </label>
                                    <label class="text-sm block">
                                        <div class="font-medium text-gray-700">Overlay Opacity</div>
                                        <input type="range" min="0" max="1" step="0.1" name="background_overlay_opacity" class="mt-2 w-full" x-model.number="form.background_overlay_opacity" />
                                        <div class="mt-1 text-xs text-gray-500" x-text="'Opacity: ' + form.background_overlay_opacity"></div>
                                    </label>
                                </div>
                            </details>

                            <div class="bg-white rounded-2xl border border-gray-200 p-5">
                                <div class="text-sm font-semibold text-gray-900">Mode Customer</div>
                                <label class="mt-3 flex items-center justify-between gap-3 rounded-2xl border border-gray-200 bg-gray-50 px-4 py-3">
                                    <div>
                                        <div class="text-sm font-semibold">Aktifkan Mode Demo</div>
                                        <div class="mt-0.5 text-xs text-gray-600">Untuk kebutuhan testing/development halaman customer.</div>
                                    </div>
                                    <input type="hidden" name="customer_demo_mode_enabled" value="0" />
                                    <input type="checkbox" name="customer_demo_mode_enabled" value="1" class="h-5 w-5" x-model="form.customer_demo_mode_enabled" />
                                </label>
                            </div>
                        </div>

                        <div x-show="activeTab==='media'" x-cloak class="space-y-4">
                            <div class="bg-white rounded-2xl border border-gray-200 p-5">
                                <div class="text-sm font-semibold text-gray-900">Logo & Favicon</div>
                                <div class="mt-3 grid grid-cols-1 sm:grid-cols-3 gap-4">
                                    <div>
                                        <label class="text-sm font-medium text-gray-700">Logo Utama</label>
                                        <input type="file" name="logo_light" accept="image/*" class="mt-2 w-full text-sm" />
                                        @if($brand?->logoLightUrl())
                                            <img src="{{ $brand->logoLightUrl() }}" alt="Logo" class="mt-3 h-10" />
                                        @endif
                                    </div>
                                    <div>
                                        <label class="text-sm font-medium text-gray-700">Logo Dark</label>
                                        <input type="file" name="logo_dark" accept="image/*" class="mt-2 w-full text-sm" />
                                        @if($brand?->logoDarkUrl())
                                            <img src="{{ $brand->logoDarkUrl() }}" alt="Logo dark" class="mt-3 h-10 bg-gray-900 px-2 py-1 rounded" />
                                        @endif
                                    </div>
                                    <div>
                                        <label class="text-sm font-medium text-gray-700">Favicon</label>
                                        <input type="file" name="favicon" accept="image/*,.ico" class="mt-2 w-full text-sm" />
                                        @if($brand?->faviconUrl())
                                            <img src="{{ $brand->faviconUrl() }}" alt="Favicon" class="mt-3 h-8 w-8 rounded" />
                                        @endif
                                    </div>
                                </div>
                            </div>

                            <div class="bg-white rounded-2xl border border-gray-200 p-5">
                                <div class="text-sm font-semibold text-gray-900">Home Banner</div>
                                <div class="mt-1 text-xs text-gray-500">Bisa gambar, GIF, atau video (mp4/webm).</div>
                                <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <label class="text-sm font-medium text-gray-700">Upload Banner</label>
                                        <input type="file" name="home_banner_media" accept="image/*,video/mp4,video/webm" class="mt-2 w-full text-sm" />
                                    </div>
                                    <div>
                                        <label class="text-sm font-medium text-gray-700">Banner Saat Ini</label>
                                        <div class="mt-2 rounded-2xl overflow-hidden border border-gray-200 bg-gray-50 aspect-[16/9] grid place-items-center">
                                            @if($brand?->homeBannerMediaUrl())
                                                @if($brand->homeBannerIsVideo())
                                                    <video class="w-full h-full object-cover" autoplay muted loop playsinline>
                                                        <source src="{{ $brand->homeBannerMediaUrl() }}" type="{{ $brand->home_banner_media_mime ?? 'video/mp4' }}" />
                                                    </video>
                                                @else
                                                    <img src="{{ $brand->homeBannerMediaUrl() }}" alt="Home banner" class="w-full h-full object-cover" />
                                                @endif
                                            @else
                                                <div class="text-xs text-gray-500">Belum ada banner</div>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="sticky bottom-0 z-20">
                            <div class="rounded-2xl border border-gray-200 bg-white/95 backdrop-blur px-4 py-3 flex items-center justify-between">
                                <div class="text-xs text-gray-500">Perubahan tampil real-time di preview, baru tersimpan saat klik tombol ini.</div>
                                <button type="submit" class="rounded-xl bg-gray-900 text-white px-5 py-3 font-semibold">Simpan Perubahan</button>
                            </div>
                        </div>
                    </form>
                </div>

                <div class="lg:col-span-5 xl:col-span-4 min-w-0">
                    <div class="sticky top-6 space-y-3">
                        <div class="bg-white shadow-sm rounded-2xl border border-gray-200 p-4">
                            <div class="font-semibold text-gray-900">Live Preview</div>
                            <div class="text-xs text-gray-500">Meniru tampilan halaman customer: header, konten, dan bottom nav.</div>
                        </div>

                        <div class="rounded-3xl border border-gray-200 overflow-hidden shadow-sm" :style="previewShellStyle()">
                            <div class="p-4 border-b border-black/10 flex items-center justify-between gap-2">
                                <div class="flex items-center gap-3 min-w-0">
                                    <div class="h-10 w-10 rounded-2xl text-white grid place-items-center font-bold shrink-0" :style="'background:'+form.primary_color">SO</div>
                                    <div class="min-w-0">
                                        <div class="text-sm font-semibold truncate" x-text="form.business_name || 'Nama Usaha'"></div>
                                        <div class="text-[11px] truncate" :style="'color:'+form.secondary_color" x-text="form.tagline || 'Tagline usaha'"></div>
                                    </div>
                                </div>
                                <div class="text-[10px] text-gray-500">Member</div>
                            </div>

                            <div class="p-4 space-y-3" :style="'font-family:'+form.font_family">
                                <div class="rounded-2xl border border-black/10 bg-white/70 p-3" :style="'border-radius:'+form.button_radius+'px'">
                                    <div class="text-xs font-semibold text-gray-700">Tentang</div>
                                    <p class="text-xs text-gray-600 mt-1 leading-relaxed" x-text="form.about || 'Deskripsi usaha akan tampil di sini.'"></p>
                                </div>

                                <div class="grid grid-cols-2 gap-2">
                                    <div class="rounded-xl border border-black/10 bg-white/80 p-3" :style="'border-radius:'+form.button_radius+'px'">
                                        <div class="text-[11px] text-gray-500">Nasi Goreng</div>
                                        <div class="text-xs font-semibold mt-1">Rp 25.000</div>
                                    </div>
                                    <div class="rounded-xl border border-black/10 bg-white/80 p-3" :style="'border-radius:'+form.button_radius+'px'">
                                        <div class="text-[11px] text-gray-500">Es Teh</div>
                                        <div class="text-xs font-semibold mt-1">Rp 5.000</div>
                                    </div>
                                </div>

                                <button type="button" class="w-full px-4 py-2.5 text-sm font-semibold text-white" :style="'background:'+form.primary_color+';border-radius:'+form.button_radius+'px'">Scan QR untuk Pesan</button>
                                <button type="button" class="w-full px-4 py-2.5 text-sm font-semibold text-white" :style="'background:'+form.accent_color+';border-radius:'+form.button_radius+'px'">Lihat Promo</button>

                                <div class="grid grid-cols-4 gap-1 rounded-2xl border border-black/10 bg-white/85 p-2 mt-4" :style="'border-radius:'+form.button_radius+'px'">
                                    <div class="text-center text-[10px] font-semibold text-white py-1.5" :style="'background:'+form.primary_color+';border-radius:'+form.button_radius+'px'">Home</div>
                                    <div class="text-center text-[10px] text-gray-600 py-1.5">Menu</div>
                                    <div class="text-center text-[10px] text-gray-600 py-1.5">Pesanan</div>
                                    <div class="text-center text-[10px] text-gray-600 py-1.5">Promo</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <style>
            #brand-editor-layout {
                width: 100%;
                max-width: none;
            }

            @media (min-width: 1024px) {
                #brand-editor-layout {
                    display: grid;
                    grid-template-columns: minmax(0, 1.45fr) minmax(340px, 1fr);
                    gap: 1.25rem;
                    align-items: start;
                }

                #brand-editor-layout > div {
                    min-width: 0;
                    width: 100%;
                }
            }
        </style>

        <script>
            function brandForm(initial) {
                return {
                    form: initial,
                    activeTab: 'identitas',
                    applyPreset(name) {
                        const presets = {
                            modern: { primary_color: '#0F172A', secondary_color: '#334155', accent_color: '#10B981', background_color: '#F8FAFC' },
                            coffee: { primary_color: '#4E342E', secondary_color: '#795548', accent_color: '#C47F17', background_color: '#FFF8F1' },
                            fresh: { primary_color: '#065F46', secondary_color: '#047857', accent_color: '#22C55E', background_color: '#F0FDF4' },
                        };
                        const selected = presets[name];
                        if (!selected) return;
                        this.form.primary_color = selected.primary_color;
                        this.form.secondary_color = selected.secondary_color;
                        this.form.accent_color = selected.accent_color;
                        this.form.background_color = selected.background_color;
                    },
                    normalizeHex(field) {
                        let value = String(this.form[field] || '').trim().toUpperCase();
                        if (!value.startsWith('#')) value = `#${value}`;
                        if (/^#([0-9A-F]{6})$/.test(value)) {
                            this.form[field] = value;
                        }
                    },
                    previewShellStyle() {
                        const background = this.form.background_gradient && this.form.background_gradient.trim() !== ''
                            ? this.form.background_gradient
                            : this.form.background_color;

                        return [
                            `background:${background}`,
                            `font-family:${this.form.font_family}`,
                            `--button-radius:${this.form.button_radius}px`
                        ].join(';');
                    },
                };
            }
        </script>
    </div>
@endsection
