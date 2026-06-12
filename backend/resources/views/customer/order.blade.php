@php
    /** @var \App\Models\DiningTable|null $table */
    /** @var \Illuminate\Support\Collection<int, \App\Models\DiningTable> $testTables */
    /** @var \Illuminate\Support\Collection<int, \App\Models\Product> $featuredPackages */
    /** @var array|null $pendingOrder */
    /** @var \App\Models\LoyaltySetting|null $loyalty */
    /** @var int $userPointsBalance */
    /** @var \App\Models\BrandSetting|null $brand */
@endphp

<x-customer-layout :showHeader="false">
    <x-slot name="headerRight">
        @if($table)
            <div class="font-medium text-slate-700">Meja</div>
            <div class="text-slate-900 font-semibold">{{ $table->name ?: $table->code }}</div>
        @else
            @if((bool)($brand?->customer_demo_mode_enabled ?? false))
                <div class="font-medium text-slate-700">Mode</div>
                <div class="text-slate-900 font-semibold">Scan QR Meja</div>
            @endif
        @endif
    </x-slot>

    <div id="home" class="scroll-mt-24"></div>

    @if(!$table)
        <div class="rounded-3xl bg-white p-6 shadow-sm border border-slate-100">
            <div class="text-xl font-semibold">Scan QR di meja untuk mulai order</div>
            @if((bool)($brand?->customer_demo_mode_enabled ?? false))
                <div class="mt-2 text-sm text-slate-600">Jika kamu admin dan mau tes manual, buka URL seperti: <span class="font-mono">/order?table=TOKEN</span></div>
            @endif

            <div class="mt-4">
                <button type="button"
                    class="rounded-2xl bg-slate-900 text-white px-5 py-3 font-semibold shadow-sm"
                    @click="openScanner()">
                    Scan QR Sekarang
                </button>
            </div>
        </div>

        @if(isset($testTables) && $testTables->count())
            <div class="mt-6 rounded-3xl bg-white p-6 shadow-sm border border-slate-100">
                <div class="text-sm font-semibold">Mode Tes (local)</div>
                <div class="mt-2 text-sm text-slate-600">Klik salah satu meja di bawah supaya tombol checkout aktif.</div>
                <div class="mt-4 flex flex-wrap gap-2">
                    @foreach($testTables as $t)
                        <a class="rounded-full bg-slate-900 text-white px-4 py-2 text-sm font-semibold"
                           href="{{ route('order.page', ['table' => $t->public_id]) }}">
                            {{ $t->name ?: $t->code }}
                        </a>
                    @endforeach
                </div>
            </div>
        @endif

        @if((bool)($brand?->customer_demo_mode_enabled ?? false))
            <div class="mt-6 rounded-3xl bg-white p-6 shadow-sm border border-slate-100">
                <div class="text-sm text-slate-600">Menu demo tetap bisa kamu lihat di bawah.</div>
            </div>
        @endif
    @endif

    <div x-data="orderPage(@js($pendingOrder), @js(rtrim(url('/'), '/')))" class="mt-6"
        :class="countItems() > 0 ? 'pb-[calc(env(safe-area-inset-bottom)+220px)]' : 'pb-10'">
        <!-- QR scanner modal (center) -->
        <div class="fixed inset-0 z-[60]" x-show="scanOpen" x-transition>
            <div class="absolute inset-0 bg-black/60" @click="closeScanner()"></div>
            <div class="relative min-h-full w-full px-4 pt-[calc(env(safe-area-inset-top)+16px)] pb-[calc(env(safe-area-inset-bottom)+16px)] flex items-center justify-center">
                <div class="w-full max-w-2xl rounded-3xl bg-white shadow-xl border border-slate-200 p-5 max-h-[85vh] overflow-auto" @click.stop>
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <div class="text-lg font-semibold">Scan QR Meja</div>
                                <div class="text-xs text-slate-500">Arahkan kamera ke QR di meja.</div>
                            </div>
                            <button type="button" class="rounded-2xl px-3 py-2 text-sm border border-slate-200" @click="closeScanner()">Tutup</button>
                        </div>

                        <div class="mt-4 grid gap-3">
                            <div class="rounded-2xl overflow-hidden border border-slate-200 bg-black aspect-[3/4] sm:aspect-video">
                                <video x-ref="scanVideo" class="w-full h-full object-cover" playsinline muted></video>
                            </div>

                            <div x-show="scanError" class="rounded-2xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800" x-text="scanError"></div>

                            <div class="flex flex-wrap gap-2">
                                <button type="button" class="rounded-2xl bg-slate-900 text-white px-5 py-3 font-semibold"
                                    @click="startScanner()">Mulai Scan</button>
                                <button type="button" class="rounded-2xl border border-slate-200 px-5 py-3 font-semibold"
                                    @click="closeScanner()">Batal</button>
                            </div>

                            <div class="text-xs text-slate-500">
                                Catatan: beberapa browser butuh klik tombol untuk mengizinkan kamera.
                            </div>
                        </div>
                    </div>
                    </div>
                </div>

        <!-- Toast (customer) -->
        <div class="fixed top-4 inset-x-0 z-[9999] flex items-center justify-center px-4" x-show="statusFlashOpen" x-transition>
            <div class="w-full max-w-md rounded-3xl border shadow-xl bg-white p-4"
                :class="statusFlashType==='error' ? 'border-rose-200' : (statusFlashType==='preparing' ? 'border-purple-200' : 'border-emerald-200')">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <div class="text-sm font-semibold"
                            :class="statusFlashType==='error' ? 'text-rose-800' : (statusFlashType==='preparing' ? 'text-purple-800' : 'text-emerald-800')"
                            x-text="statusFlashTitle"></div>
                        <div class="mt-1 text-sm text-slate-700" x-text="statusFlashMessage"></div>
                    </div>
                    <button type="button" class="text-xs text-slate-500 hover:text-slate-900" @click="statusFlashOpen=false">Tutup</button>
                </div>
                <div class="mt-3 h-1.5 rounded-full overflow-hidden bg-slate-100">
                    <div class="h-full"
                        :class="statusFlashType==='error' ? 'bg-rose-500 animate-pulse' : (statusFlashType==='preparing' ? 'bg-purple-500 animate-pulse' : 'bg-emerald-500 animate-pulse')"
                        style="width: 100%"></div>
                </div>
            </div>
        </div>

        <div id="pesanan" class="scroll-mt-24"></div>

        <div class="rounded-3xl bg-white p-6 shadow-sm border border-slate-100" x-show="tableToken && pendingOrder && isPendingStatus(pendingOrder.status)">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <div class="text-xs text-slate-500">Pesanan kamu (Meja <span class="font-semibold" x-text="tableLabel"></span>)</div>
                    <div class="text-lg font-semibold" x-text="pendingOrder?.order_number || '-' "></div>
                    <div class="mt-1 text-xs text-slate-500" x-show="pendingOrder?.created_at" x-text="formatDateTime(pendingOrder?.created_at)"></div>
                </div>
                <span class="px-3 py-1 rounded-full text-xs font-semibold border" :class="statusClass(pendingOrder?.status)" x-text="pendingOrder?.status"></span>
            </div>

            <!-- Friendly status message + animation -->
            <div class="mt-4 rounded-2xl border p-4"
                x-show="pendingOrder && (pendingOrder.status==='preparing' || pendingOrder.status==='prepared' || pendingOrder.status==='completed')"
                :class="pendingOrder?.status==='preparing' ? 'border-purple-200 bg-purple-50' : (pendingOrder?.status==='prepared' ? 'border-orange-200 bg-orange-50' : 'border-emerald-200 bg-emerald-50')">
                <div class="flex items-start gap-3">
                    <div class="h-10 w-10 rounded-2xl grid place-items-center"
                        :class="pendingOrder?.status==='preparing' ? 'bg-purple-600 text-white' : (pendingOrder?.status==='prepared' ? 'bg-orange-600 text-white' : 'bg-emerald-600 text-white')">
                        <span class="text-lg" x-text="pendingOrder?.status==='preparing' ? '⏳' : (pendingOrder?.status==='prepared' ? '🍽️' : '✅')"></span>
                    </div>
                    <div>
                        <div class="font-semibold"
                            :class="pendingOrder?.status==='preparing' ? 'text-purple-900' : (pendingOrder?.status==='prepared' ? 'text-orange-900' : 'text-emerald-900')"
                            x-text="pendingOrder?.status==='preparing' ? 'Yeyay pesananmu sedang disiapkan ya, ditunggu ya' : (pendingOrder?.status==='prepared' ? 'Pesananmu sudah siap disajikan!' : 'Yeay, kasir baik kami akan anter pesanan ke meja kamu.')"></div>
                        <div class="mt-1 text-sm"
                            :class="pendingOrder?.status==='preparing' ? 'text-purple-800' : (pendingOrder?.status==='prepared' ? 'text-orange-800' : 'text-emerald-800')"
                            x-text="pendingOrder?.status==='preparing' ? 'Kamu bisa tunggu sambil lihat rincian pesanan di bawah.' : (pendingOrder?.status==='prepared' ? 'Tunggu sebentar ya, pesanan segera diantar ke meja kamu.' : 'Tunggu sebentar ya, pesanan segera diantar.')"></div>
                        <div class="mt-2 text-xs text-slate-600" x-show="pendingOrder?.status==='completed'">Info ini akan hilang otomatis dalam ±5 menit.</div>
                    </div>
                </div>
                <div class="mt-3 h-1.5 rounded-full overflow-hidden bg-white/60">
                    <div class="h-full"
                        :class="pendingOrder?.status==='preparing' ? 'bg-purple-500 animate-pulse' : (pendingOrder?.status==='prepared' ? 'bg-orange-500 animate-pulse' : 'bg-emerald-500 animate-pulse')"
                        style="width: 100%"></div>
                </div>
            </div>

            <div class="mt-4 grid gap-2">
                <template x-for="it in (pendingOrder?.items || [])" :key="it.product_name + '_' + it.qty">
                    <div class="rounded-2xl border border-slate-100 bg-slate-50 p-3 flex items-start justify-between gap-3">
                        <div>
                            <div class="font-medium" x-text="it.product_name"></div>
                            <div class="text-xs text-slate-500">Rp <span x-text="formatRp(it.unit_price)"></span> × <span x-text="it.qty"></span></div>
                            <template x-if="(it.options || []).length">
                                <div class="mt-1 text-xs text-slate-600" x-text="(it.options || []).map(o => o.option_name + ': ' + o.value_name).join(' · ')"></div>
                            </template>
                        </div>
                        <div class="font-semibold">Rp <span x-text="formatRp(it.line_total)"></span></div>
                    </div>
                </template>
            </div>

            <div class="mt-4 flex items-center justify-between">
                <div class="text-sm text-slate-600">Total: <span class="font-semibold text-slate-900">Rp <span x-text="formatRp(pendingOrder?.total_amount || 0)"></span></span></div>
                <button type="button" class="text-xs text-slate-600 hover:text-slate-900 underline" @click="refreshPendingOrder()">Refresh</button>
            </div>
        </div>

        <div id="promo" class="scroll-mt-24"></div>

        @if(isset($featuredPackages) && $featuredPackages->count())
            <div class="mt-6">
                <div class="flex items-end justify-between gap-3">
                    <div>
                        <div class="text-lg font-semibold">Paket Spesial</div>
                        <div class="text-sm text-slate-600">Lebih hemat untuk hari spesial / event.</div>
                    </div>
                </div>

                <div class="mt-3 flex gap-3 overflow-auto pb-2">
                    @foreach($featuredPackages as $pkg)
                        @php
                            $normalPrice = (int) $pkg->packageItems->sum(fn ($pi) => (int) $pi->qty * (int) ($pi->itemProduct?->price ?? 0));
                            $packagePrice = (int) $pkg->price;
                            $savings = max(0, $normalPrice - $packagePrice);

                            $thumbUrl = $pkg->imageUrl();

                            $pkgPayload = [
                                'id' => $pkg->id,
                                'name' => $pkg->name,
                                'price' => (int) $pkg->price,
                                'normal_price' => $normalPrice,
                                'savings' => $savings,
                                'is_package' => (bool) $pkg->is_package,
                                'track_stock' => (bool) $pkg->track_stock,
                                'stock' => (int) ($pkg->stock ?? 0),
                                'description' => $pkg->description,
                                'package_items' => $pkg->packageItems
                                    ->map(fn ($pi) => [
                                        'product_id' => (int) $pi->item_product_id,
                                        'name' => (string) ($pi->itemProduct?->name ?? 'Item'),
                                        'qty' => (int) $pi->qty,
                                        'unit_price' => (int) ($pi->itemProduct?->price ?? 0),
                                    ])->values(),
                                'options' => $pkg->options
                                    ->map(fn ($o) => [
                                        'id' => $o->id,
                                        'name' => $o->name,
                                        'type' => $o->type,
                                        'is_required' => (bool) $o->is_required,
                                        'values' => $o->values
                                            ->map(fn ($v) => [
                                                'id' => $v->id,
                                                'name' => $v->name,
                                                'price_delta' => (int) $v->price_delta,
                                            ])->values(),
                                    ])->values(),
                            ];
                        @endphp

                        <button type="button"
                            class="min-w-[260px] rounded-3xl overflow-hidden border border-slate-200 bg-white shadow-sm text-left"
                            @click="openPackageModal(@js($pkgPayload))">
                            <div class="relative p-4 bg-gradient-to-br from-indigo-50 to-emerald-50 {{ $savings > 0 ? 'pr-24' : '' }}">
                                @if($savings > 0)
                                    <div class="absolute top-3 right-3">
                                        <div class="rounded-full bg-emerald-600 text-white px-3 py-1 text-[11px] font-extrabold shadow-sm">
                                            HEMAT Rp {{ number_format($savings, 0, ',', '.') }}
                                        </div>
                                    </div>
                                @endif
                                <div class="flex items-start gap-3">
                                    <!-- Thumbnail (left) -->
                                    <div class="h-16 w-16 shrink-0 rounded-2xl overflow-hidden border border-white/60 bg-white/70">
                                        @if($thumbUrl)
                                            <img src="{{ $thumbUrl }}" alt="{{ $pkg->name }}" class="h-full w-full object-cover" loading="lazy" />
                                        @else
                                            <div class="h-full w-full grid place-items-center">
                                                <div class="h-10 w-10 rounded-2xl grid place-items-center text-white font-black" style="background: var(--primary-color)">P</div>
                                            </div>
                                        @endif
                                    </div>

                                    <!-- Text (right) -->
                                    <div class="min-w-0 flex-1">
                                        <div class="text-xs font-semibold text-indigo-700">PAKET</div>
                                        <div class="mt-1 font-semibold leading-tight line-clamp-2">{{ $pkg->banner_title ?: $pkg->name }}</div>
                                        @if($pkg->banner_subtitle)
                                            <div class="mt-1 text-xs text-slate-600 line-clamp-2">{{ $pkg->banner_subtitle }}</div>
                                        @endif
                                    </div>
                                </div>
                                <div class="mt-3 text-xs text-slate-700">
                                    @if($pkg->packageItems->count())
                                        Isi: {{ $pkg->packageItems->map(fn($pi) => ((int)$pi->qty).'× '.($pi->itemProduct?->name ?? 'Item'))->join(' · ') }}
                                    @else
                                        Klik untuk lihat detail paket.
                                    @endif
                                </div>
                            </div>
                            <div class="p-4 flex items-center justify-between gap-3">
                                <div class="min-w-0 flex items-center gap-2">
                                    <div class="shrink-0 text-sm font-extrabold text-slate-900">Rp {{ number_format((int) $pkg->price, 0, ',', '.') }}</div>
                                    @if($savings > 0)
                                        <div class="shrink-0 text-xs text-slate-500 line-through">Rp {{ number_format($normalPrice, 0, ',', '.') }}</div>
                                    @endif
                                    <div class="min-w-0 text-sm text-slate-600 truncate">· Klik untuk pesan</div>
                                </div>

                                <div class="shrink-0 flex items-center gap-2">
                                    @if($pkg->track_stock)
                                        @if((int) ($pkg->stock ?? 0) > 0)
                                            <span class="px-2 py-1 rounded-full text-[11px] font-semibold border border-emerald-200 bg-emerald-50 text-emerald-700">Stok: {{ (int) ($pkg->stock ?? 0) }}</span>
                                        @else
                                            <span class="px-2 py-1 rounded-full text-[11px] font-semibold border border-rose-200 bg-rose-50 text-rose-700">Habis</span>
                                        @endif
                                    @endif
                                    <div class="text-sm font-semibold text-slate-900">Detail →</div>
                                </div>
                            </div>
                        </button>
                    @endforeach
                </div>
            </div>
        @endif

        <div id="menu" class="scroll-mt-24"></div>

        @php
            $heroCategories = ($categories ?? collect())->values();
            $heroData = $heroCategories->map(function ($c) {
                $allProducts = ($c->products ?? collect());

                // Featured selection priority:
                // 1) show_as_banner (admin-curated; usually for paket)
                // 2) has image
                // 3) first product
                $featured = $allProducts->firstWhere('show_as_banner', true);
                if (!$featured) {
                    $featured = $allProducts->first(fn ($p) => !empty($p->image_path));
                }
                if (!$featured) {
                    $featured = $allProducts->first();
                }

                $products = collect();
                if ($featured) {
                    $products->push($featured);
                }
                $products = $products
                    ->merge($allProducts->filter(fn ($p) => !$featured || (int) $p->id !== (int) $featured->id))
                    ->take(12)
                    ->values();

                return [
                    'id' => (string) $c->id,
                    'name' => (string) ($c->name ?? 'Kategori'),
                    'featured_product_id' => $featured ? (int) $featured->id : null,
                    'products' => $products->map(function ($p) use ($c) {
                        $normalPrice = (int) ($p->packageItems?->sum(fn ($pi) => (int) $pi->qty * (int) ($pi->itemProduct?->price ?? 0)) ?? 0);
                        $packagePrice = (int) ($p->price ?? 0);
                        $savings = max(0, $normalPrice - $packagePrice);

                        return [
                            'id' => (int) $p->id,
                            'name' => (string) ($p->name ?? 'Menu'),
                            'price' => (int) ($p->price ?? 0),
                            'normal_price' => $normalPrice,
                            'savings' => $savings,
                            'show_as_banner' => (bool) ($p->show_as_banner ?? false),
                            'is_package' => (bool) ($p->is_package ?? false),
                            'track_stock' => (bool) ($p->track_stock ?? false),
                            'stock' => (int) ($p->stock ?? 0),
                            'description' => (string) ($p->description ?? ''),
                            'category_id' => (string) ($c->id ?? ''),
                            'category_name' => (string) ($c->name ?? ''),
                            'image_url' => $p->imageUrl(),
                            'package_items' => ($p->packageItems ?? collect())
                                ->map(fn ($pi) => [
                                    'product_id' => (int) $pi->item_product_id,
                                    'name' => (string) ($pi->itemProduct?->name ?? 'Item'),
                                    'qty' => (int) $pi->qty,
                                    'unit_price' => (int) ($pi->itemProduct?->price ?? 0),
                                ])->values(),
                            'options' => ($p->options ?? collect())
                                ->map(fn ($o) => [
                                    'id' => $o->id,
                                    'name' => $o->name,
                                    'type' => $o->type,
                                    'is_required' => (bool) $o->is_required,
                                    'values' => ($o->values ?? collect())
                                        ->map(fn ($v) => [
                                            'id' => $v->id,
                                            'name' => $v->name,
                                            'price_delta' => (int) $v->price_delta,
                                        ])->values(),
                                ])->values(),
                        ];
                    })->values(),
                ];
            })->values();

            $heroHasData = $heroData->count() > 0;
        @endphp

        @if($heroHasData)
            <section class="mt-6">
                <div class="relative overflow-hidden rounded-[34px] border border-slate-200 bg-gradient-to-br from-[#19110b] via-[#14100c] to-[#0b0908] text-white shadow-[0_30px_80px_-60px_rgba(0,0,0,0.75)]">
                    <!-- Brand-tinted glow (dynamic) + dark overlay to keep white text readable -->
                    <div class="absolute inset-0 opacity-25" style="background:
                        radial-gradient(1200px circle at 12% 18%, var(--primary-color), transparent 60%),
                        radial-gradient(900px circle at 92% 16%, var(--accent-color), transparent 58%),
                        radial-gradient(900px circle at 55% 92%, #6f4e37, transparent 62%)"></div>
                    <div class="absolute inset-0 bg-black/35"></div>
                    <div class="absolute -left-8 -bottom-10 h-52 w-52 rounded-full bg-white/10 blur-2xl"></div>
                    <div class="absolute -right-10 -top-12 h-64 w-64 rounded-full bg-emerald-500/15 blur-3xl"></div>

                    <div class="relative p-5 sm:p-8">
                        <div class="flex items-center justify-between gap-3">
                            <div class="text-sm font-semibold tracking-wide text-white/90">Menu Pilihan</div>
                            <div class="text-[11px] text-white/70">Pilih kategori di sisi gambar</div>
                        </div>

                        <!-- Hero content -->
                        <div class="mt-6 grid gap-6 lg:grid-cols-[1.05fr_0.95fr] items-center">
                            <div>
                                <div>
                                    <div class="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/10 px-3 py-1 text-[11px] font-semibold text-white/80">
                                        <span class="h-2 w-2 rounded-full" style="background: var(--accent-color)"></span>
                                        <span x-text="heroP().category_name || 'Menu'"></span>
                                    </div>

                                    <div class="mt-4 text-2xl sm:text-3xl font-extrabold leading-tight" x-text="heroP().name"></div>
                                    <div class="mt-2 text-sm text-white/75 max-w-xl" x-text="(heroP().description || 'Rasa yang pas, dibuat fresh, dan cocok untuk temani momen kamu.')"></div>

                                    <div class="mt-5 flex items-center gap-4">
                                        <div class="rounded-2xl border border-white/15 bg-white/10 px-4 py-3">
                                            <div class="text-[11px] text-white/70">Harga Untukmu</div>
                                            <div class="text-lg font-extrabold">Rp <span x-text="formatRp(heroP().price || 0)"></span></div>
                                        </div>

                                        <button type="button"
                                            class="inline-flex items-center gap-3 rounded-full text-white px-3 py-2 shadow-lg hover:scale-105 transition-transform"
                                            style="background: var(--accent-color);"
                                            @click="heroOrderNow()">
                                            <span class="h-10 w-10 rounded-full grid place-items-center bg-white/10">
                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" class="h-6 w-6 text-white" stroke-width="1.5">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 3h2l.4 2M7 13h10l4-8H5.4" />
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M7 21a1 1 0 100-2 1 1 0 000 2zm10 0a1 1 0 100-2 1 1 0 000 2z" />
                                                </svg>
                                            </span>
                                            <span class="text-sm font-semibold">Checkout</span>
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div class="relative">
                                <div class="absolute inset-0 rounded-[28px] bg-white/10 blur-xl"></div>
                                <div class="relative rounded-[28px] border border-white/15 bg-white/10 p-4 backdrop-blur">
                                    <!-- Vertical category rail (beside main image, rising up) -->

                                    <div class="[perspective:1100px]">
                                        <div class="relative aspect-[4/3] overflow-hidden rounded-3xl will-change-transform"
                                            x-show="heroImageShow"
                                            x-transition:enter="transition duration-[820ms] ease-[cubic-bezier(0.34,1.56,0.64,1)]"
                                            x-transition:enter-start="opacity-0 blur-sm [transform:perspective(1100px)_translateY(48px)_rotateX(14deg)_rotateZ(2deg)_scale(0.97)]"
                                            x-transition:enter-end="opacity-100 blur-0 [transform:perspective(1100px)_translateY(0)_rotateX(0deg)_rotateZ(0deg)_scale(1)]"
                                            x-transition:leave="transition duration-[280ms] ease-in"
                                            x-transition:leave-start="opacity-100 blur-0 [transform:perspective(1100px)_translateY(0)_rotateX(0deg)_rotateZ(0deg)_scale(1)]"
                                            x-transition:leave-end="opacity-0 blur-sm [transform:perspective(1100px)_translateY(-48px)_rotateX(-10deg)_rotateZ(-2deg)_scale(0.97)]"
                                            x-cloak>
                                            <div class="absolute inset-0 bg-[radial-gradient(circle_at_30%_30%,rgba(111,78,55,0.25),transparent_55%),radial-gradient(circle_at_70%_60%,rgba(255,255,255,0.10),transparent_55%)]"></div>
                                            <template x-if="heroP().image_url">
                                                <img :src="heroP().image_url" :alt="heroP().name" class="absolute inset-0 h-full w-full object-cover" loading="lazy" />
                                            </template>
                                            <template x-if="!heroP().image_url">
                                                <div class="absolute inset-0 grid place-items-center">
                                                    <div class="h-28 w-28 rounded-[30px] border border-white/15 bg-white/10 grid place-items-center text-4xl font-black">
                                                        <span x-text="String(heroP().name || 'M').trim().slice(0,1).toUpperCase()"></span>
                                                    </div>
                                                </div>
                                            </template>

                                            <div class="absolute -right-6 -top-8 h-32 w-32 rounded-full bg-emerald-400/15 blur-2xl"></div>
                                            <div class="absolute -left-10 -bottom-10 h-40 w-40 rounded-full bg-white/10 blur-2xl"></div>
                                        </div>
                                    </div>

                                    <!-- Vertical Focus Spinner (center-right, Alpine-powered) -->
                                    <div id="vspinner" class="absolute z-[200] -translate-y-1/2 pointer-events" style="top:50%; right:20px;" x-data="{ base: @js($categories->map(fn($c) => ['id' => (string)$c->id, 'name' => (string)$c->name])->toArray()), itemH:48, idx: 0, get looped(){ return [...this.base, ...this.base, ...this.base]; }, get baseLen(){ return this.base.length; }, init(){ this.idx = this.baseLen; this.setActive(); }, next(){ this.idx++; this.fix(); this.setActive(); }, prev(){ this.idx--; this.fix(); this.setActive(); }, fix(){ if(this.idx >= this.baseLen * 2){ this.idx = this.baseLen; } if(this.idx < this.baseLen){ this.idx = this.baseLen * 2 - 1; } }, setActive(){ const realIndex = this.idx % this.baseLen; const id = this.base[realIndex]?.id; if(id) heroSetCategory(String(id)); } }" x-init="$el.addEventListener('mouseenter', ()=>{ /* no auto-spin */ }); window.addEventListener('resize', ()=>{ /* keep idx centered */ });">
                                        <style>
                                            #vspinner .stage{ width:15px; height:25px; position:relative; overflow:hidden; }
                    
                                            #vspinner .stage .list{ position:absolute; left:0; top:0; width:100%; transition:transform 700ms cubic-bezier(.22,.9,.36,1); }
                                            #vspinner .stage .item{ position:absolute; left:0; right:0; height:48px; display:flex; align-items:center; padding-left:56px; box-sizing:border-box; font-weight:700; white-space:normal; overflow:visible; transform-origin:center; transition: all 420ms ease; filter:blur(0.5px) saturate(.9); transform:scale(.92); }
                                            #vspinner .stage .item .text{ font-size:8px; background: rgba(255,255,255,0.5); padding:6px 8px; border-radius:8px; display:inline-block; max-width:180px; word-break:break-word; pointer-events:none; }
                                            #vspinner .stage .item:not(.active) .text{ color: rgba(0,0,0,0.85); }
                                            #vspinner .stage .item.active{ filter:blur(0) saturate(1.05); transform:scale(1.04); }
                                            #vspinner .stage .item.active .text{ font-size:11px; background: var(--accent-color); color:#0f172a; }
                                            #vspinner .viewport{ width:140px; margin-left:56px; position:relative; }

                                            #vspinner .spinner-core {
                                                position: relative;
                                                width: 196px;
                                                height: 220px;
                                            }

                                            #vspinner .nav {
                                                position: absolute;
                                                left: 70%;
                                                transform: translateX(-50%);
                                                width: 32px;
                                                height: 32px;
                                                border-radius: 9999px;
                                                display: grid;
                                                place-items: center;
                                            }

                                            #vspinner .nav.up {
                                                top: -44px;
                                            }

                                            #vspinner .nav.down {
                                                bottom: -44px;
                                            }
                                        </style>

                                        <div class="spinner-core relative">
                                            <!-- Up Button -->
                                            <button type="button" class="nav up h-8 w-8 rounded-full bg-white/10 text-white/90 grid place-items-center shadow-sm hover:bg-white/15 transition-colors" @click.stop="prev()" aria-label="Kategori sebelumnya">
                                                ▲
                                            </button>

                                            <div class="stage" style="width:196px; height:220px;">
                                                <div class="pointer" aria-hidden="true"></div>
                                                <div class="viewport">
                                                    <div class="list" :style="'transform: translateY(' + (110 - 24 - (idx * itemH)) + 'px);'">
                                                        <template x-for="(cat, i) in looped" :key="'spin_'+i">
                                                            <button type="button" class="item" :class="{ 'active': idx===i }"
                                                                :style="'top:' + (i * itemH) + 'px; z-index:' + (100 - Math.abs(i-idx))"
                                                                @click.stop="idx = i; setActive()">
                                                                <div class="text" x-text="cat.name"></div>
                                                            </button>
                                                        </template>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Down Button -->
                                            <button type="button" class="nav down h-8 w-8 rounded-full bg-white/10 text-white/90 grid place-items-center shadow-sm hover:bg-white/15 transition-colors" @click.stop="next()" aria-label="Kategori berikutnya">
                                                ▼
                                            </button>
                                        </div>

                                    </div>

                                </div>
                            </div>
                        </div>

                        <!-- Product slider (scrollable if many) -->
                        <div class="mt-6">
                            <div class="flex items-center justify-between">
                                <div class="text-[11px] text-white/70">Rekomendasi di kategori ini</div>
                                <div class="text-[11px] text-white/55">Geser →</div>
                            </div>
                            <div class="mt-2 flex flex-nowrap items-start gap-2 overflow-x-auto overscroll-x-contain pb-2 snap-x snap-mandatory scroll-pl-2 [-ms-overflow-style:none] [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                                <template x-for="p in heroSliderCards()" :key="'slider_'+String(p.id)">
                                    <button type="button"
                                        class="flex-none w-24 sm:w-28 snap-start text-left rounded-3xl border backdrop-blur p-2 shadow-sm transition"
                                        :class="Number(heroProductId)===Number(p.id) ? 'border-white bg-white/15' : 'border-white/15 bg-white/10 hover:bg-white/15'"
                                        @click="heroPickProduct(Number(p.id))">
                                        <div class="h-16 sm:h-20 rounded-2xl overflow-hidden border border-white/10 bg-white/5">
                                            <template x-if="p.image_url">
                                                <img :src="p.image_url" :alt="p.name" class="h-full w-full object-cover" loading="lazy" />
                                            </template>
                                            <template x-if="!p.image_url">
                                                <div class="h-full w-full grid place-items-center text-white/50 text-[10px]">No Image</div>
                                            </template>
                                        </div>
                                        <div class="mt-2">
                                            <div class="text-[11px] font-extrabold leading-tight line-clamp-2" x-text="p.name"></div>
                                            <div class="mt-1 flex items-center justify-between gap-2">
                                                <div class="text-[10px] text-white/70">Rp <span x-text="formatRp(p.price||0)"></span></div>
                                                <button type="button"
                                                    class="h-7 w-7 rounded-2xl grid place-items-center font-black text-slate-900 shadow-sm"
                                                    style="background: var(--accent-color)"
                                                    @click.stop="addProduct(p)">
                                                    +
                                                </button>
                                            </div>
                                        </div>
                                    </button>
                                </template>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        @endif

        <div class="sticky top-[calc(env(safe-area-inset-top)+72px)] z-30 -mx-4 px-4 pt-2 pb-3 bg-[color:var(--background-color)]/90 backdrop-blur supports-[backdrop-filter]:bg-[color:var(--background-color)]/70"
            @keydown.escape.window="searchOpen=false">
            <div class="rounded-3xl border border-slate-200/70 bg-white/70 backdrop-blur shadow-[0_18px_35px_-28px_rgba(0,0,0,0.35)] px-3 pt-3 pb-2">
                <!-- Compact header row + toggle search -->
                <div class="flex items-center justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2 min-w-0">
                            <div class="text-sm font-semibold leading-tight">Menu</div>
                            <button type="button"
                                class="inline-flex max-w-[70vw] items-center gap-2 rounded-full border border-slate-200 bg-white/90 px-3 py-1 text-[11px] font-semibold text-slate-700 shadow-sm"
                                @click="$nextTick(() => { try { $refs?.categoryDialViewport?.scrollIntoView?.({ behavior: 'smooth', block: 'nearest' }); } catch(e) {} })"
                                aria-label="Kategori aktif">
                                <span class="h-2 w-2 rounded-full" style="background: var(--accent-color)"></span>
                                <span class="truncate" x-text="activeCategoryLabel()"></span>
                            </button>
                        </div>

                        <div class="mt-1 text-[11px] text-slate-500 truncate" x-show="(searchQuery || '').trim().length > 0" x-transition>
                            Hasil: <span class="font-semibold" x-text="searchQuery"></span>
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <button type="button"
                            class="h-10 w-10 rounded-2xl border border-slate-200 bg-white/90 backdrop-blur shadow-sm grid place-items-center"
                            :class="searchOpen ? 'ring-2 ring-emerald-500/25' : ''"
                            @click="searchOpen = !searchOpen; if (searchOpen) { $nextTick(() => { try { $refs?.searchInput?.focus?.(); } catch(e) {} }) }"
                            :aria-expanded="searchOpen ? 'true' : 'false'"
                            aria-label="Cari menu">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-5 w-5 text-slate-700">
                                <path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 1 0 0 11 5.5 5.5 0 0 0 0-11ZM2 9a7 7 0 1 1 12.76 4.29l2.47 2.47a.75.75 0 1 1-1.06 1.06l-2.47-2.47A7 7 0 0 1 2 9Z" clip-rule="evenodd" />
                            </svg>
                        </button>

                        <button type="button"
                            class="h-10 w-10 rounded-2xl border border-slate-200 bg-white/90 backdrop-blur shadow-sm grid place-items-center text-slate-600"
                            x-show="(searchQuery || '').trim().length > 0"
                            @click="searchQuery=''; searchOpen=false"
                            x-transition
                            aria-label="Hapus pencarian">
                            ✕
                        </button>
                    </div>
                </div>

                <div class="mt-2" x-show="searchOpen" x-transition @click.outside="searchOpen=false">
                    <div class="relative">
                        <div class="pointer-events-none absolute inset-y-0 left-0 pl-4 flex items-center text-slate-400">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-5 w-5">
                                <path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 1 0 0 11 5.5 5.5 0 0 0 0-11ZM2 9a7 7 0 1 1 12.76 4.29l2.47 2.47a.75.75 0 1 1-1.06 1.06l-2.47-2.47A7 7 0 0 1 2 9Z" clip-rule="evenodd" />
                            </svg>
                        </div>
                        <input type="search"
                            x-ref="searchInput"
                            x-model.debounce.250ms="searchQuery"
                            class="w-full rounded-3xl border border-slate-200 bg-white px-12 py-2.5 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-emerald-500/40"
                            placeholder="Cari menu… (mie, kopi, es)" />
                    </div>
                </div>

            <!-- Mobile category tabs (compact, like reference) -->
            <div class="md:hidden mt-3">
                <div class="relative">
                    <div class="pointer-events-none absolute inset-y-0 left-0 w-10 bg-gradient-to-r from-white/80 to-transparent"></div>
                    <div class="pointer-events-none absolute inset-y-0 right-0 w-10 bg-gradient-to-l from-white/80 to-transparent"></div>

                    <div class="flex gap-2 overflow-x-auto overscroll-x-contain whitespace-nowrap pb-1 scroll-smooth snap-x snap-mandatory scroll-px-3 [-ms-overflow-style:none] [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                        <button type="button"
                            class="flex-none snap-start rounded-full px-4 py-2 text-xs font-extrabold border transition active:scale-[0.98]"
                            :class="activeCategory === 'all' ? 'text-white border-transparent' : 'bg-white text-slate-700 border-slate-200'"
                            :style="activeCategory === 'all' ? ('background: var(--accent-color)') : ''"
                            @click="setCategory('all', $event.currentTarget)">
                            Semua
                        </button>

                        @foreach($categories as $category)
                            <button type="button"
                                class="flex-none snap-start rounded-full px-4 py-2 text-xs font-extrabold border transition active:scale-[0.98]"
                                :class="activeCategory === '{{ $category->id }}' ? 'text-white border-transparent' : 'bg-white text-slate-700 border-slate-200'"
                                :style="activeCategory === '{{ $category->id }}' ? ('background: var(--accent-color)') : ''"
                                @click="setCategory('{{ $category->id }}', $event.currentTarget)">
                                {{ $category->name }}
                            </button>
                        @endforeach
                    </div>
                </div>
            </div>

            <!-- Horizontal category chips (md+) as fallback -->
            <div class="relative hidden md:block">
                <!-- Premium fade edges (visual indicator that it's scrollable) -->
                <div class="pointer-events-none absolute inset-y-0 left-0 z-[1] w-12 bg-gradient-to-r from-[color:var(--background-color)]/95 to-transparent"></div>
                <div class="pointer-events-none absolute inset-y-0 right-0 z-[1] w-12 bg-gradient-to-l from-[color:var(--background-color)]/95 to-transparent"></div>

                <!-- Desktop arrows -->
                <button type="button" class="hidden md:grid absolute left-1 top-1/2 z-[2] -translate-y-1/2 h-9 w-9 rounded-2xl border border-slate-200 bg-white/90 backdrop-blur shadow-sm place-items-center"
                    @click="scrollCategories(-260)" aria-label="Geser kategori ke kiri">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-5 w-5 text-slate-700">
                        <path fill-rule="evenodd" d="M12.79 15.77a.75.75 0 0 1-1.06-.02L7.23 11a.75.75 0 0 1 0-1l4.5-4.75a.75.75 0 1 1 1.08 1.04L8.86 10l3.95 4.73a.75.75 0 0 1-.02 1.04z" clip-rule="evenodd" />
                    </svg>
                </button>
                <button type="button" class="hidden md:grid absolute right-1 top-1/2 z-[2] -translate-y-1/2 h-9 w-9 rounded-2xl border border-slate-200 bg-white/90 backdrop-blur shadow-sm place-items-center"
                    @click="scrollCategories(260)" aria-label="Geser kategori ke kanan">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-5 w-5 text-slate-700">
                        <path fill-rule="evenodd" d="M7.21 4.23a.75.75 0 0 1 1.06.02l4.5 4.75a.75.75 0 0 1 0 1l-4.5 4.75a.75.75 0 1 1-1.08-1.04L11.14 10 7.19 5.27a.75.75 0 0 1 .02-1.04z" clip-rule="evenodd" />
                    </svg>
                </button>

                <div x-ref="categoryScroller"
                    class="flex flex-nowrap gap-2 overflow-x-scroll overflow-y-hidden w-full max-w-full select-none touch-pan-x overscroll-x-contain whitespace-nowrap pb-1 pl-12 pr-12 scroll-smooth snap-x snap-mandatory scroll-px-12 [-ms-overflow-style:none] [scrollbar-width:none] [&::-webkit-scrollbar]:hidden [-webkit-overflow-scrolling:touch]">

                    <button type="button" class="flex-none snap-start rounded-full px-4 py-2 text-sm shadow-sm border transition-colors duration-200 ease-out active:scale-[0.98]"
                        :class="activeCategory === 'all' ? 'bg-slate-900 text-white border-slate-900' : 'bg-white border-slate-200 hover:bg-slate-50'"
                        @click="setCategory('all', $event.currentTarget)">
                        Semua
                    </button>
                    @foreach($categories as $category)
                        <button type="button" class="flex-none snap-start rounded-full px-4 py-2 text-sm shadow-sm border transition-colors duration-200 ease-out active:scale-[0.98]"
                            :class="activeCategory === '{{ $category->id }}' ? 'bg-slate-900 text-white border-slate-900' : 'bg-white border-slate-200 hover:bg-slate-50'"
                            @click="setCategory('{{ $category->id }}', $event.currentTarget)">
                            {{ $category->name }}
                        </button>
                    @endforeach
                </div>
            </div>
            </div>
        </div>

        <div class="mt-4 grid grid-cols-2 gap-3 md:gap-4">
            @foreach($categories as $category)
                @foreach($category->products as $product)
                    @php
                        $normalPrice = (int) ($product->packageItems?->sum(fn ($pi) => (int) $pi->qty * (int) ($pi->itemProduct?->price ?? 0)) ?? 0);
                        $packagePrice = (int) ($product->price ?? 0);
                        $savings = max(0, $normalPrice - $packagePrice);

                        $productPayload = [
                            'id' => $product->id,
                            'name' => $product->name,
                            'price' => (int) $product->price,
                            'normal_price' => $normalPrice,
                            'savings' => $savings,
                            'is_package' => (bool) $product->is_package,
                            'track_stock' => (bool) $product->track_stock,
                            'stock' => (int) ($product->stock ?? 0),
                            'description' => $product->description,
                            'package_items' => ($product->packageItems ?? collect())
                                ->map(fn ($pi) => [
                                    'product_id' => (int) $pi->item_product_id,
                                    'name' => (string) ($pi->itemProduct?->name ?? 'Item'),
                                    'qty' => (int) $pi->qty,
                                    'unit_price' => (int) ($pi->itemProduct?->price ?? 0),
                                ])->values(),
                            'options' => ($product->options ?? collect())
                                ->map(fn ($o) => [
                                    'id' => $o->id,
                                    'name' => $o->name,
                                    'type' => $o->type,
                                    'is_required' => (bool) $o->is_required,
                                    'values' => ($o->values ?? collect())
                                        ->map(fn ($v) => [
                                            'id' => $v->id,
                                            'name' => $v->name,
                                            'price_delta' => (int) $v->price_delta,
                                        ])->values(),
                                ])->values(),
                        ];
                    @endphp

                    <div class="rounded-3xl bg-white border border-slate-100 shadow-sm overflow-hidden"
                        x-show="(activeCategory==='all' || activeCategory==='{{ $category->id }}') && matchesSearch(@js(trim(($product->name ?? '').' '.($product->description ?? '').' '.($category->name ?? ''))))"
                        x-transition>

                        <!-- Product Image (Prominent) -->
                        <div class="aspect-square bg-slate-100 relative overflow-hidden">
                            @if($product->imageUrl())
                                <img
                                    src="{{ $product->imageUrl() }}"
                                    alt="{{ $product->name }}"
                                    class="h-full w-full object-cover"
                                    loading="lazy"
                                />
                            @else
                                <div class="h-full w-full grid place-items-center text-slate-400 text-sm">No Image</div>
                            @endif

                            <!-- Stock Badge -->
                            @if($product->track_stock)
                                <div class="absolute top-2 right-2">
                                    <span class="inline-flex items-center rounded-full px-2 py-1 text-xs font-extrabold border"
                                        :class="(int)($product->stock ?? 0) > 0 ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-rose-200 bg-rose-50 text-rose-700'">
                                        {{ (int)($product->stock ?? 0) > 0 ? 'Stok '.(int)($product->stock ?? 0) : 'Habis' }}
                                    </span>
                                </div>
                            @endif

                            <!-- Package Badge -->
                            @if($product->is_package && $savings > 0)
                                <div class="absolute top-2 left-2">
                                    <span class="inline-flex items-center rounded-full bg-indigo-600 text-white px-2 py-1 text-xs font-extrabold">
                                        PAKET HEMAT
                                    </span>
                                </div>
                            @endif
                        </div>

                        <!-- Product Info -->
                        <div class="p-4">
                            <div class="flex items-start justify-between gap-2 mb-2">
                                <div class="min-w-0 flex-1">
                                    <div class="text-sm font-semibold leading-tight line-clamp-2">{{ $product->name }}</div>
                                    <div class="text-xs text-slate-500 mt-1">{{ $category->name }}</div>
                                </div>
                            </div>

                            <!-- Price -->
                            <div class="mb-3">
                                @if($product->is_package && $savings > 0)
                                    <div class="text-xs text-slate-500 line-through">Rp {{ number_format($normalPrice, 0, ',', '.') }}</div>
                                    <div class="text-lg font-extrabold text-slate-900">Rp <span x-text="formatRp({{ (int) $product->price }})"></span></div>
                                    <div class="text-xs font-semibold text-emerald-700">Hemat Rp {{ number_format($savings, 0, ',', '.') }}</div>
                                @else
                                    <div class="text-lg font-extrabold text-slate-900">Rp <span x-text="formatRp({{ (int) $product->price }})"></span></div>
                                @endif
                            </div>

                            <!-- Description -->
                            @if($product->description)
                                <div class="text-xs text-slate-600 line-clamp-2 mb-3">{{ $product->description }}</div>
                            @endif

                            <!-- Quantity Input -->
                            <div class="mb-3">
                                <label class="block text-xs font-medium text-slate-700 mb-1">Jumlah</label>
                                <div class="flex items-center gap-2">
                                    <button type="button"
                                        class="h-8 w-8 rounded-lg border border-slate-200 bg-white grid place-items-center text-slate-700 hover:bg-slate-50 active:scale-95 transition-transform"
                                        @click="removeOne({{ (int) $product->id }})"
                                        aria-label="Kurangi">
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4"></path>
                                        </svg>
                                    </button>

                                    <input type="number"
                                        class="flex-1 h-8 text-center border border-slate-200 rounded-lg font-semibold text-sm focus:ring-2 focus:ring-slate-500 focus:border-slate-500"
                                        x-bind:value="qtyByProduct({{ (int) $product->id }})"
                                        @input="updateManualQty({{ (int) $product->id }}, $event.target.value)"
                                        min="0"
                                        max="99"
                                        placeholder="0"
                                        inputmode="numeric"
                                        pattern="[0-9]*">

                                    <button type="button"
                                        class="h-8 w-8 rounded-lg border border-slate-200 bg-white grid place-items-center text-slate-900 hover:bg-slate-50 active:scale-95 transition-transform"
                                        @click="addProduct(@js($productPayload))"
                                        {{ $product->track_stock && (int)($product->stock ?? 0) <= 0 ? 'disabled' : '' }}
                                        :class="({{ (int)($product->track_stock ?? 0) }} && {{ (int)($product->stock ?? 0) }} <= 0) ? 'opacity-40 cursor-not-allowed' : ''"
                                        aria-label="Tambah">
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                                        </svg>
                                    </button>
                                </div>
                            </div>

                            <!-- Action Buttons -->
                            <div class="grid gap-2">
                                @if($product->is_package)
                                    <button type="button"
                                        class="w-full rounded-xl px-4 py-2 text-sm font-semibold bg-indigo-600 text-white shadow-sm hover:bg-indigo-700 active:scale-95 transition-transform"
                                        @click="openPackageModal(@js($productPayload))">
                                        Lihat Paket
                                    </button>
                                @else
                                    <div class="flex justify-center">
                                        <button type="button"
                                            class="inline-flex items-center gap-3 rounded-full text-white px-3 py-2 shadow-lg hover:scale-105 transition-transform"
                                            style="background: var(--accent-color);"
                                            @click="addProduct(@js($productPayload))"
                                            {{ $product->track_stock && (int)($product->stock ?? 0) <= 0 ? 'disabled' : '' }}
                                            :class="({{ (int)($product->track_stock ?? 0) }} && {{ (int)($product->stock ?? 0) }} <= 0) ? 'opacity-40 cursor-not-allowed' : ''"
                                            aria-label="Tambah ke Keranjang (Checkout)">
                                            <span class="h-10 w-10 rounded-full grid place-items-center bg-white/10">
                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" class="h-6 w-6 text-white" stroke-width="1.5">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 3h2l.4 2M7 13h10l4-8H5.4" />
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M7 21a1 1 0 100-2 1 1 0 000 2zm10 0a1 1 0 100-2 1 1 0 000 2z" />
                                                </svg>
                                            </span>
                                            <span class="text-sm font-semibold">Checkout</span>
                                        </button>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            @endforeach
        </div>

        <!-- Package detail modal (center) -->
        <div class="fixed inset-0 z-[60]" x-show="packageOpen" x-transition>
            <div class="absolute inset-0 bg-black/40" @click="closePackageModal()"></div>
            <div class="relative min-h-full w-full px-4 pt-[calc(env(safe-area-inset-top)+16px)] pb-[calc(env(safe-area-inset-bottom)+16px)] flex items-center justify-center">
                <div class="w-full max-w-2xl rounded-3xl bg-white shadow-xl border border-slate-200 p-5 max-h-[85vh] overflow-auto" @click.stop>
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <div class="text-xs font-semibold text-indigo-700">PAKET SPESIAL</div>
                                <div class="text-lg font-semibold" x-text="packageProduct?.name || 'Paket' "></div>
                                <div class="mt-1 text-sm text-slate-600" x-text="packageProduct?.description || ''"></div>

                                <template x-if="Number(packageProduct?.savings || 0) > 0">
                                    <div class="mt-2 inline-flex items-center rounded-full bg-emerald-600 text-white px-3 py-1 text-[11px] font-extrabold shadow-sm">
                                        HEMAT Rp <span class="ml-1" x-text="formatRp(packageProduct?.savings || 0)"></span>
                                    </div>
                                </template>
                            </div>
                            <button type="button" class="rounded-2xl px-3 py-2 text-sm border border-slate-200" @click="closePackageModal()">Tutup</button>
                        </div>

                        <div class="mt-4 rounded-2xl border border-slate-100 p-4">
                            <div class="flex items-center justify-between gap-3">
                                <div class="text-sm font-semibold">Isi paket</div>
                                <div class="text-right">
                                    <template x-if="Number(packageProduct?.savings || 0) > 0">
                                        <div class="text-xs text-slate-500 line-through">Rp <span x-text="formatRp(packageProduct?.normal_price || 0)"></span></div>
                                    </template>
                                    <div class="text-sm font-semibold">Rp <span x-text="formatRp(packageProduct?.price || 0)"></span></div>
                                    <template x-if="Number(packageProduct?.savings || 0) > 0">
                                        <div class="mt-1 text-[11px] font-semibold text-emerald-700">Kamu hemat Rp <span x-text="formatRp(packageProduct?.savings || 0)"></span></div>
                                    </template>
                                </div>
                            </div>

                            <template x-if="(packageProduct?.track_stock)">
                                <div class="mt-2 text-xs font-semibold" :class="(Number(packageProduct?.stock||0) > 0) ? 'text-emerald-700' : 'text-rose-700'"
                                    x-text="(Number(packageProduct?.stock||0) > 0) ? ('Stok: ' + packageProduct.stock) : 'Stok habis'"></div>
                            </template>

                            <div class="mt-3 grid gap-2">
                                <template x-for="(it, idx) in (packageProduct?.package_items || [])" :key="idx">
                                    <div class="flex items-center justify-between gap-3 rounded-xl border border-slate-200 px-4 py-3">
                                        <div class="text-sm font-semibold" x-text="it.name"></div>
                                        <div class="text-right">
                                            <div class="text-xs text-slate-600" x-text="'Qty: ' + it.qty"></div>
                                            <template x-if="Number(it.unit_price || 0) > 0">
                                                <div class="text-[11px] text-slate-500" x-text="'@ Rp ' + formatRp(it.unit_price || 0)"></div>
                                            </template>
                                        </div>
                                    </div>
                                </template>
                                <template x-if="(packageProduct?.package_items || []).length === 0">
                                    <div class="text-sm text-slate-500">Isi paket belum diatur.</div>
                                </template>
                            </div>
                        </div>

                        <div class="mt-4 flex items-center justify-between gap-3">
                            <button type="button" class="rounded-2xl border border-slate-200 px-5 py-3 font-semibold" @click="closePackageModal()">Nanti dulu</button>
                            <button type="button" class="rounded-2xl bg-slate-900 text-white px-5 py-3 font-semibold"
                                @click="addFromPackage()">
                                Pesan Paket
                            </button>
                        </div>
                    </div>
                    </div>
                </div>

        <!-- Config modal (selera / add-on) (center) -->
        <div class="fixed inset-0 z-[60]" x-show="openConfig" x-transition>
            <div class="absolute inset-0 bg-black/40" @click="closeConfig()"></div>
            <div class="relative min-h-full w-full px-4 pt-[calc(env(safe-area-inset-top)+16px)] pb-[calc(env(safe-area-inset-bottom)+16px)] flex items-center justify-center">
                <div class="w-full max-w-2xl rounded-3xl bg-white shadow-xl border border-slate-200 p-5 max-h-[85vh] overflow-auto" @click.stop>
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <div class="text-lg font-semibold" x-text="configProduct?.name || 'Pilih Add-on'"></div>
                                <div class="text-xs text-slate-500">Atur selera pedas / topping sesuai keinginan.</div>
                            </div>
                            <button type="button" class="rounded-2xl px-3 py-2 text-sm border border-slate-200" @click="closeConfig()">Tutup</button>
                        </div>

                        <div class="mt-4 grid gap-4 max-h-[50vh] overflow-auto pr-1">
                            <template x-for="opt in (configProduct?.options || [])" :key="opt.id">
                                <div class="rounded-2xl border border-slate-100 p-4">
                                    <div class="flex items-center justify-between gap-3">
                                        <div class="text-sm font-semibold" x-text="opt.name"></div>
                                        <div class="text-xs" :class="opt.is_required ? 'text-amber-700' : 'text-slate-500'" x-text="opt.is_required ? 'Wajib' : 'Opsional'"></div>
                                    </div>

                                    <template x-if="opt.type === 'single'">
                                        <div class="mt-3 grid gap-2">
                                            <template x-for="val in (opt.values || [])" :key="val.id">
                                                <label class="flex items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-white px-4 py-3">
                                                    <div class="flex items-center gap-3">
                                                        <input type="radio" class="rounded-full border-slate-300" :name="'opt_'+opt.id" :value="val.id" x-model="configSelections[opt.id]">
                                                        <div class="text-sm" x-text="val.name"></div>
                                                    </div>
                                                    <div class="text-xs text-slate-600" x-show="(val.price_delta || 0) > 0">+Rp <span x-text="formatRp(val.price_delta)"></span></div>
                                                </label>
                                            </template>
                                        </div>
                                    </template>

                                    <template x-if="opt.type === 'multi'">
                                        <div class="mt-3 grid gap-2">
                                            <template x-for="val in (opt.values || [])" :key="val.id">
                                                <label class="flex items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-white px-4 py-3">
                                                    <div class="flex items-center gap-3">
                                                        <input type="checkbox" class="rounded border-slate-300" :value="val.id" x-model="configSelections[opt.id]">
                                                        <div class="text-sm" x-text="val.name"></div>
                                                    </div>
                                                    <div class="text-xs text-slate-600" x-show="(val.price_delta || 0) > 0">+Rp <span x-text="formatRp(val.price_delta)"></span></div>
                                                </label>
                                            </template>
                                        </div>
                                    </template>
                                </div>
                            </template>

                            <div x-show="configError" class="rounded-2xl border border-red-200 bg-red-50 p-3 text-sm text-red-700" x-text="configError"></div>
                        </div>

                        <div class="mt-4 flex items-center justify-between gap-3">
                            <div>
                                <div class="text-xs text-slate-500">Harga per porsi</div>
                                <div class="text-lg font-semibold">Rp <span x-text="formatRp(configUnitPrice())"></span></div>
                            </div>
                            <button type="button" class="rounded-2xl bg-slate-900 text-white px-5 py-3 font-semibold shadow-sm" @click="confirmConfig()">
                                Tambah ke Keranjang
                            </button>
                        </div>
                    </div>
                    </div>
                </div>

        <div class="fixed inset-x-0 top-[calc(env(safe-area-inset-top)+12px)] z-50" x-show="!canProcessSelfOrder()" x-transition x-cloak>
            <div class="mx-auto max-w-6xl px-4">
                <div class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800 shadow-sm">
                    <div class="font-semibold">Self-order sementara tidak tersedia</div>
                    <div class="mt-1" x-text="connectivityMessage()"></div>
                </div>
            </div>
        </div>

        <!-- Floating cart (raised above bottom navbar) -->
        <div class="fixed inset-x-0 bottom-[96px] sm:bottom-[104px] z-40" x-show="countItems() > 0" x-transition x-cloak>
            <div class="mx-auto max-w-6xl px-4 pb-[calc(env(safe-area-inset-bottom)+8px)]">
                <div class="rounded-3xl bg-[#F5C518] border border-[#E6A800] shadow-lg p-4 flex items-center justify-between gap-4">
                    <div>
                        <div class="text-xs text-[#1A1A1A]">Total</div>
                        <div class="text-lg font-semibold text-[#1A1A1A]">Rp <span x-text="formatRp(total())"></span></div>
                        <div class="text-xs text-[#1A1A1A]" x-text="countItems()+' item'"></div>
                    </div>
                    <button type="button" class="rounded-2xl px-5 py-3 font-semibold shadow-sm"
                        :class="(countItems()===0 || !tableToken || !canProcessSelfOrder()) ? 'bg-[#E6A800] text-[#1A1A1A] cursor-not-allowed' : 'bg-[#1A1A1A] text-[#F5C518]'"
                        @click="tryOpenCheckout()">
                        Checkout
                    </button>
                </div>
                <div class="mt-2" x-show="!tableToken" x-cloak>
                    <div class="rounded-2xl border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900">
                        <div class="font-bold">Silahkan scan QR meja Anda dahulu</div>
                        <div class="mt-1">Untuk melakukan checkout pesanan, kamu wajib scan QR meja.</div>
                        <button type="button" class="mt-2 inline-flex items-center gap-2 rounded-xl bg-amber-900 px-3 py-2 text-[11px] font-bold text-white"
                            @click="openScanner(true)">Scan QR sekarang →</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Checkout modal (center) -->
        <div class="fixed inset-0 z-[60]" x-show="openCheckout" x-transition>
            <div class="absolute inset-0 bg-black/40" @click="openCheckout=false"></div>
            <div class="relative min-h-full w-full px-4 pt-[calc(env(safe-area-inset-top)+16px)] pb-[calc(env(safe-area-inset-bottom)+16px)] flex items-center justify-center">
                <div class="w-full max-w-2xl rounded-3xl bg-white shadow-xl border border-slate-200 p-5 max-h-[85vh] overflow-auto" @click.stop>
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <div class="text-lg font-semibold">Checkout</div>
                                <div class="text-xs text-slate-500">Pastikan pesanan benar sebelum kirim.</div>
                            </div>
                            <button type="button" class="rounded-2xl px-3 py-2 text-sm border border-slate-200" @click="openCheckout=false">Tutup</button>
                        </div>

                        <div class="mt-4 space-y-2 max-h-56 overflow-auto">
                            <template x-for="row in cartList()" :key="row.key">
                                <div class="flex items-start justify-between gap-3 rounded-2xl border border-slate-100 bg-slate-50 p-3">
                                    <div class="min-w-0">
                                        <div class="font-medium truncate" x-text="row.name"></div>
                                        <div class="text-xs text-slate-500" x-show="(row.options_summary || '') !== ''" x-text="row.options_summary"></div>
                                        <div class="mt-1 text-xs text-slate-500">Rp <span x-text="formatRp(row.unit_price)"></span> / porsi</div>
                                    </div>

                                    <div class="flex flex-col items-end gap-2 shrink-0">
                                        <div class="font-semibold">Rp <span x-text="formatRp(row.unit_price*row.qty)"></span></div>

                                        <div class="flex items-center gap-2">
                                            <button type="button"
                                                class="h-9 w-9 rounded-xl border border-slate-200 bg-white font-semibold"
                                                @click="decRow(row.key)">
                                                −
                                            </button>
                                            <div class="min-w-8 text-center font-semibold" x-text="row.qty"></div>
                                            <button type="button"
                                                class="h-9 w-9 rounded-xl border border-slate-200 bg-white font-semibold"
                                                @click="incRow(row.key)">
                                                +
                                            </button>

                                            <button type="button" class="ml-1 text-xs font-semibold text-rose-700 underline" @click="removeRow(row.key)">Hapus</button>
                                        </div>
                                    </div>
                                </div>
                            </template>

                            <div x-show="countItems() === 0" class="rounded-2xl border border-slate-200 bg-white p-4 text-sm text-slate-600">
                                Keranjang kosong.
                            </div>
                        </div>

                        <form method="POST" action="{{ route('customer.checkout') }}" class="mt-4 grid gap-3"
                            x-ref="checkoutForm"
                            @submit="redeemPoints = Math.min(Math.max(0, Math.floor(Number(redeemPoints||0))), maxRedeemPoints())">
                            @csrf
                            <input type="hidden" name="table" :value="tableToken">
                            <input type="hidden" name="items" :value="JSON.stringify(checkoutPayload())">
                            <input type="hidden" name="payment_method" :value="selectedPaymentMethod">

                            @auth
                                @if(($loyalty?->redeem_enabled ?? false) && (int)($loyalty?->redeem_rp_per_point ?? 0) > 0)
                                    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                        <div class="flex items-start justify-between gap-3">
                                            <div>
                                                <div class="text-sm font-semibold">Pakai Poin</div>
                                                <div class="mt-0.5 text-xs text-slate-600">
                                                    Saldo: <span class="font-semibold">{{ number_format((int)($userPointsBalance ?? 0), 0, ',', '.') }}</span> poin ·
                                                    1 poin = Rp {{ number_format((int)($loyalty?->redeem_rp_per_point ?? 0), 0, ',', '.') }}
                                                </div>
                                            </div>
                                            <button type="button" class="text-xs font-semibold text-slate-700 underline" @click="redeemPoints = 0">Reset</button>
                                        </div>

                                        <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-3 items-end">
                                            <div>
                                                <label class="text-sm font-medium">Jumlah poin dipakai</label>
                                                <input type="number" min="0" name="redeem_points"
                                                    class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-3"
                                                    x-model.number="redeemPoints"
                                                    :max="maxRedeemPoints()"
                                                    placeholder="0" />
                                                <div class="mt-1 text-xs text-slate-500">
                                                    Maks: <span x-text="formatRp(maxRedeemPoints())"></span> poin
                                                </div>
                                            </div>
                                            <div class="rounded-2xl border border-slate-200 bg-white p-3">
                                                <div class="text-xs text-slate-500">Diskon dari poin</div>
                                                <div class="text-base font-semibold text-emerald-700">Rp <span x-text="formatRp(redeemDiscount())"></span></div>
                                                <div class="mt-1 text-xs text-slate-500">Total bayar: Rp <span x-text="formatRp(Math.max(0, total() - redeemDiscount()))"></span></div>
                                            </div>
                                        </div>

                                        <div class="mt-3 rounded-2xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-900" x-show="redeemDiscount() > 0">
                                            Selamat! Kamu pakai <span class="font-semibold" x-text="Math.min(Math.floor(Number(redeemPoints||0)), maxRedeemPoints())"></span> poin dan hemat
                                            <span class="font-semibold">Rp <span x-text="formatRp(redeemDiscount())"></span></span>. Yuk kumpulin poin terus!
                                        </div>
                                    </div>
                                @endif
                            @endauth

                            <div x-show="checkoutStep === 'details'" x-cloak class="grid gap-3">
                                <div>
                                    <label class="text-sm font-medium">Nama (opsional)</label>
                                    <input name="customer_name" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-3" placeholder="Nama panggilan" value="{{ auth()->check() ? auth()->user()->name : '' }}" />
                                </div>
                                <div>
                                    <label class="text-sm font-medium">Catatan (opsional)</label>
                                    <textarea name="notes" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-3" rows="2" placeholder="Contoh: tanpa pedas, es sedikit"></textarea>
                                </div>

                                <button type="button" class="rounded-2xl bg-slate-900 text-white px-5 py-3 font-semibold shadow-sm"
                                    :disabled="countItems()===0"
                                    @click="openPaymentStep()">
                                    Pilih Metode Pembayaran
                                </button>
                                <div class="text-xs text-slate-500">
                                    Self order hanya mendukung QRIS. Setelah pilih QRIS, order akan otomatis dikirim.
                                </div>
                            </div>

                            <div x-show="checkoutStep === 'payment'" x-cloak class="grid gap-3">
                                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                    <div class="text-sm font-semibold">Metode Pembayaran</div>
                                    <div class="mt-1 text-xs text-slate-600">Pilih salah satu, order akan langsung dikirim.</div>
                                </div>

                                <div x-show="(qrisMethods || []).length === 0" class="rounded-2xl border border-rose-200 bg-rose-50 p-4">
                                    <div class="text-sm font-semibold text-rose-900">QRIS belum aktif</div>
                                    <div class="mt-1 text-sm text-rose-800">Hubungi admin/kasir untuk mengaktifkan QRIS di Payment Settings.</div>
                                </div>

                                <div class="grid sm:grid-cols-2 gap-3" x-show="(qrisMethods || []).length > 0">
                                    <template x-for="m in (qrisMethods || [])" :key="m">
                                        <button type="button"
                                            class="rounded-2xl border px-5 py-4 text-left font-semibold shadow-sm"
                                            :class="selectedPaymentMethod===m ? 'border-emerald-300 bg-emerald-50' : 'border-slate-200 bg-white hover:bg-slate-50'"
                                            @click="submitWithPayment(m)">
                                            <div class="flex items-start justify-between gap-3">
                                                <div>
                                                    <div class="text-sm" x-text="m==='qris_dynamic' ? 'QRIS Dinamis' : 'QRIS' "></div>
                                                    <div class="mt-1 text-xs text-slate-600" x-text="m==='qris_dynamic' ? 'QR akan dibuat per transaksi (API).' : 'Scan QR statis toko.'"></div>
                                                </div>
                                                <div class="text-xs text-slate-500">Pilih →</div>
                                            </div>
                                        </button>
                                    </template>
                                </div>

                                <div class="flex items-center justify-between gap-3">
                                    <button type="button" class="rounded-2xl border border-slate-200 bg-white px-5 py-3 font-semibold"
                                        @click="checkoutStep='details'">
                                        Kembali
                                    </button>
                                    <div class="text-xs text-slate-500" x-show="selectedPaymentMethod">Metode terpilih: <span class="font-semibold" x-text="selectedPaymentMethod"></span></div>
                                </div>
                            </div>
                        </form>

                        @if($errors->any())
                            <div class="mt-3 rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                                <div class="font-semibold text-red-800 mb-2">❌ Checkout Gagal</div>
                                <div class="text-red-700">Silahkan scan QR meja Anda terlebih dahulu sebelum melakukan checkout.</div>
                                <button type="button" class="mt-3 inline-flex items-center gap-2 rounded-xl bg-red-600 px-4 py-2 text-xs font-bold text-white hover:bg-red-700 transition-colors"
                                    @click="openScanner(true)">
                                    🔍 Scan QR Meja Sekarang →
                                </button>
                            </div>
                        @endif
                    </div>
                    </div>
                </div>
    </div>

    <script>
        function orderPage(initialPendingOrder, baseUrl) {
            return {
                activeCategory: 'all',
                searchQuery: '',
                searchOpen: false,
                openCheckout: false,
                tableToken: @js(optional($table)->public_id),
                // How long a scanned table token is valid (ms). Users requested re-scan every 10 minutes.
                tableTokenTtlMs: 1000 * 60 * 10,
                tableLabel: @js($table ? ($table->name ?: $table->code) : ''),
                cart: {},

                heroData: @js($heroData ?? []),
                heroCategoryId: null,
                heroProductId: null,
                heroImageShow: true,
                heroImageSwapTimer: null,

                categoryItems: @js(array_merge([
                    ['id' => 'all', 'name' => 'Semua'],
                ], ($categories ?? collect())->map(fn($c) => ['id' => (string) $c->id, 'name' => (string) $c->name])->values()->all())),

                allProducts: @js(collect($categories ?? [])->pluck('products')->flatten()->map(function($product) {
                    $normalPrice = (int) ($product->packageItems?->sum(fn ($pi) => (int) $pi->qty * (int) ($pi->itemProduct?->price ?? 0)) ?? 0);
                    $packagePrice = (int) ($product->price ?? 0);
                    $savings = max(0, $normalPrice - $packagePrice);

                    return [
                        'id' => $product->id,
                        'name' => $product->name,
                        'price' => (int) $product->price,
                        'normal_price' => $normalPrice,
                        'savings' => $savings,
                        'is_package' => (bool) $product->is_package,
                        'track_stock' => (bool) $product->track_stock,
                        'stock' => (int) ($product->stock ?? 0),
                        'description' => $product->description,
                        'package_items' => ($product->packageItems ?? collect())
                            ->map(fn ($pi) => [
                                'product_id' => (int) $pi->item_product_id,
                                'name' => (string) ($pi->itemProduct?->name ?? 'Item'),
                                'qty' => (int) $pi->qty,
                                'unit_price' => (int) ($pi->itemProduct?->price ?? 0),
                            ])->values(),
                        'options' => ($product->options ?? collect())
                            ->map(fn ($o) => [
                                'id' => $o->id,
                                'name' => $o->name,
                                'type' => $o->type,
                                'is_required' => (bool) $o->is_required,
                                'values' => ($o->values ?? collect())
                                    ->map(fn ($v) => [
                                        'id' => $v->id,
                                        'name' => $v->name,
                                        'price_delta' => (int) $v->price_delta,
                                    ])->values(),
                            ])->values(),
                    ];
                })->values()->all()),

                dialOffsetDeg: 0,
                dialRadius: 110,
                dialAngleStart: -75,
                dialAngleEnd: 75,
                dialDragging: false,
                dialSnapping: false,
                dialStartX: 0,
                dialStartOffset: 0,
                dialMoved: false,
                dialPointerId: null,

                redeemPoints: 0,
                redeemEnabled: @js((bool)($loyalty?->redeem_enabled ?? false)),
                redeemRpPerPoint: @js((int)($loyalty?->redeem_rp_per_point ?? 0)),
                redeemMinSpend: @js((int)($loyalty?->redeem_min_spend_amount ?? 0)),
                redeemMaxPointsPerOrder: @js($loyalty?->redeem_max_points_per_order),
                redeemMaxDiscountRp: @js($loyalty?->redeem_max_discount_rp),
                userPointsBalance: @js((int)($userPointsBalance ?? 0)),

                // Self-order payment (QRIS only)
                qrisMethods: @js($qrisMethods ?? []),
                selectedPaymentMethod: @js($defaultQrisMethod ?? null),
                checkoutStep: 'details',

                baseUrl: (baseUrl || ''),
                pendingOrder: (initialPendingOrder || null),
                pendingPollTimer: null,
                networkOnline: navigator.onLine,
                serverReachable: true,
                connectivityCheckTimer: null,
                connectivityDownNotified: false,

                openConfig: false,
                configProduct: null,
                configSelections: {},
                configError: '',

                packageOpen: false,
                packageProduct: null,

                formatRp(v) {
                    const s = String(v ?? 0);
                    return s.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
                },

                maxRedeemPoints() {
                    if (!this.redeemEnabled) return 0;
                    if (!this.redeemRpPerPoint || this.redeemRpPerPoint <= 0) return 0;
                    if ((this.total() || 0) < (this.redeemMinSpend || 0)) return 0;

                    let max = Number(this.userPointsBalance || 0);
                    const cap = this.redeemMaxPointsPerOrder;
                    if (typeof cap === 'number' && cap > 0) {
                        max = Math.min(max, cap);
                    }
                    max = Math.min(max, Math.floor((this.total() || 0) / this.redeemRpPerPoint));

                    const maxDisc = this.redeemMaxDiscountRp;
                    if (typeof maxDisc === 'number' && maxDisc > 0) {
                        max = Math.min(max, Math.floor(maxDisc / this.redeemRpPerPoint));
                    }
                    return Math.max(0, Math.floor(max));
                },

                redeemDiscount() {
                    const maxPts = this.maxRedeemPoints();
                    let pts = Number(this.redeemPoints || 0);
                    if (!Number.isFinite(pts) || pts < 0) pts = 0;
                    pts = Math.min(Math.floor(pts), maxPts);
                    return pts * (this.redeemRpPerPoint || 0);
                },

                formatDateTime(iso) {
                    try {
                        const d = new Date(iso);
                        if (Number.isNaN(d.getTime())) return String(iso);
                        return new Intl.DateTimeFormat('id-ID', {
                            year: 'numeric', month: '2-digit', day: '2-digit',
                            hour: '2-digit', minute: '2-digit',
                        }).format(d);
                    } catch (e) {
                        return String(iso);
                    }
                },

                isPendingStatus(status) {
                    // completed is returned by server only for <= 5 minutes, so we can show final message.
                    return status === 'new' || status === 'accepted' || status === 'preparing' || status === 'prepared' || status === 'completed';
                },

                statusClass(s) {
                    switch (s) {
                        case 'new': return 'bg-blue-50 text-blue-700 border-blue-200';
                        case 'accepted': return 'bg-amber-50 text-amber-700 border-amber-200';
                        case 'preparing': return 'bg-purple-50 text-purple-700 border-purple-200';
                        case 'prepared': return 'bg-orange-50 text-orange-700 border-orange-200';
                        case 'completed': return 'bg-emerald-50 text-emerald-700 border-emerald-200';
                        case 'cancelled': return 'bg-gray-100 text-gray-700 border-gray-200';
                        default: return 'bg-gray-100 text-gray-700 border-gray-200';
                    }
                },

                init() {
                    // Persisted table token support: prefer server-provided token, otherwise honor a recent local scan
                    try {
                        const lsToken = localStorage.getItem('tableToken');
                        const lsTs = parseInt(localStorage.getItem('tableTokenScannedAt') || '0', 10) || 0;
                        const ttl = Number(this.tableTokenTtlMs || 60000);

                        if (!this.tableToken && lsToken && (Date.now() - lsTs) < ttl) {
                            this.tableToken = lsToken;
                        }

                        // If we have a token (either server or local), start polling pending orders
                        if (this.tableToken) {
                            // ensure localStorage mirrors server token
                            try { localStorage.setItem('tableToken', String(this.tableToken)); localStorage.setItem('tableTokenScannedAt', String(Date.now())); } catch (e) {}
                            this.startPendingPolling();
                        } else {
                            // Try to open scanner automatically when no table (best-effort; browsers may require user gesture)
                            setTimeout(() => {
                                try { this.openScanner(true); } catch (e) {}
                            }, 400);
                        }
                    } catch (e) {
                        // fallback to original behaviour
                        if (!this.tableToken) {
                            setTimeout(() => { try { this.openScanner(true); } catch (ee) {} }, 400);
                        }
                        if (this.tableToken) this.startPendingPolling();
                    }

                    // Category dial sizing + initial center
                    this.$nextTick(() => {
                        try { this.updateDialRadius(); } catch (e) {}
                        try {
                            const idx = this.indexOfCategory(this.activeCategory);
                            if (idx >= 0) this.snapDialToIndex(idx, false);
                        } catch (e) {}
                    });

                    // Hero showcase (top multiple menu + featured product)
                    this.$nextTick(() => {
                        try { this.initHero(); } catch (e) {}
                    });

                    window.addEventListener('resize', () => {
                        try { this.updateDialRadius(); } catch (e) {}
                    });

                    window.addEventListener('online', () => {
                        this.networkOnline = true;
                        this.checkServerReachability(true);
                    });

                    window.addEventListener('offline', () => {
                        this.networkOnline = false;
                        this.serverReachable = false;
                        this.connectivityDownNotified = true;
                        this.showStatusFlash('error', 'Koneksi Terputus', this.connectivityMessage());
                    });

                    this.startConnectivityChecks();
                },

                canProcessSelfOrder() {
                    return !!this.networkOnline && !!this.serverReachable;
                },

                connectivityMessage() {
                    if (!this.networkOnline) {
                        return 'Koneksi internet kamu sedang mati. Cek jaringan dulu lalu coba lagi.';
                    }
                    if (!this.serverReachable) {
                        return 'Koneksi ke kasir/server sedang terganggu. Mohon tunggu sebentar lalu coba checkout lagi.';
                    }
                    return '';
                },

                startConnectivityChecks() {
                    if (this.connectivityCheckTimer) return;
                    this.checkServerReachability(false);
                    this.connectivityCheckTimer = setInterval(() => this.checkServerReachability(false), 12000);
                },

                async checkServerReachability(forceToast) {
                    if (!this.networkOnline) {
                        this.connectivityDownNotified = true;
                        this.serverReachable = false;
                        return;
                    }

                    const url = `${this.baseUrl}/order?healthcheck=1&_t=${Date.now()}`;
                    try {
                        const prevReachable = !!this.serverReachable;
                        const res = await fetch(url, {
                            method: 'GET',
                            headers: { 'Accept': 'text/html' },
                            cache: 'no-store',
                            credentials: 'same-origin',
                        });

                        this.serverReachable = !!res.ok;

                        if (!prevReachable && this.serverReachable) {
                            this.connectivityDownNotified = false;
                            this.showStatusFlash('success', 'Koneksi Pulih', 'Koneksi kembali normal, kamu bisa lanjut self-order.');
                            return;
                        }

                        if (forceToast && this.serverReachable) {
                            this.connectivityDownNotified = false;
                            this.showStatusFlash('success', 'Koneksi Pulih', 'Koneksi kembali normal, kamu bisa lanjut self-order.');
                            return;
                        }

                        if (prevReachable && !this.serverReachable && !this.connectivityDownNotified) {
                            this.connectivityDownNotified = true;
                            this.showStatusFlash('error', 'Koneksi Kasir Terganggu', this.connectivityMessage());
                        }
                    } catch (e) {
                        if (this.serverReachable && !this.connectivityDownNotified) {
                            this.connectivityDownNotified = true;
                            this.showStatusFlash('error', 'Koneksi Kasir Terganggu', this.connectivityMessage());
                        }
                        this.serverReachable = false;
                    }
                },

                initHero() {
                    const cats = this.heroData || [];
                    if (!cats.length) return;
                    const firstCat = cats.find(c => (c?.products || []).length > 0) || cats[0];
                    this.heroCategoryId = String(firstCat?.id ?? '');
                    const products = (firstCat?.products || []);
                    const featuredId = Number(firstCat?.featured_product_id);
                    const featured = products.find(p => Number(p?.id) === featuredId);
                    const firstWithImg = products.find(p => !!p?.image_url);
                    const pick = featured || firstWithImg || products[0];

                    // Initial render: show content immediately (no blink)
                    this.heroProductId = pick ? Number(pick.id) : null;
                    this.heroImageShow = true;
                },

                heroP() {
                    return this.heroActiveProduct() || {};
                },

                heroSwapTo(productId) {
                    const pid = Number(productId);
                    if (!Number.isFinite(pid)) return;

                    if (this.heroImageSwapTimer) {
                        try { clearTimeout(this.heroImageSwapTimer); } catch (e) {}
                        this.heroImageSwapTimer = null;
                    }

                    // Only animate the big image (keep text/buttons steady).
                    this.heroImageShow = false;
                    this.heroImageSwapTimer = setTimeout(() => {
                        this.heroProductId = pid;
                        this.$nextTick(() => {
                            this.heroImageShow = true;
                        });
                    }, 140);
                },

                heroCurrentCategory() {
                    const cats = this.heroData || [];
                    const id = String(this.heroCategoryId ?? '');
                    return cats.find(c => String(c.id) === id) || cats[0] || null;
                },

                heroProducts() {
                    const cat = this.heroCurrentCategory();
                    return (cat?.products || []);
                },

                heroActiveProduct() {
                    const products = this.heroProducts();
                    const pid = Number(this.heroProductId);
                    const found = products.find(p => Number(p.id) === pid);
                    return found || products[0] || null;
                },

                heroActiveProductList() {
                    const p = this.heroActiveProduct();
                    return p ? [p] : [];
                },

                heroMiniCards() {
                    const products = this.heroProducts();
                    const activeId = Number(this.heroProductId);
                    const others = products.filter(p => Number(p.id) !== activeId);
                    const active = products.find(p => Number(p.id) === activeId);
                    const list = [];
                    if (active) list.push(active);
                    for (const p of others) {
                        if (list.length >= 3) break;
                        list.push(p);
                    }
                    return list.slice(0, 3);
                },

                heroSliderCards() {
                    const products = this.heroProducts();
                    const activeId = Number(this.heroProductId);
                    const active = products.find(p => Number(p.id) === activeId);
                    const rest = products.filter(p => Number(p.id) !== activeId);
                    const list = [];
                    if (active) list.push(active);
                    for (const p of rest) list.push(p);
                    return list;
                },

                heroSetCategory(id) {
                    const sid = String(id);
                    this.heroCategoryId = sid;
                    const cat = (this.heroData || []).find(c => String(c.id) === sid) || null;
                    const products = (cat?.products || []);
                    const featuredId = Number(cat?.featured_product_id);
                    const featured = products.find(p => Number(p?.id) === featuredId);
                    const firstWithImg = products.find(p => !!p?.image_url);
                    const pick = featured || firstWithImg || products[0];
                    if (pick) {
                        this.heroSwapTo(Number(pick.id));
                    } else {
                        this.heroProductId = null;
                        this.heroImageShow = false;
                        this.$nextTick(() => { this.heroImageShow = true; });
                    }
                },

                heroPickProduct(productId) {
                    this.heroSwapTo(productId);
                },

                heroOrderNow() {
                    const p = this.heroActiveProduct();
                    if (!p) return;

                    try {
                        const cid = String(p.category_id || this.heroCategoryId || 'all');
                        this.setCategory(cid);
                    } catch (e) {}

                    // For paket, show details first; otherwise add directly (handles config/options too).
                    if (p.is_package) {
                        this.openPackageModal(p);
                        return;
                    }

                    this.addProduct(p);
                    try { this.showStatusFlash('success', 'Ditambahkan', (p.name || 'Menu') + ' masuk keranjang.'); } catch (e) {}
                },

                setCategory(id, el) {
                    this.activeCategory = String(id);
                    this.$nextTick(() => {
                        try {
                            if (el && typeof el.scrollIntoView === 'function') {
                                el.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
                            }
                        } catch (e) {}
                    });
                },

                indexOfCategory(id) {
                    const sid = String(id);
                    return (this.categoryItems || []).findIndex(c => String(c.id) === sid);
                },

                activeCategoryLabel() {
                    const sid = String(this.activeCategory || 'all');
                    const found = (this.categoryItems || []).find(c => String(c.id) === sid);
                    return (found && found.name) ? String(found.name) : 'Semua';
                },

                baseDialAngle(idx) {
                    const n = (this.categoryItems || []).length;
                    if (n <= 1) return 0;
                    const start = Number(this.dialAngleStart || -75);
                    const end = Number(this.dialAngleEnd || 75);
                    return start + (idx * (end - start) / (n - 1));
                },

                dialAngleForIndex(idx) {
                    return this.baseDialAngle(idx) + Number(this.dialOffsetDeg || 0);
                },

                dialCenteredIndex() {
                    const items = this.categoryItems || [];
                    if (items.length < 1) return -1;
                    let bestIdx = 0;
                    let bestDist = Infinity;
                    for (let i = 0; i < items.length; i++) {
                        const d = Math.abs(this.dialAngleForIndex(i));
                        if (d < bestDist) {
                            bestDist = d;
                            bestIdx = i;
                        }
                    }
                    return bestIdx;
                },

                updateDialRadius() {
                    const el = this.$refs?.categoryDialViewport;
                    const w = Number(el?.clientWidth || 0);
                    if (!w || !Number.isFinite(w)) return;
                    const r = Math.floor((w / 2) - 18);
                    this.dialRadius = Math.max(85, Math.min(125, r));
                },

                dialWrapperStyle(idx) {
                    const a = this.dialAngleForIndex(idx);
                    return `transform: translateX(-50%) rotate(${a}deg);`;
                },

                dialButtonStyle(idx) {
                    const a = this.dialAngleForIndex(idx);
                    const r = Number(this.dialRadius || 140);
                    const t = `transform: translateY(-${r}px) rotate(${-a}deg);`;
                    const tt = this.dialSnapping ? 'transition: transform 300ms cubic-bezier(0.2, 0.9, 0.2, 1);' : '';
                    return t + tt;
                },

                snapDialToIndex(idx, animate = true) {
                    const a = this.baseDialAngle(idx);
                    if (animate) {
                        this.dialSnapping = true;
                        clearTimeout(this._dialSnapT);
                        this._dialSnapT = setTimeout(() => { this.dialSnapping = false; }, 320);
                    } else {
                        this.dialSnapping = false;
                    }
                    this.dialOffsetDeg = -a;
                },

                selectDialCategory(idx) {
                    const cat = (this.categoryItems || [])[idx];
                    if (!cat) return;
                    this.setCategory(String(cat.id));
                    this.snapDialToIndex(idx, true);
                },

                spinDial(dir) {
                    const delta = Number(dir || 0);
                    if (!delta) return;
                    const current = this.dialCenteredIndex();
                    const next = Math.max(0, Math.min((this.categoryItems || []).length - 1, current + (delta > 0 ? 1 : -1)));
                    this.selectDialCategory(next);
                },

                dialPointerDown(e) {
                    try {
                        this.dialDragging = true;
                        this.dialMoved = false;
                        this.dialStartX = Number(e?.clientX || 0);
                        this.dialStartOffset = Number(this.dialOffsetDeg || 0);
                        this.dialPointerId = e?.pointerId ?? null;
                        if (e?.target && typeof e.target.setPointerCapture === 'function' && this.dialPointerId !== null) {
                            e.target.setPointerCapture(this.dialPointerId);
                        }
                    } catch (err) {}
                },

                dialPointerMove(e) {
                    if (!this.dialDragging) return;
                    const x = Number(e?.clientX || 0);
                    const dx = x - Number(this.dialStartX || 0);
                    if (Math.abs(dx) > 3) this.dialMoved = true;
                    const sensitivity = 0.25; // deg per px
                    this.dialOffsetDeg = Number(this.dialStartOffset || 0) + (dx * sensitivity);
                },

                dialPointerUp(e) {
                    if (!this.dialDragging) return;
                    this.dialDragging = false;

                    // Snap to nearest category and set it (but only if user actually dragged)
                    const idx = this.dialCenteredIndex();
                    if (idx >= 0) {
                        if (this.dialMoved) {
                            this.selectDialCategory(idx);
                        } else {
                            this.snapDialToIndex(idx, true);
                        }
                    }

                    this.dialMoved = false;
                    this.dialPointerId = null;
                },

                scrollCategories(px) {
                    try {
                        const el = this.$refs?.categoryScroller;
                        if (!el || typeof el.scrollBy !== 'function') return;
                        el.scrollBy({ left: Number(px || 0), behavior: 'smooth' });
                    } catch (e) {}
                },

                normalizeText(s) {
                    return String(s || '')
                        .toLowerCase()
                        .replace(/\s+/g, ' ')
                        .trim();
                },

                matchesSearch(haystack) {
                    const q = this.normalizeText(this.searchQuery);
                    if (!q) return true;
                    const h = this.normalizeText(haystack);
                    return h.includes(q);
                },

                startPendingPolling() {
                    if (this.pendingPollTimer) return;
                    this.pendingPollTimer = setInterval(() => this.refreshPendingOrder(), 5000);
                    this.refreshPendingOrder();
                },

                async refreshPendingOrder() {
                    if (!this.tableToken) return;
                    try {
                        const url = `${this.baseUrl}/order/pending?table=${encodeURIComponent(this.tableToken)}`;
                        const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
                        if (!res.ok) return;
                        const json = await res.json();
                        const prevStatus = this.pendingOrder?.status;
                        this.pendingOrder = json.order;

                        const nextStatus = this.pendingOrder?.status;
                        if (prevStatus && nextStatus && prevStatus !== nextStatus) {
                            if (nextStatus === 'preparing') {
                                this.showStatusFlash('preparing', 'Order sedang disiapkan', 'Yeyay pesananmu sedang disiapkan ya, ditunggu ya');
                            }
                            if (nextStatus === 'prepared') {
                                this.showStatusFlash('prepared', 'Order siap disajikan', 'Pesananmu sudah siap! Tunggu sebentar ya, akan segera diantar.');
                            }
                            if (nextStatus === 'completed') {
                                this.showStatusFlash('completed', 'Order selesai', 'Yeay, kasir baik kami akan anter pesanan ke meja kamu.');
                            }
                        }
                    } catch (e) {
                        // ignore
                    }
                },

                statusFlashOpen: false,
                statusFlashType: 'preparing',
                statusFlashTitle: '',
                statusFlashMessage: '',
                statusFlashTimer: null,

                showStatusFlash(type, title, message) {
                    this.statusFlashType = type;
                    this.statusFlashTitle = title;
                    this.statusFlashMessage = message;
                    this.statusFlashOpen = true;
                    if (this.statusFlashTimer) clearTimeout(this.statusFlashTimer);
                    this.statusFlashTimer = setTimeout(() => { this.statusFlashOpen = false; }, 4500);
                },

                requireTableToken() {
                    // Accept server token or a recently scanned local token (within TTL)
                    if (this.tableToken) return true;
                    try {
                        const lsToken = localStorage.getItem('tableToken');
                        const lsTs = parseInt(localStorage.getItem('tableTokenScannedAt') || '0', 10) || 0;
                        const ttl = Number(this.tableTokenTtlMs || 600000); // 10 minutes
                        if (lsToken && (Date.now() - lsTs) < ttl) {
                            this.tableToken = lsToken;
                            this.startPendingPolling();
                            return true;
                        }
                    } catch (e) {}

                    try {
                        this.showStatusFlash(
                            'error',
                            'Scan QR Meja Diperlukan',
                            'Silahkan scan QR meja Anda terlebih dahulu untuk mulai order atau melihat status pesanan.'
                        );
                    } catch (e) {}
                    try { this.openScanner(true); } catch (e) {}
                    return false;
                },

                tryOpenCheckout() {
                    if ((this.countItems() || 0) <= 0) return;
                    if (!this.canProcessSelfOrder()) {
                        this.showStatusFlash('error', 'Self-order tidak tersedia', this.connectivityMessage());
                        return;
                    }
                    if (!this.requireTableToken()) return;
                    this.checkoutStep = 'details';
                    if (!this.selectedPaymentMethod && (this.qrisMethods || []).length === 1) {
                        this.selectedPaymentMethod = this.qrisMethods[0];
                    }
                    this.openCheckout = true;
                },

                openPaymentStep() {
                    if (this.countItems() === 0) return;
                    this.checkoutStep = 'payment';
                    // Ensure we have a default when only 1 method is enabled
                    if (!this.selectedPaymentMethod && (this.qrisMethods || []).length === 1) {
                        this.selectedPaymentMethod = this.qrisMethods[0];
                    }
                },

                submitWithPayment(method) {
                    if (!this.canProcessSelfOrder()) {
                        this.showStatusFlash('error', 'Self-order tidak tersedia', this.connectivityMessage());
                        return;
                    }
                    this.selectedPaymentMethod = String(method || '');
                    if (!this.selectedPaymentMethod) return;
                    // refresh local table token timestamp so user won't be asked to re-scan immediately after submitting
                    try { if (this.tableToken) localStorage.setItem('tableTokenScannedAt', String(Date.now())); } catch (e) {}
                    this.$nextTick(() => {
                        if (this.$refs.checkoutForm) {
                            this.$refs.checkoutForm.submit();
                        }
                    });
                },

                openPackageModal(product) {
                    this.packageProduct = product;
                    this.packageOpen = true;
                },

                closePackageModal() {
                    this.packageOpen = false;
                    this.packageProduct = null;
                },

                addFromPackage() {
                    const p = this.packageProduct;
                    if (!p) return;
                    this.closePackageModal();
                    this.addProduct(p);
                },

                scanOpen: false,
                scanError: '',
                scanStream: null,
                scanDetector: null,
                scanRunning: false,

                openScanner(autoStart = false) {
                    this.scanError = '';
                    this.scanOpen = true;
                    if (autoStart) {
                        setTimeout(() => this.startScanner(), 200);
                    }
                },

                async startScanner() {
                    this.scanError = '';
                    if (!('mediaDevices' in navigator) || !navigator.mediaDevices.getUserMedia) {
                        this.scanError = 'Browser kamu tidak mendukung akses kamera.';
                        return;
                    }

                    // On mobile, getUserMedia requires a secure context (HTTPS) except localhost.
                    if (!window.isSecureContext) {
                        this.scanError = 'Kamera hanya bisa dipakai di HTTPS (secure). Kamu sekarang membuka: ' + window.location.origin +
                            '. Coba buka lewat HTTPS (mis. tunnel/ngrok) atau scan pakai kamera bawaan lalu buka link QR.';
                        return;
                    }

                    if (!('BarcodeDetector' in window)) {
                        this.scanError = 'Fitur scan QR belum didukung di browser ini. Coba Google Chrome di Android, atau gunakan scan kamera bawaan lalu buka linknya.';
                        return;
                    }

                    try {
                        const stream = await navigator.mediaDevices.getUserMedia({
                            video: { facingMode: { ideal: 'environment' } },
                            audio: false,
                        });
                        this.scanStream = stream;
                        const video = this.$refs.scanVideo;
                        video.srcObject = stream;
                        await video.play();

                        this.scanDetector = new BarcodeDetector({ formats: ['qr_code'] });
                        this.scanRunning = true;
                        this.tickScan();
                    } catch (e) {
                        const name = e?.name ? String(e.name) : '';
                        const msg = e?.message ? String(e.message) : '';

                        if (name === 'NotAllowedError') {
                            this.scanError = 'Izin kamera ditolak. Cek izin kamera untuk situs ini di pengaturan browser.';
                            return;
                        }
                        if (name === 'NotFoundError') {
                            this.scanError = 'Kamera tidak ditemukan di device ini.';
                            return;
                        }
                        if (name === 'NotReadableError') {
                            this.scanError = 'Kamera sedang dipakai aplikasi lain. Tutup aplikasi kamera/WhatsApp/Instagram lalu coba lagi.';
                            return;
                        }

                        this.scanError = 'Gagal membuka kamera. ' + (name ? name + ': ' : '') + (msg || 'Coba refresh halaman lalu scan lagi.');
                    }
                },

                async tickScan() {
                    if (!this.scanRunning) return;
                    try {
                        const video = this.$refs.scanVideo;
                        if (video && video.readyState >= 2) {
                            const codes = await this.scanDetector.detect(video);
                            if (codes && codes.length) {
                                const raw = codes[0].rawValue || '';
                                this.handleQrResult(String(raw));
                                return;
                            }
                        }
                    } catch (e) {
                        // ignore
                    }
                    requestAnimationFrame(() => this.tickScan());
                },

                handleQrResult(raw) {
                    // Accept either a full URL containing ?table=TOKEN or a plain token
                    let target = null;
                    try {
                        if (raw.startsWith('http://') || raw.startsWith('https://')) {
                            const u = new URL(raw);
                            const token = u.searchParams.get('table');
                            if (token) {
                                target = `${this.baseUrl}/order?table=${encodeURIComponent(token)}`;
                            } else {
                                // If QR points directly to /order?table=..., keep it
                                target = raw;
                            }
                        } else {
                            const token = raw.trim();
                            if (token) target = `${this.baseUrl}/order?table=${encodeURIComponent(token)}`;
                        }
                    } catch (e) {
                        // ignore
                    }

                    if (target) {
                        // If the QR contained a table token, persist it locally so we don't force re-scan
                        try {
                            // extract token if present in the URL
                            const u = new URL(target, window.location.origin);
                            const token = u.searchParams.get('table') || null;
                            if (token) {
                                try { localStorage.setItem('tableToken', String(token)); localStorage.setItem('tableTokenScannedAt', String(Date.now())); } catch (e) {}
                            }
                        } catch (e) {}

                        this.closeScanner();
                        window.location.href = target;
                        return;
                    }

                    this.scanError = 'QR tidak dikenali. Pastikan QR meja benar.';
                },

                closeScanner() {
                    this.scanOpen = false;
                    this.scanRunning = false;
                    this.scanDetector = null;

                    try {
                        const video = this.$refs.scanVideo;
                        if (video) {
                            video.pause();
                            video.srcObject = null;
                        }
                    } catch (e) {}

                    if (this.scanStream) {
                        try { this.scanStream.getTracks().forEach(t => t.stop()); } catch (e) {}
                    }
                    this.scanStream = null;
                },

                cartList() {
                    return Object.values(this.cart || {});
                },

                qtyByProduct(productId) {
                    return this.cartList().reduce((sum, r) => sum + (r.product_id === productId ? (r.qty || 0) : 0), 0);
                },

                updateManualQty(productId, value) {
                    const numValue = parseInt(value, 10);
                    if (isNaN(numValue) || numValue < 0) {
                        // Reset to current quantity if invalid
                        this.$nextTick(() => {
                            const input = document.querySelector(`input[x-model*='manualQty_${productId}']`);
                            if (input) input.value = this.qtyByProduct(productId);
                        });
                        return;
                    }

                    if (numValue > 99) {
                        // Max 99 items
                        this.$nextTick(() => {
                            const input = document.querySelector(`input[x-model*='manualQty_${productId}']`);
                            if (input) input.value = 99;
                        });
                        this.updateManualQty(productId, '99');
                        return;
                    }

                    const product = this.findProductById(productId);
                    if (product?.track_stock) {
                        const stock = Number(product.stock ?? 0);
                        if (numValue > stock) {
                            // Cannot exceed stock
                            this.showStatusFlash('error', 'Stok tidak cukup', `Maksimal ${stock} untuk ${product.name}.`);
                            this.$nextTick(() => {
                                const input = document.querySelector(`input[x-model*='manualQty_${productId}']`);
                                if (input) input.value = Math.min(this.qtyByProduct(productId), stock);
                            });
                            return;
                        }
                    }

                    const currentQty = this.qtyByProduct(productId);
                    const diff = numValue - currentQty;

                    if (diff > 0) {
                        // Need to add items
                        for (let i = 0; i < diff; i++) {
                            this.addProduct(product);
                        }
                    } else if (diff < 0) {
                        // Need to remove items
                        for (let i = 0; i < Math.abs(diff); i++) {
                            this.removeOne(productId);
                        }
                    }
                },

                findProductById(productId) {
                    return this.allProducts?.find(p => p.id === productId);
                },

                addProduct(product) {
                    if (!product) return;

                    // Prevent adding items when table QR is not scanned
                    if (!this.requireTableToken()) return;

                    const opts = (product.options || []);
                    if (opts.length > 0) {
                        this.openConfigFor(product);
                        return;
                    }
                    this.addVariant(product, {});
                },

                removeOne(productId) {
                    const keys = Object.keys(this.cart || {});
                    for (const k of keys) {
                        const row = this.cart[k];
                        if (row && row.product_id === productId) {
                            row.qty = (row.qty || 0) - 1;
                            if (row.qty <= 0) delete this.cart[k];
                            return;
                        }
                    }
                },

                incRow(rowKey) {
                    const row = this.cart?.[rowKey];
                    if (!row) return;

                    if (row.track_stock) {
                        const stock = Number(row.stock ?? 0);
                        const currentQty = this.qtyByProduct(Number(row.product_id));
                        if (stock <= 0) {
                            this.showStatusFlash('error', 'Stok habis', (row?.name || 'Produk') + ' sedang habis.');
                            return;
                        }
                        if (currentQty >= stock) {
                            this.showStatusFlash('error', 'Stok tidak cukup', 'Maksimal ' + stock + ' untuk ' + (row?.name || 'produk') + '.');
                            return;
                        }
                    }

                    row.qty = Math.min((row.qty || 0) + 1, 99);
                },

                decRow(rowKey) {
                    const row = this.cart?.[rowKey];
                    if (!row) return;
                    row.qty = (row.qty || 0) - 1;
                    if (row.qty <= 0) delete this.cart[rowKey];
                    if (this.countItems() <= 0) {
                        this.openCheckout = false;
                        this.redeemPoints = 0;
                    }
                },

                removeRow(rowKey) {
                    if (this.cart?.[rowKey]) delete this.cart[rowKey];
                    if (this.countItems() <= 0) {
                        this.openCheckout = false;
                        this.redeemPoints = 0;
                    }
                },

                openConfigFor(product) {
                    this.configProduct = product;
                    this.configSelections = {};
                    this.configError = '';

                    for (const opt of (product.options || [])) {
                        if (opt.type === 'multi') {
                            this.configSelections[opt.id] = [];
                        } else {
                            const first = (opt.values || [])[0];
                            this.configSelections[opt.id] = (opt.is_required && first) ? first.id : null;
                        }
                    }

                    this.openConfig = true;
                },

                closeConfig() {
                    this.openConfig = false;
                    this.configProduct = null;
                    this.configSelections = {};
                    this.configError = '';
                },

                configUnitPrice() {
                    const p = this.configProduct;
                    if (!p) return 0;
                    const base = Number(p.price || 0);
                    let extra = 0;

                    for (const opt of (p.options || [])) {
                        const selected = this.configSelections[opt.id];
                        if (opt.type === 'multi') {
                            const arr = Array.isArray(selected) ? selected : [];
                            for (const valId of arr) {
                                const vId = Number(valId);
                                const val = (opt.values || []).find(v => Number(v.id) === vId);
                                extra += Number(val?.price_delta || 0);
                            }
                        } else {
                            const vId = selected === null || selected === undefined || selected === '' ? null : Number(selected);
                            const val = (opt.values || []).find(v => Number(v.id) === vId);
                            extra += Number(val?.price_delta || 0);
                        }
                    }

                    return base + extra;
                },

                confirmConfig() {
                    const p = this.configProduct;
                    if (!p) return;

                    // Prevent confirming add-to-cart when table QR is not scanned
                    if (!this.requireTableToken()) return;

                    for (const opt of (p.options || [])) {
                        const selected = this.configSelections[opt.id];
                        if (opt.is_required) {
                            if (opt.type === 'multi') {
                                const arr = Array.isArray(selected) ? selected : [];
                                if (arr.length < 1) {
                                    this.configError = 'Pilih minimal 1 untuk: ' + opt.name;
                                    return;
                                }
                            } else {
                                if (selected === null || selected === undefined || selected === '') {
                                    this.configError = 'Pilih 1 untuk: ' + opt.name;
                                    return;
                                }
                            }
                        }
                    }

                    this.addVariant(p, this.configSelections);
                    this.closeConfig();
                },

                addVariant(product, selections) {
                    // Safety: prevent cart edits when table QR is not scanned
                    if (!this.requireTableToken()) return;

                    if (product?.track_stock) {
                        const stock = Number(product.stock ?? 0);
                        const currentQty = this.qtyByProduct(Number(product.id));
                        if (stock <= 0) {
                            this.showStatusFlash('error', 'Stok habis', (product?.name || 'Produk') + ' sedang habis.');
                            return;
                        }
                        if (currentQty >= stock) {
                            this.showStatusFlash('error', 'Stok tidak cukup', 'Maksimal ' + stock + ' untuk ' + (product?.name || 'produk') + '.');
                            return;
                        }
                    }

                    const base = Number(product.price || 0);
                    let optionsTotal = 0;
                    const optionsSnapshot = [];
                    const optionsSummaryParts = [];

                    for (const opt of (product.options || [])) {
                        const selected = selections ? selections[opt.id] : null;

                        if (opt.type === 'multi') {
                            const arr = Array.isArray(selected) ? selected : [];
                            if (arr.length < 1) continue;

                            const chosenValues = [];
                            for (const valId of arr) {
                                const vId = Number(valId);
                                const val = (opt.values || []).find(v => Number(v.id) === vId);
                                if (!val) continue;
                                optionsTotal += Number(val.price_delta || 0);
                                chosenValues.push({ value_id: val.id, value_name: val.name, price_delta: Number(val.price_delta || 0) });
                            }

                            if (chosenValues.length) {
                                optionsSnapshot.push({ option_id: opt.id, option_name: opt.name, type: opt.type, values: chosenValues });
                                optionsSummaryParts.push(opt.name + ': ' + chosenValues.map(v => v.value_name).join(', '));
                            }
                        } else {
                            if (selected === null || selected === undefined || selected === '') continue;
                            const vId = Number(selected);
                            const val = (opt.values || []).find(v => Number(v.id) === vId);
                            if (!val) continue;
                            optionsTotal += Number(val.price_delta || 0);
                            optionsSnapshot.push({ option_id: opt.id, option_name: opt.name, type: opt.type, values: [{ value_id: val.id, value_name: val.name, price_delta: Number(val.price_delta || 0) }] });
                            optionsSummaryParts.push(opt.name + ': ' + val.name);
                        }
                    }

                    const unitPrice = base + optionsTotal;

                    const signatureObj = optionsSnapshot
                        .map(o => ({ option_id: o.option_id, values: (o.values || []).map(v => v.value_id).slice().sort((a, b) => a - b) }))
                        .sort((a, b) => a.option_id - b.option_id);
                    const signature = JSON.stringify(signatureObj);
                    const key = String(product.id) + '|' + signature;

                    const current = this.cart[key]?.qty ?? 0;
                    this.cart[key] = {
                        key,
                        product_id: product.id,
                        name: product.name,
                        track_stock: !!product.track_stock,
                        stock: Number(product.stock ?? 0),
                        base_price: base,
                        options_total: optionsTotal,
                        unit_price: unitPrice,
                        options: optionsSnapshot,
                        options_summary: optionsSummaryParts.join(' · '),
                        qty: Math.min(current + 1, 99),
                    };
                },

                checkoutPayload() {
                    return this.cartList().map(r => {
                        const options = (r.options || []).map(o => {
                            if (o.type === 'multi') {
                                return { option_id: o.option_id, value_ids: (o.values || []).map(v => v.value_id) };
                            }
                            return { option_id: o.option_id, value_id: (o.values || [])[0]?.value_id };
                        });

                        return {
                            product_id: r.product_id,
                            qty: r.qty,
                            options,
                        };
                    });
                },

                total() {
                    return this.cartList().reduce((sum, r) => sum + ((r.unit_price || 0) * (r.qty || 0)), 0);
                },

                countItems() {
                    return this.cartList().reduce((sum, r) => sum + (r.qty || 0), 0);
                },
            }
        }
    </script>
</x-customer-layout>
