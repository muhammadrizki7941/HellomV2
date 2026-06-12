@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\Product> $packages */
    /** @var \Illuminate\Support\Collection<int, \App\Models\Category> $categories */
    /** @var string $tenant */
@endphp

@extends('layouts.admin-sidebar')

@section('header')
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Paket Menu</h2>
            <div class="text-xs text-gray-500">Kelola paket spesial dan event.</div>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('admin.packages.create') }}" class="inline-flex items-center rounded-xl bg-gray-900 text-white px-4 py-2 text-sm font-semibold shadow-sm hover:bg-gray-800">Buat Paket Baru</a>
        </div>
    </div>
@endsection

@section('content')
    <div class="py-6 sm:py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-2xl">
                <div class="p-4 sm:p-6 text-gray-900">
                    @if(session('success'))
                        <div class="mb-4 p-4 bg-green-50 border border-green-200 rounded-lg">
                            <div class="text-sm text-green-700">{{ session('success') }}</div>
                        </div>
                    @endif

                    @if($errors->any())
                        <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg">
                            <ul class="text-sm text-red-700">
                                @foreach($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <div x-data="packagesGrid(@js([
                        'packages' => $packages->map(function ($p) {
                            return [
                                'id' => (int) $p->id,
                                'name' => (string) $p->name,
                                'price' => (int) $p->price,
                                'description' => (string) ($p->description ?? ''),
                                'category_id' => (int) $p->category_id,
                                'category_ids' => $p->categories?->pluck('id')->map(fn ($v) => (int) $v)->values()->all() ?? [],
                                'categories' => ($p->categories ?? collect())->map(fn ($c) => ['id' => (int) $c->id, 'name' => (string) $c->name])->values()->all(),
                                'sort_order' => (int) ($p->sort_order ?? 0),
                                'is_available' => (bool) $p->is_available,
                                'track_stock' => (bool) $p->track_stock,
                                'stock' => $p->stock === null ? null : (int) $p->stock,
                                'show_as_banner' => (bool) $p->show_as_banner,
                                'banner_title' => (string) ($p->banner_title ?? ''),
                                'banner_subtitle' => (string) ($p->banner_subtitle ?? ''),
                                'banner_starts_at' => $p->banner_starts_at?->format('Y-m-d\\TH:i'),
                                'banner_ends_at' => $p->banner_ends_at?->format('Y-m-d\\TH:i'),
                                'image_url' => $p->image_path ? \Illuminate\Support\Facades\Storage::url($p->image_path) : null,
                                'package_items_count' => $p->packageItems?->count() ?? 0,
                                'remove_image' => false,
                            ];
                        })->values()->all(),
                        'categories' => $categories->map(fn ($c) => ['id' => (int) $c->id, 'name' => (string) $c->name])->values()->all(),
                        'routes' => [
                            'edit' => route('admin.packages.index'),
                            'destroy' => route('admin.packages.index') . '/',
                            'update' => route('admin.packages.index') . '/',
                        ],
                    ]))" class="mt-4">

                        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:gap-3">
                                <div class="text-sm text-gray-600">Total: <span class="font-semibold">{{ $packages->count() }}</span></div>

                                <div class="flex items-center gap-2">
                                    <select class="rounded-xl border-gray-300 text-sm focus:border-gray-400 focus:ring-gray-400" x-model.number="catFilter">
                                        <option value="0">Semua kategori</option>
                                        <template x-for="c in categories" :key="c.id">
                                            <option :value="c.id" x-text="c.name"></option>
                                        </template>
                                    </select>
                                    <button type="button" class="text-sm font-semibold text-gray-600 hover:text-gray-900" x-show="catFilter !== 0" x-cloak @click="catFilter = 0">Reset</button>
                                </div>
                            </div>

                            <div class="w-full sm:max-w-md">
                                <div class="relative">
                                    <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-gray-400">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="h-5 w-5">
                                            <path fill-rule="evenodd" d="M10.5 3.75a6.75 6.75 0 1 0 0 13.5 6.75 6.75 0 0 0 0-13.5ZM2.25 10.5a8.25 8.25 0 1 1 14.59 5.006l4.327 4.327a.75.75 0 1 1-1.06 1.06l-4.327-4.327A8.25 8.25 0 0 1 2.25 10.5Z" clip-rule="evenodd" />
                                        </svg>
                                    </div>
                                    <input type="text" placeholder="Search paket…" class="w-full rounded-xl border-gray-300 pl-10 focus:border-gray-400 focus:ring-gray-400" x-model="q" />
                                </div>
                            </div>
                        </div>

                        <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                            <template x-for="p in filtered" :key="p.id">
                                <div class="rounded-2xl border border-gray-200 bg-white overflow-hidden shadow-sm hover:shadow-md transition">
                                    <div class="relative">
                                        <template x-if="p.image_url">
                                            <img :src="p.image_url" class="h-36 sm:h-40 w-full object-cover" alt="" loading="lazy" />
                                        </template>
                                        <template x-if="!p.image_url">
                                            <div class="h-36 sm:h-40 w-full bg-gray-50 grid place-items-center">
                                                <div class="h-12 w-12 rounded-2xl grid place-items-center text-white font-black shadow-sm" style="background: var(--primary-color)">PAKET</div>
                                            </div>
                                        </template>
                                    </div>

                                    <div class="p-4">
                                        <div class="flex items-start justify-between gap-2">
                                            <div class="flex flex-wrap gap-1">
                                                <template x-if="p.show_as_banner">
                                                    <span class="text-[11px] font-semibold px-2 py-1 rounded-full bg-indigo-50 text-indigo-700 border border-indigo-200">Banner</span>
                                                </template>
                                            </div>
                                            <span class="shrink-0 text-[11px] font-semibold px-2 py-1 rounded-full" :class="p.is_available ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-gray-100 text-gray-700 border border-gray-200'" x-text="p.is_available ? 'Available' : 'Hidden'"></span>
                                        </div>

                                        <div class="mt-1 min-w-0 font-semibold leading-tight line-clamp-2" x-text="p.name"></div>
                                        <div class="mt-1 text-sm font-extrabold text-gray-900" x-text="'Rp ' + formatRp(p.price)"></div>

                                        <div class="mt-2 flex flex-wrap gap-1">
                                            <template x-for="(c, idx) in p.categories.slice(0, 2)" :key="c.id">
                                                <span class="text-[11px] font-semibold px-2 py-1 rounded-full bg-gray-50 text-gray-700 border border-gray-200" x-text="c.name"></span>
                                            </template>
                                            <template x-if="p.categories.length > 2">
                                                <span class="text-[11px] font-semibold px-2 py-1 rounded-full bg-gray-50 text-gray-500 border border-gray-200" x-text="'+' + (p.categories.length - 2)"></span>
                                            </template>
                                        </div>

                                        <div class="mt-2 text-xs text-gray-500">
                                            <span x-text="p.package_items_count + ' item' + (p.package_items_count !== 1 ? 's' : '')"></span>
                                        </div>

                                        <div class="mt-3 flex items-center justify-between gap-2">
                                            <div class="text-xs text-gray-500" x-text="p.track_stock ? ('Stok: ' + (p.stock ?? 0)) : 'Stok: ∞'"></div>
                                            <div class="inline-flex items-center rounded-xl border border-gray-200 bg-white shadow-sm overflow-hidden">
                                                <button type="button" class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold text-gray-900 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-gray-300" @click="openEdit(p)">
                                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="h-4 w-4 text-gray-500">
                                                        <path d="M21.731 2.269a2.625 2.625 0 0 0-3.712 0l-1.157 1.157 3.712 3.712 1.157-1.157a2.625 2.625 0 0 0 0-3.712Z" />
                                                        <path d="M19.513 8.199 15.8 4.487l-9.563 9.563a1.5 1.5 0 0 0-.402.757l-.545 3.272a.75.75 0 0 0 .862.862l3.272-.545a1.5 1.5 0 0 0 .757-.402l9.563-9.563Z" />
                                                    </svg>
                                                    Edit
                                                </button>
                                                <div class="h-8 w-px bg-gray-200"></div>
                                                <a class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-gray-300" :href="routes.edit + '/' + p.id + '/edit'">
                                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="h-4 w-4 text-gray-500">
                                                        <path fill-rule="evenodd" d="M15 2.25H9A2.25 2.25 0 0 0 6.75 4.5v15A2.25 2.25 0 0 0 9 21.75h6A2.25 2.25 0 0 0 17.25 19.5v-15A2.25 2.25 0 0 0 15 2.25ZM9 4.5a.75.75 0 0 0-.75.75v15c0 .414.336.75.75.75h6a.75.75 0 0 .75-.75v-15A.75.75 0 0 0 15 4.5H9Z" clip-rule="evenodd" />
                                                    </svg>
                                                    Detail
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>

                        <div class="mt-4 text-sm text-gray-500" x-show="filtered.length === 0" x-cloak>Tidak ada paket yang cocok.</div>

                        <!-- Edit modal -->
                        <div class="fixed inset-0 z-[70]" x-show="editOpen" x-transition x-cloak @keydown.escape.window="closeEdit()">
                            <div class="absolute inset-0 bg-black/40" @click="closeEdit()"></div>
                            <div class="relative min-h-full w-full p-4 sm:p-6 flex items-center justify-center">
                                <div class="w-full max-w-4xl max-h-[90vh] rounded-2xl bg-white shadow-xl border border-gray-200 overflow-hidden">
                                    <form method="POST" :action="routes.update + form.id" enctype="multipart/form-data" class="h-full max-h-[90vh] flex flex-col">
                                        @csrf
                                        <input type="hidden" name="_method" value="PUT" />

                                        <div class="flex items-start justify-between gap-3 px-5 py-4 border-b border-gray-100">
                                            <div>
                                                <div class="text-xs font-semibold text-gray-500">QUICK EDIT</div>
                                                <div class="text-lg font-semibold" x-text="form.id ? ('Edit: ' + form.name) : 'Edit Package'"></div>
                                                <div class="text-xs text-gray-500">Modal ini scrollable (responsif).</div>
                                            </div>
                                            <button type="button" class="rounded-xl border border-gray-300 px-3 py-2 text-sm font-semibold hover:bg-gray-50" @click="closeEdit()">Close</button>
                                        </div>

                                        <div class="flex-1 overflow-y-auto p-5">
                                            <div class="grid gap-4 lg:grid-cols-2">
                                                <div class="grid gap-4">
                                                    <div class="grid sm:grid-cols-2 gap-3">
                                                        <div>
                                                            <label class="text-sm font-medium">Primary category</label>
                                                            <select name="category_id" class="mt-1 w-full rounded-xl border-gray-300" x-model.number="form.category_id" required>
                                                                <template x-for="c in categories" :key="c.id">
                                                                    <option :value="c.id" x-text="c.name"></option>
                                                                </template>
                                                            </select>
                                                        </div>
                                                        <div>
                                                            <label class="text-sm font-medium">Price</label>
                                                            <input name="price" type="number" min="0" class="mt-1 w-full rounded-xl border-gray-300" x-model.number="form.price" required />
                                                        </div>
                                                    </div>

                                                    <div>
                                                        <label class="text-sm font-medium">Name</label>
                                                        <input name="name" class="mt-1 w-full rounded-xl border-gray-300" x-model="form.name" required />
                                                    </div>

                                                    <div>
                                                        <label class="text-sm font-medium">Description</label>
                                                        <textarea name="description" rows="3" class="mt-1 w-full rounded-xl border-gray-300" x-model="form.description"></textarea>
                                                    </div>

                                                    <div>
                                                        <label class="text-sm font-medium">Additional categories (optional)</label>
                                                        <div class="mt-2 grid sm:grid-cols-2 gap-2 max-h-44 overflow-auto rounded-xl border border-gray-200 p-3 bg-gray-50/40">
                                                            <template x-for="c in categories" :key="c.id">
                                                                <label class="inline-flex items-center gap-2">
                                                                    <input type="checkbox" name="category_ids[]" :value="c.id" class="rounded border-gray-300" :checked="form.category_ids && form.category_ids.includes(c.id)" @change="toggleCat(c.id, $event.target.checked)" />
                                                                    <span class="text-sm" x-text="c.name"></span>
                                                                </label>
                                                            </template>
                                                        </div>
                                                        <div class="text-xs text-gray-500 mt-2">Paket akan tampil di semua kategori yang dicentang.</div>
                                                    </div>

                                                    <div class="grid sm:grid-cols-2 gap-3">
                                                        <label class="inline-flex items-center gap-2">
                                                            <input type="hidden" name="is_available" value="0" />
                                                            <input type="checkbox" name="is_available" value="1" class="rounded border-gray-300" :checked="form.is_available" @change="form.is_available = $event.target.checked" />
                                                            <span class="text-sm">Available</span>
                                                        </label>
                                                        <div>
                                                            <label class="text-sm font-medium">Sort order</label>
                                                            <input name="sort_order" type="number" min="0" max="10000" class="mt-1 w-full rounded-xl border-gray-300" x-model.number="form.sort_order" />
                                                        </div>
                                                    </div>
                                                </div>

                                                <div class="grid gap-4">
                                                    <div class="rounded-2xl border border-gray-200 p-4 grid gap-3">
                                                        <div class="text-sm font-semibold">Stok / Limit Order</div>
                                                        <label class="inline-flex items-center gap-2">
                                                            <input type="hidden" name="track_stock" value="0" />
                                                            <input type="checkbox" name="track_stock" value="1" class="rounded border-gray-300" :checked="form.track_stock" @change="form.track_stock = $event.target.checked" />
                                                            <span class="text-sm">Aktifkan stok</span>
                                                        </label>
                                                        <div>
                                                            <label class="text-sm font-medium">Jumlah stok</label>
                                                            <input name="stock" type="number" min="0" class="mt-1 w-full rounded-xl border-gray-300" :disabled="!form.track_stock" :value="form.stock ?? ''" />
                                                        </div>
                                                    </div>

                                                    <div class="rounded-2xl border border-gray-200 p-4 grid gap-3">
                                                        <div class="text-sm font-semibold">Banner di Customer App</div>
                                                        <label class="inline-flex items-center gap-2">
                                                            <input type="hidden" name="show_as_banner" value="0" />
                                                            <input type="checkbox" name="show_as_banner" value="1" class="rounded border-gray-300" :checked="form.show_as_banner" @change="form.show_as_banner = $event.target.checked" />
                                                            <span class="text-sm">Tampilkan sebagai banner</span>
                                                        </label>
                                                        <div class="grid sm:grid-cols-2 gap-3">
                                                            <div>
                                                                <label class="text-sm font-medium">Judul banner</label>
                                                                <input name="banner_title" class="mt-1 w-full rounded-xl border-gray-300" x-model="form.banner_title" />
                                                            </div>
                                                            <div>
                                                                <label class="text-sm font-medium">Subjudul banner</label>
                                                                <input name="banner_subtitle" class="mt-1 w-full rounded-xl border-gray-300" x-model="form.banner_subtitle" />
                                                            </div>
                                                        </div>
                                                        <div class="grid sm:grid-cols-2 gap-3">
                                                            <div>
                                                                <label class="text-sm font-medium">Mulai</label>
                                                                <input name="banner_starts_at" type="datetime-local" class="mt-1 w-full rounded-xl border-gray-300" x-model="form.banner_starts_at" />
                                                            </div>
                                                            <div>
                                                                <label class="text-sm font-medium">Selesai</label>
                                                                <input name="banner_ends_at" type="datetime-local" class="mt-1 w-full rounded-xl border-gray-300" x-model="form.banner_ends_at" />
                                                            </div>
                                                        </div>
                                                    </div>

                                                    <div class="rounded-2xl border border-gray-200 p-4 grid gap-3">
                                                        <div class="text-sm font-semibold">Image</div>
                                                        <template x-if="form.image_url">
                                                            <img :src="form.image_url" class="h-28 w-44 object-cover rounded-xl border" alt="" />
                                                        </template>
                                                        <label class="inline-flex items-center gap-2" x-show="form.image_url">
                                                            <input type="checkbox" name="remove_image" value="1" class="rounded border-gray-300" />
                                                            <span class="text-sm">Remove current image</span>
                                                        </label>
                                                        <input name="image" type="file" accept="image/*" class="w-full" />
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="border-t border-gray-100 bg-white px-5 py-4 flex flex-wrap items-center gap-3">
                                            <button class="rounded-xl bg-gray-900 text-white px-4 py-2 text-sm font-semibold shadow-sm hover:bg-gray-800">Save</button>
                                            <a class="text-sm font-semibold text-gray-600 hover:text-gray-900" :href="routes.edit + '/' + form.id + '/edit'">Open full editor</a>
                                            <button type="button" class="ml-auto text-sm font-semibold text-red-600 hover:text-red-700" @click="confirmDelete = true">Delete</button>
                                        </div>
                                    </form>

                                    <form method="POST" :action="routes.destroy + form.id" class="border-t border-red-100 bg-red-50 px-5 py-4" x-show="confirmDelete" x-cloak>
                                        @csrf
                                        <input type="hidden" name="_method" value="DELETE" />
                                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                                            <div class="text-sm text-red-800">Yakin hapus paket ini?</div>
                                            <div class="flex items-center gap-2">
                                                <button type="button" class="text-sm font-semibold text-gray-700" @click="confirmDelete = false">Batal</button>
                                                <button class="rounded-xl bg-red-600 text-white px-3 py-2 text-sm font-semibold" onclick="return confirm('Delete package?')">Hapus</button>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <script>
                            function packagesGrid(cfg) {
                                return {
                                    q: '',
                                    catFilter: 0,
                                    routes: cfg.routes,
                                    categories: cfg.categories || [],
                                    all: cfg.packages || [],
                                    editOpen: false,
                                    confirmDelete: false,
                                    form: {
                                        category_ids: []
                                    },

                                    get filtered() {
                                        const q = String(this.q || '').toLowerCase().trim();
                                        const catId = Number(this.catFilter || 0);

                                        return this.all.filter(p => {
                                            if (catId) {
                                                const ids = Array.isArray(p.category_ids) ? p.category_ids.map(Number) : [];
                                                const primary = Number(p.category_id || 0);
                                                if (!(ids.includes(catId) || primary === catId)) return false;
                                            }

                                            if (q) {
                                                const name = String(p.name || '').toLowerCase();
                                                const cats = (p.categories || []).map(c => String(c.name || '').toLowerCase()).join(' ');
                                                return name.includes(q) || cats.includes(q);
                                            }

                                            return true;
                                        });
                                    },

                                    formatRp(n) {
                                        const num = Number(n || 0);
                                        return new Intl.NumberFormat('id-ID').format(num);
                                    },

                                    openEdit(p) {
                                        // clone so edits don't mutate the grid until saved
                                        this.form = JSON.parse(JSON.stringify(p));
                                        if (!Array.isArray(this.form.category_ids)) this.form.category_ids = [];
                                        this.editOpen = true;
                                    },
                                    closeEdit() {
                                        this.editOpen = false;
                                        this.confirmDelete = false;
                                        this.form = {
                                            category_ids: []
                                        };
                                    },
                                    toggleCat(id, checked) {
                                        const n = Number(id);
                                        const set = new Set(this.form.category_ids.map(Number));
                                        if (checked) set.add(n);
                                        else set.delete(n);
                                        this.form.category_ids = Array.from(set);
                                    },
                                }
                            }
                        </script>
                    </div>

                    <div class="mt-4 text-sm text-gray-500" x-show="all.length === 0" x-cloak>Belum ada paket.</div>
                </div>
            </div>
        </div>
    </div>
@endsection