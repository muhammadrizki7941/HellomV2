@php
    /** @var \App\Models\ReservationSpace $space */
    /** @var \Illuminate\Support\Collection<int, \App\Models\Product> $menuProducts */
@endphp

<x-customer-layout>
    <x-slot name="headerRight">
        <a href="{{ route('reservation.index') }}" class="text-xs font-semibold text-slate-600">Kembali</a>
    </x-slot>

    @php
        $imageUrls = $space->images->map(fn($i) => $i->url())->values();
    @endphp

    <div class="mt-2" x-data="{ selectedImg: 0, images: @js($imageUrls) }">
        <div class="rounded-3xl border border-slate-200 bg-white shadow-sm overflow-hidden" style="border-radius: var(--button-radius)">
            <div class="p-5">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <div class="text-lg font-semibold">{{ $space->name }}</div>
                        <div class="text-xs text-slate-600">
                            @if($space->location) {{ $space->location }} @endif
                            @if($space->capacity) · Kapasitas {{ $space->capacity }} @endif
                        </div>
                    </div>
                    <div class="text-right">
                        <div class="text-xs text-slate-500">Total</div>
                        <div class="text-lg font-extrabold">Rp {{ number_format($space->total_price, 0, ',', '.') }}</div>
                    </div>
                </div>

                @if($space->description)
                    <div class="mt-3 text-sm text-slate-700 leading-relaxed">{{ $space->description }}</div>
                @endif
            </div>

            <div class="border-t border-slate-200">
                @if($space->images->count())
                    <div class="p-4">
                        <div class="rounded-3xl overflow-hidden border border-slate-200 bg-black/5" style="border-radius: var(--button-radius)">
                            <img :src="images[selectedImg]" alt="" class="h-56 w-full object-cover" />
                        </div>
                        <div class="mt-3 flex gap-2 overflow-auto pb-1">
                            @foreach($space->images as $idx => $img)
                                <button type="button" class="h-16 w-20 shrink-0 rounded-2xl overflow-hidden border" :class="selectedImg === {{ $idx }} ? 'border-slate-900' : 'border-slate-200'" @click="selectedImg = {{ $idx }}">
                                    <img src="{{ $img->url() }}" alt="" class="h-full w-full object-cover" />
                                </button>
                            @endforeach
                        </div>
                    </div>
                @else
                    <div class="p-6 text-sm text-slate-600">Belum ada foto untuk space ini.</div>
                @endif
            </div>
        </div>

        <div class="mt-4 grid sm:grid-cols-2 gap-3">
            <div class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm" style="border-radius: var(--button-radius)">
                <div class="text-sm font-semibold">Rincian Harga</div>
                <div class="mt-3 text-sm text-slate-700 flex items-center justify-between"><span>Sewa</span>
                    <span>
                        @if($space->rent_enabled)
                            Rp {{ number_format($space->rent_price, 0, ',', '.') }}
                        @else
                            Free
                        @endif
                    </span>
                </div>
                <div class="text-sm text-slate-700 flex items-center justify-between"><span>Menu termasuk</span><span>Rp {{ number_format($space->items_total, 0, ',', '.') }}</span></div>
                <div class="mt-2 pt-2 border-t border-slate-200 text-sm font-semibold flex items-center justify-between"><span>Total</span><span>Rp {{ number_format($space->total_price, 0, ',', '.') }}</span></div>

                @if(($space->min_menu_total ?? 0) > 0)
                    <div class="mt-3 rounded-2xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900" style="border-radius: var(--button-radius)">
                        Syarat: minimal order menu Rp {{ number_format($space->min_menu_total, 0, ',', '.') }}.
                    </div>
                @endif
            </div>

            <div class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm" style="border-radius: var(--button-radius)">
                <div class="text-sm font-semibold">Menu Termasuk</div>
                <div class="mt-3 grid gap-2">
                    @forelse($space->items as $it)
                        <div class="flex items-center justify-between gap-3 rounded-2xl border border-slate-200 px-4 py-3" style="border-radius: var(--button-radius)">
                            <div>
                                <div class="text-sm font-semibold">{{ $it->product_name }}</div>
                                <div class="text-xs text-slate-600">Qty {{ $it->qty }} · @ Rp {{ number_format($it->unit_price, 0, ',', '.') }}</div>
                            </div>
                            <div class="text-sm font-semibold">Rp {{ number_format($it->line_total, 0, ',', '.') }}</div>
                        </div>
                    @empty
                        <div class="text-sm text-slate-600">Tidak ada menu termasuk.</div>
                    @endforelse
                </div>
            </div>
        </div>

        <div class="mt-4 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm" style="border-radius: var(--button-radius)"
            x-data="reservationBooking({
                availabilityUrl: @js(route('reservation.availability', $space)),
                initialScheduledAt: @js(old('scheduled_at')),
                initialDuration: @js((int) old('duration_minutes', 60)),
                baseTotal: @js((int) $space->total_price),
                minMenuTotal: @js((int) ($space->min_menu_total ?? 0)),
                products: @js(($menuProducts ?? collect())->map(fn($p) => [
                    'id' => (int) $p->id,
                    'name' => (string) $p->name,
                    'price' => (int) $p->price,
                    'is_available' => (bool) $p->is_available,
                    'track_stock' => (bool) $p->track_stock,
                    'stock' => (int) ($p->stock ?? 0),
                ])->values()->all()),
            })">
            <div class="text-lg font-semibold">Booking Reservasi</div>
            <div class="mt-1 text-sm text-slate-600">Isi data kamu. Status awal: <span class="font-semibold">pending</span> (menunggu konfirmasi).</div>

            @guest
                <div class="mt-3 rounded-2xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900" style="border-radius: var(--button-radius)">
                    Setelah klik <span class="font-semibold">Kirim Permintaan Reservasi</span>, kamu akan diarahkan ke halaman <span class="font-semibold">Daftar</span> untuk membuat akun dan mengisi password.
                </div>
            @endguest

            <form method="POST" action="{{ route('reservation.store', $space) }}" class="mt-4 grid gap-3" @submit="beforeSubmit($event)">
                @csrf

                <div class="grid sm:grid-cols-2 gap-3">
                    <div>
                        <label class="text-sm font-medium">Nama</label>
                        <input name="customer_name" value="{{ old('customer_name', auth()->user()?->name) }}" class="mt-1 w-full rounded-2xl border-slate-300" required style="border-radius: var(--button-radius)" />
                        @error('customer_name')<div class="text-sm text-rose-700 mt-1">{{ $message }}</div>@enderror
                    </div>
                    <div>
                        <label class="text-sm font-medium">No. HP / WhatsApp</label>
                        <input name="customer_phone" value="{{ old('customer_phone', auth()->user()?->phone) }}" class="mt-1 w-full rounded-2xl border-slate-300" required style="border-radius: var(--button-radius)" />
                        @error('customer_phone')<div class="text-sm text-rose-700 mt-1">{{ $message }}</div>@enderror
                    </div>
                </div>

                <div class="grid sm:grid-cols-2 gap-3">
                    <div>
                        <label class="text-sm font-medium">Email (opsional)</label>
                        <input name="customer_email" type="email" value="{{ old('customer_email', auth()->user()?->email) }}" class="mt-1 w-full rounded-2xl border-slate-300" style="border-radius: var(--button-radius)" />
                        @error('customer_email')<div class="text-sm text-rose-700 mt-1">{{ $message }}</div>@enderror
                    </div>
                    <div>
                        <label class="text-sm font-medium">Jumlah Tamu (opsional)</label>
                        <input name="guests_count" type="number" min="1" value="{{ old('guests_count') }}" class="mt-1 w-full rounded-2xl border-slate-300" style="border-radius: var(--button-radius)" />
                        @error('guests_count')<div class="text-sm text-rose-700 mt-1">{{ $message }}</div>@enderror
                    </div>
                </div>

                <div class="grid sm:grid-cols-2 gap-3">
                    <div>
                        <label class="text-sm font-medium">Jadwal</label>
                        <input name="scheduled_at" type="datetime-local" value="{{ old('scheduled_at') }}" class="mt-1 w-full rounded-2xl border-slate-300" required style="border-radius: var(--button-radius)" x-model="scheduledAt" @change="check()" @input.debounce.600ms="check()" />
                        @error('scheduled_at')<div class="text-sm text-rose-700 mt-1">{{ $message }}</div>@enderror
                    </div>
                    <div>
                        <label class="text-sm font-medium">Durasi (menit)</label>
                        <input name="duration_minutes" type="number" min="30" max="720" value="{{ old('duration_minutes', 60) }}" class="mt-1 w-full rounded-2xl border-slate-300" required style="border-radius: var(--button-radius)" x-model.number="duration" @change="check()" @input.debounce.600ms="check()" />
                        @error('duration_minutes')<div class="text-sm text-rose-700 mt-1">{{ $message }}</div>@enderror
                    </div>
                </div>

                <template x-if="availability.checked">
                    <div class="rounded-2xl border p-3 text-sm" style="border-radius: var(--button-radius)"
                        :class="availability.ok ? 'border-emerald-200 bg-emerald-50 text-emerald-900' : 'border-rose-200 bg-rose-50 text-rose-900'">
                        <span x-text="availability.message"></span>
                    </div>
                </template>

                <div class="rounded-3xl border border-slate-200 bg-white p-4" style="border-radius: var(--button-radius)">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <div class="text-sm font-semibold">Pilih Menu (Pre-Order)</div>
                            <div class="mt-0.5 text-xs text-slate-600">Pilih menu yang tersedia untuk disiapkan saat reservasi.</div>
                        </div>
                        <button type="button" class="text-xs font-semibold underline text-slate-600 hover:text-slate-900" @click="menuOpen = !menuOpen" x-text="menuOpen ? 'Tutup' : 'Pilih menu'"></button>
                    </div>

                    <input type="hidden" name="menu_items" :value="JSON.stringify(menuPayload())" />
                    @error('menu_items')<div class="text-sm text-rose-700 mt-2">{{ $message }}</div>@enderror

                    <div class="mt-3" x-show="minMenuTotal > 0" x-cloak>
                        <div class="rounded-2xl border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900" style="border-radius: var(--button-radius)">
                            Minimal order menu: <span class="font-extrabold">Rp <span x-text="formatRp(minMenuTotal)"></span></span>.
                        </div>
                    </div>

                    <div class="mt-3" x-show="menuOpen" x-cloak>
                        <div class="grid gap-3">
                            <input type="text" class="w-full rounded-2xl border border-slate-300 px-4 py-3 text-sm"
                                style="border-radius: var(--button-radius)" placeholder="Cari menu..." x-model="menuQuery" />

                            <div class="grid gap-2 max-h-80 overflow-auto pr-1">
                                <template x-for="p in filteredProducts()" :key="p.id">
                                    <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 flex items-center justify-between gap-3" style="border-radius: var(--button-radius)">
                                        <div class="min-w-0">
                                            <div class="text-sm font-semibold truncate" x-text="p.name"></div>
                                            <div class="text-xs text-slate-600">Rp <span x-text="formatRp(p.price)"></span></div>
                                            <div class="mt-0.5 text-[11px]" x-show="p.track_stock" x-cloak :class="(p.stock||0) > 0 ? 'text-slate-500' : 'text-rose-700'">
                                                Stok: <span x-text="p.stock"></span>
                                            </div>
                                        </div>

                                        <div class="flex items-center gap-2 shrink-0">
                                            <button type="button" class="h-9 w-9 rounded-xl border border-slate-200 bg-white font-semibold" @click="decMenu(p.id)">−</button>
                                            <div class="min-w-8 text-center font-semibold" x-text="qtyById(p.id)"></div>
                                            <button type="button" class="h-9 w-9 rounded-xl border border-slate-200 bg-white font-semibold"
                                                :disabled="p.track_stock && (qtyById(p.id) >= (p.stock||0))"
                                                @click="incMenu(p.id)">+</button>
                                        </div>
                                    </div>
                                </template>

                                <div class="text-sm text-slate-600" x-show="filteredProducts().length === 0" x-cloak>
                                    Menu tidak ditemukan.
                                </div>
                            </div>

                            <div class="rounded-2xl border border-slate-200 bg-white p-4" style="border-radius: var(--button-radius)">
                                <div class="flex items-center justify-between text-sm">
                                    <span class="text-slate-600">Total pre-order</span>
                                    <span class="font-extrabold">Rp <span x-text="formatRp(menuTotal())"></span></span>
                                </div>

                                <div class="mt-2 rounded-2xl border p-3 text-xs" style="border-radius: var(--button-radius)"
                                    x-show="minMenuTotal > 0" x-cloak
                                    :class="menuTotal() >= minMenuTotal ? 'border-emerald-200 bg-emerald-50 text-emerald-900' : 'border-rose-200 bg-rose-50 text-rose-900'">
                                    <template x-if="menuTotal() >= minMenuTotal">
                                        <span>Minimal order terpenuhi.</span>
                                    </template>
                                    <template x-if="menuTotal() < minMenuTotal">
                                        <span>Kurang Rp <span class="font-bold" x-text="formatRp(minMenuTotal - menuTotal())"></span> lagi untuk memenuhi minimal order.</span>
                                    </template>
                                </div>

                                <div class="mt-2 flex items-center justify-between">
                                    <button type="button" class="text-xs font-semibold underline text-slate-600 hover:text-slate-900" @click="clearMenu()" :disabled="menuTotal()===0">Reset menu</button>
                                    <button type="button" class="rounded-2xl px-4 py-2 text-xs font-bold text-white" style="background: var(--primary-color); border-radius: var(--button-radius)" @click="menuOpen=false">Selesai</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div>
                    <label class="text-sm font-medium">Catatan (opsional)</label>
                    <textarea name="notes" rows="3" class="mt-1 w-full rounded-2xl border-slate-300" style="border-radius: var(--button-radius)" placeholder="Contoh: minta posisi dekat jendela">{{ old('notes') }}</textarea>
                    @error('notes')<div class="text-sm text-rose-700 mt-1">{{ $message }}</div>@enderror
                </div>

                <div class="rounded-3xl border border-slate-200 bg-slate-50 p-4" style="border-radius: var(--button-radius)">
                    <div class="text-xs text-slate-500">Perkiraan total</div>
                    <div class="text-lg font-extrabold">Rp <span x-text="formatRp(grandTotal())"></span></div>
                    <div class="text-xs text-slate-600">(Sewa + paket menu termasuk + pre-order menu)</div>
                </div>

                <button class="rounded-2xl px-5 py-4 font-extrabold text-white shadow-sm disabled:opacity-60 disabled:cursor-not-allowed"
                    :disabled="(availability.checked && !availability.ok) || (minMenuTotal>0 && menuTotal() < minMenuTotal)"
                    style="background: var(--primary-color); border-radius: var(--button-radius)">
                    Kirim Permintaan Reservasi
                </button>
            </form>
        </div>

    </div>

    <script>
        function reservationBooking({ availabilityUrl, initialScheduledAt, initialDuration, baseTotal, minMenuTotal, products }) {
            return {
                scheduledAt: initialScheduledAt || '',
                duration: initialDuration || 60,
                availability: { checked: false, ok: true, message: '' },
                inflight: null,
                baseTotal: Number(baseTotal || 0),
                minMenuTotal: Number(minMenuTotal || 0),

                menuOpen: false,
                menuQuery: '',
                products: Array.isArray(products) ? products : [],
                menuCart: {},

                formatRp(v) {
                    const s = String(v ?? 0);
                    return s.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
                },

                qtyById(id) {
                    const k = String(id);
                    return Number(this.menuCart?.[k] || 0) || 0;
                },

                incMenu(id) {
                    const p = this.products.find(x => Number(x.id) === Number(id));
                    if (!p) return;
                    const k = String(id);
                    let next = this.qtyById(id) + 1;
                    if (p.track_stock) {
                        const stock = Number(p.stock || 0);
                        if (stock <= 0) return;
                        next = Math.min(next, stock);
                    }
                    next = Math.min(next, 99);
                    this.menuCart[k] = next;
                },

                decMenu(id) {
                    const k = String(id);
                    const cur = this.qtyById(id);
                    const next = Math.max(0, cur - 1);
                    if (next <= 0) {
                        try { delete this.menuCart[k]; } catch (e) { this.menuCart[k] = 0; }
                    } else {
                        this.menuCart[k] = next;
                    }
                },

                clearMenu() {
                    this.menuCart = {};
                    this.menuQuery = '';
                },

                filteredProducts() {
                    const q = String(this.menuQuery || '').trim().toLowerCase();
                    const list = (this.products || []).filter(p => !!p && p.is_available);
                    if (!q) return list;
                    return list.filter(p => String(p.name || '').toLowerCase().includes(q));
                },

                menuPayload() {
                    const rows = [];
                    for (const [k, qty] of Object.entries(this.menuCart || {})) {
                        const pid = Number(k);
                        const q = Number(qty || 0);
                        if (!Number.isFinite(pid) || pid <= 0) continue;
                        if (!Number.isFinite(q) || q <= 0) continue;
                        rows.push({ product_id: pid, qty: Math.min(99, Math.max(1, Math.floor(q))) });
                    }
                    return rows;
                },

                menuTotal() {
                    let total = 0;
                    for (const row of this.menuPayload()) {
                        const p = this.products.find(x => Number(x.id) === Number(row.product_id));
                        if (!p) continue;
                        total += Number(p.price || 0) * Number(row.qty || 0);
                    }
                    return Math.max(0, Math.floor(total));
                },

                grandTotal() {
                    return Math.max(0, Math.floor((this.baseTotal || 0) + this.menuTotal()));
                },

                beforeSubmit(e) {
                    if (this.minMenuTotal > 0 && this.menuTotal() < this.minMenuTotal) {
                        try { e.preventDefault(); } catch (err) {}
                        this.menuOpen = true;
                        return false;
                    }
                    return true;
                },

                async check() {
                    if (!this.scheduledAt || !this.duration) {
                        this.availability = { checked: false, ok: true, message: '' };
                        return;
                    }

                    const params = new URLSearchParams({
                        scheduled_at: this.scheduledAt,
                        duration_minutes: String(this.duration),
                    });

                    const url = availabilityUrl + '?' + params.toString();

                    try {
                        const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
                        const data = await res.json();
                        this.availability = {
                            checked: true,
                            ok: !!data.available,
                            message: data.message || (data.available ? 'Jadwal tersedia.' : 'Jadwal tidak tersedia.'),
                        };
                    } catch (e) {
                        this.availability = { checked: false, ok: true, message: '' };
                    }
                },
                init() {
                    if (this.scheduledAt) {
                        this.check();
                    }
                }
            }
        }
    </script>
</x-customer-layout>
