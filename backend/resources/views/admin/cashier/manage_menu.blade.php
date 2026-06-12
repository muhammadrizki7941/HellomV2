@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\Product> $products */
    /** @var \Illuminate\Support\Collection<int, \App\Models\Category> $categories */
    /** @var \Illuminate\Support\Collection<int, \App\Models\DiningTable> $tables */
    /** @var \App\Models\PaymentSetting|null $paymentSetting */
    /** @var array<string,string> $paymentMethods */

    $payload = [
        'categories' => $categories->map(fn ($c) => [
            'id' => (int) $c->id,
            'name' => (string) $c->name,
        ])->values()->all(),
        'products' => $products->map(function ($p) {
            $categoryIds = $p->categories?->pluck('id')->map(fn ($v) => (int) $v)->values()->all() ?? [];
            if (empty($categoryIds) && !empty($p->category_id)) {
                $categoryIds = [(int) $p->category_id];
            }

            return [
                'id' => (int) $p->id,
                'name' => (string) $p->name,
                'price' => (int) $p->price,
                'description' => (string) ($p->description ?? ''),
                'image_url' => $p->imageUrl(),
                'track_stock' => (bool) $p->track_stock,
                'stock' => $p->stock === null ? null : (int) $p->stock,
                'category_id' => !empty($p->category_id) ? (int) $p->category_id : (isset($categoryIds[0]) ? (int) $categoryIds[0] : null),
                'category_ids' => $categoryIds,
                'options' => ($p->options ?? collect())->map(function ($opt) {
                    return [
                        'id' => (int) $opt->id,
                        'name' => (string) $opt->name,
                        'type' => (string) $opt->type,
                        'is_required' => (bool) $opt->is_required,
                        'values' => ($opt->values ?? collect())->map(fn ($v) => [
                            'id' => (int) $v->id,
                            'name' => (string) $v->name,
                            'price_delta' => (int) $v->price_delta,
                        ])->values()->all(),
                    ];
                })->values()->all(),
            ];
        })->values()->all(),
        'paymentMethods' => $paymentMethods ?? [],
        'cashierSettings' => [
            'auto_complete_when_paid' => (bool) ($paymentSetting?->auto_complete_when_paid ?? true),
            'require_paid_before_submit' => (bool) ($paymentSetting?->require_paid_before_submit ?? false),
        ],
    ];
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ __('ui.manage_menu_title') }} - {{ config('app.name', 'Laravel') }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full font-sans antialiased bg-gray-50">
    <div class="h-full flex" x-data="menuManager({
        categories: @js($payload['categories']),
        allProducts: @js($payload['products']),
        urls: {
            categoriesStore: @js(route('admin.cashier.menu.categories.store')),
            categoriesUpdateBase: @js(route('admin.cashier.menu.categories.store')),
            productsStore: @js(route('admin.cashier.menu.products.store')),
            productsUpdateBase: @js(route('admin.cashier.menu.products.store')),
            productOptionsBase: @js(route('admin.cashier.menu.products.store')),
        },
        i18n: {
            categoriesLabel: @js(__('ui.categories')),
            productsLabel: @js(__('ui.products')),
            productsCountSuffix: @js(__('ui.count_products_suffix')),
            categoriesCountSuffix: @js(__('ui.count_categories_suffix')),
            noCategory: @js(__('ui.no_category')),
            addCategory: @js(__('ui.add_category')),
            addProduct: @js(__('ui.add_product')),
            editCategory: @js(__('ui.edit_category')),
            editProduct: @js(__('ui.edit_product')),
            create: @js(__('ui.create')),
            update: @js(__('ui.update')),
            cancel: @js(__('ui.cancel')),
            categoryName: @js(__('ui.category_name')),
            enterCategoryName: @js(__('ui.enter_category_name')),
            productName: @js(__('ui.product_name')),
            enterProductName: @js(__('ui.enter_product_name')),
            price: @js(__('ui.price')),
            category: @js(__('ui.category')),
            selectCategory: @js(__('ui.select_category')),
            descriptionOptional: @js(__('ui.description_optional')),
            productDescription: @js(__('ui.product_description')),
            imageOptional: @js(__('ui.image_optional')),
            imageHelp: @js(__('ui.image_help')),
            saveCategorySuccess: @js(__('ui.save_category_success')),
            saveCategoryFailed: @js(__('ui.save_category_failed')),
            deleteCategoryConfirm: @js(__('ui.delete_category_confirm')),
            deleteCategorySuccess: @js(__('ui.delete_category_success')),
            deleteCategoryFailed: @js(__('ui.delete_category_failed')),
            saveProductSuccess: @js(__('ui.save_product_success')),
            saveProductFailed: @js(__('ui.save_product_failed')),
            deleteProductConfirm: @js(__('ui.delete_product_confirm')),
            deleteProductSuccess: @js(__('ui.delete_product_success')),
            deleteProductFailed: @js(__('ui.delete_product_failed')),
        },
        showCategoryModal: false,
        showProductModal: false,
        showAddonModal: false,
        editingCategory: null,
        editingProduct: null,
        addonProduct: null,
        addonOptionForm: { name: '', type: 'single', is_required: false },
        editingAddonOptionId: null,
        addonValueForm: { option_id: null, name: '', price_delta: 0 },
        editingAddonValueId: null,
        categoryForm: { name: '' },
        productForm: {
            name: '',
            price: 0,
            category_id: '',
            description: '',
            image: null
        }
    })">
        @include('admin.cashier._sidebar')

        <!-- Main Content Area -->
        <div class="flex-1 flex flex-col overflow-hidden">
            <!-- Top Header -->
            <div class="bg-white border-b border-gray-200 px-4 md:px-6 py-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center gap-3">
                        <button type="button" class="px-2 py-1 rounded-md border bg-white text-gray-700 hover:bg-gray-50" onclick="cashierToggleSidebar()" title="Tampilkan/Sembunyikan menu">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                        </button>
                        <div>
                        <h1 class="text-xl font-bold text-gray-900">{{ __('ui.manage_menu_title') }}</h1>
                        <p class="text-sm text-gray-600">{{ __('ui.manage_menu_subtitle') }}</p>
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-2 w-full sm:w-auto">
                        <button type="button" @click="showCategoryModal = true; editingCategory = null; categoryForm.name = ''" class="px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 text-sm font-semibold">
                            + {{ __('ui.add_category') }}
                        </button>
                        <button type="button" @click="showProductModal = true; editingProduct = null; resetProductForm()" class="px-4 py-2 bg-emerald-500 text-white rounded-lg hover:bg-emerald-600 text-sm font-semibold">
                            + {{ __('ui.add_product') }}
                        </button>
                    </div>
                </div>
            </div>

            <!-- Content -->
            <div class="flex-1 overflow-y-auto p-4 md:p-6 space-y-8">
                <!-- Categories Section -->
                <div>
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-lg font-semibold text-gray-900">{{ __('ui.categories') }}</h2>
                        <span class="text-sm text-gray-500" x-text="categories.length + ' ' + i18n.categoriesCountSuffix"></span>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                        <template x-for="category in categories" :key="category.id">
                            <div class="bg-white border border-gray-200 rounded-lg p-4 hover:shadow-sm transition">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-3">
                                        <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center">
                                            <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
                                            </svg>
                                        </div>
                                        <div>
                                            <h4 class="font-semibold text-gray-900" x-text="category.name"></h4>
                                            <p class="text-xs text-gray-500" x-text="getProductsCount(category.id) + ' ' + i18n.productsCountSuffix"></p>
                                        </div>
                                    </div>
                                    <div class="flex gap-1">
                                        <button type="button" @click="editCategory(category)" class="p-1 text-gray-400 hover:text-blue-600">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                            </svg>
                                        </button>
                                        <button type="button" @click="deleteCategory(category.id)" class="p-1 text-gray-400 hover:text-red-600">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                            </svg>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>

                <!-- Products Section -->
                <div>
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-lg font-semibold text-gray-900">{{ __('ui.products') }}</h2>
                        <span class="text-sm text-gray-500" x-text="allProducts.length + ' ' + i18n.productsCountSuffix"></span>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                        <template x-for="product in allProducts" :key="product.id">
                            <div class="bg-white border border-gray-200 rounded-lg overflow-hidden hover:shadow-sm transition">
                                <div class="bg-gray-50 relative h-32">
                                    <img x-show="product.image_url" :src="product.image_url" :alt="product.name" class="w-full h-full object-cover" onerror="if(this.dataset.retryImg){return;} this.dataset.retryImg='1'; const u=new URL(this.src, window.location.origin); u.searchParams.set('_img_retry', Date.now().toString()); this.src=u.toString();" />
                                    <div x-show="!product.image_url" class="w-full h-full flex items-center justify-center text-gray-400">
                                        <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                        </svg>
                                    </div>
                                </div>
                                <div class="p-4">
                                    <h4 class="font-semibold text-gray-900 text-sm mb-1" x-text="product.name"></h4>
                                    <p class="text-xs text-gray-600 mb-2">Rp <span x-text="formatRp(product.price)"></span></p>
                                    <div class="flex items-center justify-between">
                                        <span class="text-xs text-gray-500" x-text="getCategoryName(product.category_id || product.category_ids?.[0])"></span>
                                        <div class="flex gap-1">
                                            <button type="button" @click="openAddonModal(product)" class="p-1 text-gray-400 hover:text-indigo-600" title="Kelola Add-on">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                                                </svg>
                                            </button>
                                            <button type="button" @click="editProduct(product)" class="p-1 text-gray-400 hover:text-blue-600">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                                </svg>
                                            </button>
                                            <button type="button" @click="deleteProduct(product.id)" class="p-1 text-gray-400 hover:text-red-600">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                                </svg>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </div>

        <!-- Category Modal -->
        <div x-show="showCategoryModal" x-cloak x-transition class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-black/40" @click="closeCategoryModal()"></div>
            <div class="relative w-full max-w-md bg-white rounded-3xl shadow-xl overflow-hidden">
                <div class="px-6 py-4 border-b flex items-center justify-between">
                    <h3 class="font-bold text-gray-900" x-text="editingCategory ? i18n.editCategory : i18n.addCategory"></h3>
                    <button type="button" @click="closeCategoryModal()" class="text-gray-400 hover:text-gray-600">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <form @submit.prevent="saveCategory()" class="px-6 py-4 space-y-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2" x-text="i18n.categoryName"></label>
                        <input type="text" x-model="categoryForm.name" required class="w-full rounded-xl border-gray-200 text-sm" :placeholder="i18n.enterCategoryName" />
                    </div>

                    <div class="flex gap-3 pt-4">
                        <button type="button" @click="closeCategoryModal()" class="flex-1 px-4 py-2 rounded-xl bg-gray-100 text-gray-700 font-semibold hover:bg-gray-200">
                            <span x-text="i18n.cancel"></span>
                        </button>
                        <button type="submit" :disabled="categoryForm.name.trim() === ''" class="flex-1 px-4 py-2 rounded-xl bg-blue-500 text-white font-semibold hover:bg-blue-600 disabled:opacity-50">
                            <span x-text="editingCategory ? i18n.update : i18n.create"></span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Product Modal -->
        <div x-show="showProductModal" x-cloak x-transition class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-black/40" @click="closeProductModal()"></div>
            <div class="relative w-full max-w-2xl bg-white rounded-3xl shadow-xl overflow-hidden">
                <div class="px-6 py-4 border-b flex items-center justify-between">
                    <h3 class="font-bold text-gray-900" x-text="editingProduct ? i18n.editProduct : i18n.addProduct"></h3>
                    <button type="button" @click="closeProductModal()" class="text-gray-400 hover:text-gray-600">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <form @submit.prevent="saveProduct()" class="px-6 py-4 space-y-4 max-h-[70vh] overflow-y-auto">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2" x-text="i18n.productName"></label>
                            <input type="text" x-model="productForm.name" required class="w-full rounded-xl border-gray-200 text-sm" :placeholder="i18n.enterProductName" />
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2" x-text="i18n.price"></label>
                            <input type="number" x-model.number="productForm.price" required min="0" class="w-full rounded-xl border-gray-200 text-sm" placeholder="0" />
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2" x-text="i18n.category"></label>
                        <select x-model="productForm.category_id" required class="w-full rounded-xl border-gray-200 text-sm">
                            <option value="" x-text="i18n.selectCategory"></option>
                            <template x-for="category in categories" :key="category.id">
                                <option :value="category.id" x-text="category.name"></option>
                            </template>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2" x-text="i18n.descriptionOptional"></label>
                        <textarea x-model="productForm.description" rows="3" class="w-full rounded-xl border-gray-200 text-sm" :placeholder="i18n.productDescription"></textarea>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2" x-text="i18n.imageOptional"></label>
                        <input type="file" @change="handleImageUpload($event)" accept="image/*" class="w-full text-sm" />
                        <p class="text-xs text-gray-500 mt-1" x-text="i18n.imageHelp"></p>
                    </div>

                    <div class="rounded-2xl border border-gray-200 bg-gray-50 p-4">
                        <div class="text-sm font-semibold text-gray-900">Preview Produk</div>
                        <div class="mt-3 flex gap-3">
                            <template x-if="productImagePreview">
                                <img :src="productImagePreview" alt="Preview" class="h-16 w-16 rounded-xl border object-cover" />
                            </template>
                            <template x-if="!productImagePreview">
                                <div class="h-16 w-16 rounded-xl border bg-white grid place-items-center text-[10px] text-gray-400">No Image</div>
                            </template>
                            <div class="min-w-0">
                                <div class="font-semibold text-gray-900 truncate" x-text="productForm.name || 'Nama produk belum diisi'"></div>
                                <div class="text-xs text-gray-500 mt-1" x-text="productForm.description || 'Deskripsi produk akan tampil di sini'"></div>
                                <div class="text-sm font-bold text-emerald-700 mt-1">Rp <span x-text="formatRp(productForm.price || 0)"></span></div>
                            </div>
                        </div>
                    </div>

                    <div class="flex gap-3 pt-4">
                        <button type="button" @click="closeProductModal()" class="flex-1 px-4 py-2 rounded-xl bg-gray-100 text-gray-700 font-semibold hover:bg-gray-200">
                            <span x-text="i18n.cancel"></span>
                        </button>
                        <button type="submit" :disabled="!isProductFormValid()" class="flex-1 px-4 py-2 rounded-xl bg-emerald-500 text-white font-semibold hover:bg-emerald-600 disabled:opacity-50">
                            <span x-text="editingProduct ? i18n.update : i18n.create"></span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Add-on Modal -->
        <div x-show="showAddonModal" x-cloak x-transition class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-black/40" @click="closeAddonModal()"></div>
            <div class="relative w-full max-w-4xl bg-white rounded-3xl shadow-xl overflow-hidden">
                <div class="px-6 py-4 border-b flex items-center justify-between">
                    <div>
                        <h3 class="font-bold text-gray-900">Kelola Add-on Produk</h3>
                        <p class="text-xs text-gray-500" x-text="addonProduct ? addonProduct.name : ''"></p>
                    </div>
                    <button type="button" @click="closeAddonModal()" class="text-gray-400 hover:text-gray-600">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <div class="px-6 py-4 max-h-[70vh] overflow-y-auto space-y-5" x-show="addonProduct">
                    <div class="rounded-2xl border border-gray-200 p-4">
                        <div class="text-sm font-semibold text-gray-900" x-text="editingAddonOptionId ? 'Ubah Grup Add-on' : 'Tambah Grup Add-on'"></div>
                        <div class="mt-3 grid sm:grid-cols-3 gap-3">
                            <input type="text" x-model="addonOptionForm.name" class="rounded-xl border-gray-200 text-sm" placeholder="Contoh: Level Pedas" />
                            <select x-model="addonOptionForm.type" class="rounded-xl border-gray-200 text-sm">
                                <option value="single">Pilih satu</option>
                                <option value="multi">Bisa pilih banyak</option>
                            </select>
                            <label class="inline-flex items-center gap-2 rounded-xl border border-gray-200 px-3 py-2 text-sm">
                                <input type="checkbox" x-model="addonOptionForm.is_required" class="rounded border-gray-300" />
                                <span>Wajib dipilih</span>
                            </label>
                        </div>
                        <div class="mt-3 flex gap-2">
                            <button type="button" @click="saveAddonOption()" class="px-4 py-2 rounded-xl bg-emerald-500 text-white text-sm font-semibold hover:bg-emerald-600" x-text="editingAddonOptionId ? 'Simpan Perubahan' : 'Tambah Grup'"></button>
                            <button type="button" @click="resetAddonOptionForm()" class="px-4 py-2 rounded-xl bg-gray-100 text-gray-700 text-sm font-semibold hover:bg-gray-200">Reset</button>
                        </div>
                    </div>

                    <template x-if="!(addonProduct.options || []).length">
                        <div class="rounded-2xl border border-dashed border-gray-300 p-6 text-sm text-gray-500 text-center">Belum ada add-on untuk produk ini.</div>
                    </template>

                    <div class="space-y-3" x-show="(addonProduct?.options || []).length > 0">
                        <template x-for="opt in (addonProduct?.options || [])" :key="opt.id">
                            <div class="rounded-2xl border border-gray-200 p-4">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <div class="font-semibold text-gray-900" x-text="opt.name"></div>
                                        <div class="text-xs text-gray-500" x-text="(opt.type === 'multi' ? 'Bisa pilih banyak' : 'Pilih satu') + ' · ' + (opt.is_required ? 'Wajib' : 'Opsional')"></div>
                                    </div>
                                    <div class="flex gap-1">
                                        <button type="button" @click="editAddonOption(opt)" class="px-3 py-1.5 rounded-lg border text-xs font-semibold text-gray-700 hover:bg-gray-50">Ubah</button>
                                        <button type="button" @click="deleteAddonOption(opt.id)" class="px-3 py-1.5 rounded-lg border border-red-200 text-xs font-semibold text-red-700 hover:bg-red-50">Hapus</button>
                                    </div>
                                </div>

                                <div class="mt-3 space-y-2">
                                    <template x-for="val in (opt.values || [])" :key="val.id">
                                        <div class="rounded-xl border border-gray-100 bg-gray-50 px-3 py-2 flex items-center justify-between text-sm">
                                            <div class="font-medium" x-text="val.name"></div>
                                            <div class="flex items-center gap-2">
                                                <span class="text-xs text-gray-600">+Rp <span x-text="formatRp(val.price_delta)"></span></span>
                                                <button type="button" @click="editAddonValue(opt.id, val)" class="text-xs font-semibold text-gray-700 hover:text-gray-900">Ubah</button>
                                                <button type="button" @click="deleteAddonValue(opt.id, val.id)" class="text-xs font-semibold text-red-700 hover:text-red-800">Hapus</button>
                                            </div>
                                        </div>
                                    </template>
                                </div>

                                <div class="mt-3 rounded-xl border border-gray-200 p-3 bg-white">
                                    <div class="grid sm:grid-cols-3 gap-2">
                                        <input type="text" x-model="addonValueForm.name" class="rounded-lg border-gray-200 text-sm" placeholder="Nama pilihan add-on" x-show="addonValueForm.option_id === opt.id" />
                                        <input type="number" min="0" x-model.number="addonValueForm.price_delta" class="rounded-lg border-gray-200 text-sm" placeholder="Tambahan harga" x-show="addonValueForm.option_id === opt.id" />
                                        <div class="flex gap-2" x-show="addonValueForm.option_id === opt.id">
                                            <button type="button" @click="saveAddonValue()" class="px-3 py-2 rounded-lg bg-emerald-500 text-white text-xs font-semibold hover:bg-emerald-600" x-text="editingAddonValueId ? 'Simpan' : 'Tambah'"></button>
                                            <button type="button" @click="openAddonValueForm(opt.id)" class="px-3 py-2 rounded-lg bg-gray-100 text-gray-700 text-xs font-semibold hover:bg-gray-200">Reset</button>
                                        </div>
                                        <button type="button" x-show="addonValueForm.option_id !== opt.id" @click="openAddonValueForm(opt.id)" class="sm:col-span-3 px-3 py-2 rounded-lg bg-gray-100 text-gray-700 text-xs font-semibold hover:bg-gray-200">Tambah Pilihan pada Grup Ini</button>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function menuManager(cfg) {
            return {
                categories: cfg.categories || [],
                allProducts: cfg.allProducts || [],
                i18n: cfg.i18n || {},
                showCategoryModal: false,
                showProductModal: false,
                editingCategory: null,
                editingProduct: null,
                categoryForm: { name: '' },
                urls: cfg.urls || {},
                productForm: {
                    name: '',
                    price: 0,
                    category_id: '',
                    description: '',
                    image: null
                },
                productImagePreview: null,

                init() {
                    // Initialize any required setup
                },

                // Category methods
                editCategory(category) {
                    this.editingCategory = category;
                    this.categoryForm.name = category.name;
                    this.showCategoryModal = true;
                },

                closeCategoryModal() {
                    this.showCategoryModal = false;
                    this.editingCategory = null;
                    this.categoryForm.name = '';
                },

                async saveCategory() {
                    if (!this.categoryForm.name.trim()) return;

                    try {
                        const url = this.editingCategory
                            ? `${this.urls.categoriesUpdateBase}/${this.editingCategory.id}`
                            : this.urls.categoriesStore;
                        const method = this.editingCategory ? 'PUT' : 'POST';

                        const response = await fetch(url, {
                            method: method,
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                            },
                            body: JSON.stringify({ name: this.categoryForm.name.trim() })
                        });

                        if (response.ok) {
                            const result = await response.json();
                            if (this.editingCategory) {
                                // Update existing category
                                const index = this.categories.findIndex(c => c.id === this.editingCategory.id);
                                if (index !== -1) {
                                    this.categories[index] = result.category;
                                }
                            } else {
                                // Add new category
                                this.categories.push(result.category);
                            }
                            this.closeCategoryModal();
                            this.showNotification(this.i18n.saveCategorySuccess || 'Category saved', 'success');
                        } else {
                            const text = await response.text();
                            throw new Error(text || (this.i18n.saveCategoryFailed || 'Failed to save category'));
                        }
                    } catch (error) {
                        console.error('Error saving category:', error);
                        this.showNotification(this.i18n.saveCategoryFailed || 'Failed to save category', 'error');
                    }
                },

                async deleteCategory(categoryId) {
                    const confirmed = await this.confirmAction(this.i18n.deleteCategoryConfirm || 'Delete this category?');
                    if (!confirmed) return;

                    try {
                        const response = await fetch(`${this.urls.categoriesUpdateBase}/${categoryId}`, {
                            method: 'DELETE',
                            headers: {
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                            }
                        });

                        if (response.ok) {
                            this.categories = this.categories.filter(c => c.id !== categoryId);
                            this.showNotification(this.i18n.deleteCategorySuccess || 'Category deleted', 'success');
                        } else {
                            const text = await response.text();
                            throw new Error(text || (this.i18n.deleteCategoryFailed || 'Failed to delete category'));
                        }
                    } catch (error) {
                        console.error('Error deleting category:', error);
                        this.showNotification(this.i18n.deleteCategoryFailed || 'Failed to delete category', 'error');
                    }
                },

                // Product methods
                editProduct(product) {
                    this.editingProduct = product;
                    this.productForm = {
                        name: product.name,
                        price: product.price,
                        category_id: product.category_id || product.category_ids?.[0] || '',
                        description: product.description || '',
                        image: null
                    };
                    this.productImagePreview = product.image_url || null;
                    this.showProductModal = true;
                },

                resetProductForm() {
                    this.productForm = {
                        name: '',
                        price: 0,
                        category_id: '',
                        description: '',
                        image: null
                    };
                    this.productImagePreview = null;
                },

                closeProductModal() {
                    this.showProductModal = false;
                    this.editingProduct = null;
                    this.resetProductForm();
                },

                // Add-on methods (stay inside cashier mode)
                openAddonModal(product) {
                    this.addonProduct = JSON.parse(JSON.stringify(product));
                    this.showAddonModal = true;
                    this.resetAddonOptionForm();
                    this.addonValueForm = { option_id: null, name: '', price_delta: 0 };
                    this.editingAddonValueId = null;
                },

                closeAddonModal() {
                    this.showAddonModal = false;
                    this.addonProduct = null;
                    this.resetAddonOptionForm();
                    this.addonValueForm = { option_id: null, name: '', price_delta: 0 };
                    this.editingAddonValueId = null;
                },

                resetAddonOptionForm() {
                    this.editingAddonOptionId = null;
                    this.addonOptionForm = { name: '', type: 'single', is_required: false };
                },

                editAddonOption(option) {
                    this.editingAddonOptionId = option.id;
                    this.addonOptionForm = {
                        name: option.name || '',
                        type: option.type || 'single',
                        is_required: !!option.is_required,
                    };
                },

                openAddonValueForm(optionId) {
                    this.addonValueForm = { option_id: optionId, name: '', price_delta: 0 };
                    this.editingAddonValueId = null;
                },

                editAddonValue(optionId, value) {
                    this.addonValueForm = {
                        option_id: optionId,
                        name: value.name || '',
                        price_delta: Number(value.price_delta || 0),
                    };
                    this.editingAddonValueId = value.id;
                },

                getAddonOptionBaseUrl() {
                    return `${this.urls.productOptionsBase}/${this.addonProduct.id}/options`;
                },

                async saveAddonOption() {
                    if (!this.addonProduct) return;
                    const name = String(this.addonOptionForm.name || '').trim();
                    if (!name) return;

                    const payload = {
                        name,
                        type: this.addonOptionForm.type || 'single',
                        is_required: !!this.addonOptionForm.is_required,
                    };

                    const isEdit = !!this.editingAddonOptionId;
                    const url = isEdit
                        ? `${this.getAddonOptionBaseUrl()}/${this.editingAddonOptionId}`
                        : this.getAddonOptionBaseUrl();

                    const response = await fetch(url, {
                        method: isEdit ? 'PUT' : 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                        },
                        body: JSON.stringify(payload)
                    });

                    if (!response.ok) {
                        const text = await response.text();
                        this.showNotification(text || 'Gagal menyimpan grup add-on', 'error');
                        return;
                    }

                    const result = await response.json();
                    const option = result.option;
                    if (isEdit) {
                        const idx = (this.addonProduct.options || []).findIndex(o => o.id === option.id);
                        if (idx !== -1) this.addonProduct.options[idx] = option;
                    } else {
                        if (!Array.isArray(this.addonProduct.options)) this.addonProduct.options = [];
                        this.addonProduct.options.push(option);
                    }

                    this.syncAddonProductToMainList();
                    this.resetAddonOptionForm();
                    this.showNotification('Grup add-on berhasil disimpan', 'success');
                },

                async deleteAddonOption(optionId) {
                    const ok = await this.confirmAction('Hapus grup add-on ini beserta pilihannya?');
                    if (!ok || !this.addonProduct) return;

                    const response = await fetch(`${this.getAddonOptionBaseUrl()}/${optionId}`, {
                        method: 'DELETE',
                        headers: {
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                        }
                    });

                    if (!response.ok) {
                        const text = await response.text();
                        this.showNotification(text || 'Gagal menghapus grup add-on', 'error');
                        return;
                    }

                    this.addonProduct.options = (this.addonProduct.options || []).filter(o => o.id !== optionId);
                    this.syncAddonProductToMainList();
                    this.showNotification('Grup add-on dihapus', 'success');
                },

                async saveAddonValue() {
                    if (!this.addonProduct || !this.addonValueForm.option_id) return;
                    const name = String(this.addonValueForm.name || '').trim();
                    if (!name) return;

                    const optionId = this.addonValueForm.option_id;
                    const payload = {
                        name,
                        price_delta: Math.max(0, Number(this.addonValueForm.price_delta || 0))
                    };

                    const base = `${this.getAddonOptionBaseUrl()}/${optionId}/values`;
                    const isEdit = !!this.editingAddonValueId;
                    const url = isEdit ? `${base}/${this.editingAddonValueId}` : base;

                    const response = await fetch(url, {
                        method: isEdit ? 'PUT' : 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                        },
                        body: JSON.stringify(payload)
                    });

                    if (!response.ok) {
                        const text = await response.text();
                        this.showNotification(text || 'Gagal menyimpan pilihan add-on', 'error');
                        return;
                    }

                    const result = await response.json();
                    const option = (this.addonProduct.options || []).find(o => o.id === optionId);
                    if (!option) return;
                    if (!Array.isArray(option.values)) option.values = [];

                    if (isEdit) {
                        const idx = option.values.findIndex(v => v.id === result.value.id);
                        if (idx !== -1) option.values[idx] = result.value;
                    } else {
                        option.values.push(result.value);
                    }

                    this.syncAddonProductToMainList();
                    this.openAddonValueForm(optionId);
                    this.showNotification('Pilihan add-on berhasil disimpan', 'success');
                },

                async deleteAddonValue(optionId, valueId) {
                    const ok = await this.confirmAction('Hapus pilihan add-on ini?');
                    if (!ok || !this.addonProduct) return;

                    const response = await fetch(`${this.getAddonOptionBaseUrl()}/${optionId}/values/${valueId}`, {
                        method: 'DELETE',
                        headers: {
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                        }
                    });

                    if (!response.ok) {
                        const text = await response.text();
                        this.showNotification(text || 'Gagal menghapus pilihan add-on', 'error');
                        return;
                    }

                    const option = (this.addonProduct.options || []).find(o => o.id === optionId);
                    if (option) {
                        option.values = (option.values || []).filter(v => v.id !== valueId);
                    }
                    this.syncAddonProductToMainList();
                    this.showNotification('Pilihan add-on dihapus', 'success');
                },

                syncAddonProductToMainList() {
                    if (!this.addonProduct) return;
                    const index = this.allProducts.findIndex(p => p.id === this.addonProduct.id);
                    if (index !== -1) {
                        this.allProducts[index] = JSON.parse(JSON.stringify(this.addonProduct));
                    }
                },

                handleImageUpload(event) {
                    const file = event.target.files[0];
                    if (file) {
                        this.productForm.image = file;
                        this.productImagePreview = URL.createObjectURL(file);
                    }
                },

                isProductFormValid() {
                    return this.productForm.name.trim() &&
                           this.productForm.price >= 0 &&
                           this.productForm.category_id;
                },

                async saveProduct() {
                    if (!this.isProductFormValid()) return;

                    try {
                        const formData = new FormData();
                        formData.append('name', this.productForm.name.trim());
                        formData.append('price', this.productForm.price);
                        formData.append('category_id', this.productForm.category_id);
                        formData.append('description', this.productForm.description);
                        if (this.productForm.image) {
                            formData.append('image', this.productForm.image);
                        }

                        const url = this.editingProduct
                            ? `${this.urls.productsUpdateBase}/${this.editingProduct.id}`
                            : this.urls.productsStore;
                        const method = 'POST';

                        const response = await fetch(url, {
                            method: method,
                            headers: {
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                            },
                            body: formData
                        });

                        if (response.ok) {
                            const result = await response.json();
                            if (this.editingProduct) {
                                // Update existing product
                                const index = this.allProducts.findIndex(p => p.id === this.editingProduct.id);
                                if (index !== -1) {
                                    this.allProducts[index] = result.product;
                                }
                            } else {
                                // Add new product
                                this.allProducts.push(result.product);
                            }
                            this.closeProductModal();
                            this.showNotification(this.i18n.saveProductSuccess || 'Product saved', 'success');
                        } else {
                            let message = this.i18n.saveProductFailed || 'Failed to save product';
                            try {
                                const data = await response.json();
                                if (data && data.message) {
                                    message = data.message;
                                } else if (data && data.errors) {
                                    const firstKey = Object.keys(data.errors)[0];
                                    if (firstKey && data.errors[firstKey] && data.errors[firstKey][0]) {
                                        message = data.errors[firstKey][0];
                                    }
                                }
                            } catch (e) {
                                const text = await response.text();
                                if (text) message = text;
                            }
                            throw new Error(message);
                        }
                    } catch (error) {
                        console.error('Error saving product:', error);
                        this.showNotification(error?.message || this.i18n.saveProductFailed || 'Failed to save product', 'error');
                    }
                },

                async deleteProduct(productId) {
                    const confirmed = await this.confirmAction(this.i18n.deleteProductConfirm || 'Delete this product?');
                    if (!confirmed) return;

                    try {
                        const response = await fetch(`${this.urls.productsUpdateBase}/${productId}`, {
                            method: 'DELETE',
                            headers: {
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                            }
                        });

                        if (response.ok) {
                            this.allProducts = this.allProducts.filter(p => p.id !== productId);
                            this.showNotification(this.i18n.deleteProductSuccess || 'Product deleted', 'success');
                        } else {
                            const text = await response.text();
                            throw new Error(text || (this.i18n.deleteProductFailed || 'Failed to delete product'));
                        }
                    } catch (error) {
                        console.error('Error deleting product:', error);
                        this.showNotification(this.i18n.deleteProductFailed || 'Failed to delete product', 'error');
                    }
                },

                // Helper methods
                getProductsCount(categoryId) {
                    return this.allProducts.filter(p => p.category_ids?.includes(categoryId)).length;
                },

                getCategoryName(categoryId) {
                    const category = this.categories.find(c => c.id === categoryId);
                    return category ? category.name : (this.i18n.noCategory || '-');
                },

                formatRp(v) {
                    const n = Number(v);
                    if (!isFinite(n)) return '0';
                    try { return new Intl.NumberFormat('id-ID').format(Math.round(n)); }
                    catch(e){ return String(Math.round(n)); }
                },

                showNotification(message, type = 'info') {
                    if (window.cashierUi && typeof window.cashierUi.toast === 'function') {
                        window.cashierUi.toast(message, type);
                        return;
                    }
                    alert(message);
                },

                async confirmAction(message) {
                    if (window.cashierUi && typeof window.cashierUi.confirm === 'function') {
                        return await window.cashierUi.confirm({
                            title: this.i18n.confirmTitle || 'Konfirmasi',
                            message,
                            confirmText: this.i18n.confirm || 'Ya, lanjutkan',
                            cancelText: this.i18n.cancel || 'Batal',
                        });
                    }
                    return confirm(message);
                }
            }
        }
    </script>
</body>
</html>