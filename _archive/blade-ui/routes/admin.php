<?php

// Admin Routes for Single Restaurant App

use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\TableController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\ReservationController;
use App\Http\Controllers\Admin\ReservationSpaceController;
use App\Http\Controllers\Admin\ReservationSpaceImageController;
use App\Http\Controllers\Admin\ReservationSpaceItemController;
use App\Http\Controllers\Admin\BrandSettingController;
use App\Http\Controllers\Admin\PaymentSettingController;
use App\Http\Controllers\Admin\LoyaltySettingController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\MemberPromotionController;
use App\Http\Controllers\Admin\SitePromotionController;
use App\Http\Controllers\Admin\KitchenController;
use App\Http\Controllers\Admin\PackageController;
use App\Http\Controllers\Admin\CashierController;
use App\Http\Controllers\Admin\ProductOptionController;
use App\Http\Controllers\Admin\ProductOptionValueController;
use App\Http\Controllers\Admin\PackageItemController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')->name('admin.')->middleware(['auth', 'verified', 'admin', 'web.pos.entitled'])->group(function () {
    Route::post('locale', function (Request $request) {
        $validated = $request->validate([
            'locale' => ['required', 'in:id,en'],
        ]);

        session(['locale' => $validated['locale']]);

        return back();
    })->name('locale.update');

    // Dashboard
    Route::get('/', DashboardController::class)->name('dashboard');
    
    // Products
    Route::resource('products', ProductController::class);
    Route::prefix('products/{product}')->name('products.')->group(function () {
        Route::resource('options', ProductOptionController::class)->except(['show']);
        Route::get('options/{option}/values/create', [ProductOptionValueController::class, 'create'])->name('options.values.create');
        Route::post('options/{option}/values', [ProductOptionValueController::class, 'store'])->name('options.values.store');
        Route::get('options/{option}/values/{value}/edit', [ProductOptionValueController::class, 'edit'])->name('options.values.edit');
        Route::put('options/{option}/values/{value}', [ProductOptionValueController::class, 'update'])->name('options.values.update');
        Route::delete('options/{option}/values/{value}', [ProductOptionValueController::class, 'destroy'])->name('options.values.destroy');

        Route::post('package-items', [PackageItemController::class, 'store'])->name('package-items.store');
        Route::delete('package-items/{packageItem}', [PackageItemController::class, 'destroy'])->name('package-items.destroy');
    });
    Route::resource('categories', CategoryController::class);
    Route::resource('packages', PackageController::class);
    
    
    
    // Tables
    Route::resource('tables', TableController::class);
    
    // Orders
    Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('orders/history', [OrderController::class, 'history'])->name('orders.history');
    Route::get('orders/poll', [OrderController::class, 'poll'])->name('orders.poll');
    Route::get('orders/{orderNumber}', [OrderController::class, 'show'])->name('orders.show');
    Route::patch('orders/{orderNumber}/status', [OrderController::class, 'updateStatus'])->name('orders.status');
    Route::patch('orders/{orderNumber}/payment-status', [OrderController::class, 'updatePaymentStatus'])->name('orders.payment-status');
    Route::patch('orders/{orderNumber}/customer-name', [OrderController::class, 'updateCustomerName'])->name('orders.customer-name');
    
    // Reports
    Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('reports/daily', [ReportController::class, 'daily'])->name('reports.daily');
    Route::get('reports/monthly', [ReportController::class, 'monthly'])->name('reports.monthly');
    Route::get('reports/export/sales', [ReportController::class, 'exportSales'])->name('reports.export.sales');
    Route::get('reports/export/products', [ReportController::class, 'exportProducts'])->name('reports.export.products');
    
    // Reservations
    Route::resource('reservations', ReservationController::class)->only(['index', 'show']);
    Route::patch('reservations/{reservation}/status', [ReservationController::class, 'updateStatus'])->name('reservations.status');
    Route::resource('reservation-spaces', ReservationSpaceController::class);
    Route::prefix('reservation-spaces/{space}')->name('reservation-spaces.')->group(function () {
        Route::post('images', [ReservationSpaceImageController::class, 'store'])->name('images.store');
        Route::delete('images/{image}', [ReservationSpaceImageController::class, 'destroy'])->name('images.destroy');

        Route::post('items', [ReservationSpaceItemController::class, 'store'])->name('items.store');
        Route::put('items/{item}', [ReservationSpaceItemController::class, 'update'])->name('items.update');
        Route::delete('items/{item}', [ReservationSpaceItemController::class, 'destroy'])->name('items.destroy');
    });
    
    // Settings
    Route::get('brand', [BrandSettingController::class, 'edit'])->name('brand.edit');
    Route::put('brand', [BrandSettingController::class, 'update'])->name('brand.update');
    
    Route::get('payment', [PaymentSettingController::class, 'edit'])->name('payment.edit');
    Route::put('payment', [PaymentSettingController::class, 'update'])->name('payment.update');
    Route::get('payments', [PaymentSettingController::class, 'edit'])->name('payments.edit');
    Route::put('payments', [PaymentSettingController::class, 'update'])->name('payments.update');
    
    Route::get('loyalty', [LoyaltySettingController::class, 'edit'])->name('loyalty.edit');
    Route::put('loyalty', [LoyaltySettingController::class, 'update'])->name('loyalty.update');
    
    // Members
    Route::resource('customers', CustomerController::class);
    Route::post('customers/{customer}/toggle-status', [CustomerController::class, 'toggleStatus'])->name('customers.toggle-status');
    Route::get('members', [CustomerController::class, 'index'])->name('members.index');
    Route::get('members/{customer}', [CustomerController::class, 'show'])->name('members.show');
    
    // Promotions
    Route::resource('site-promotions', SitePromotionController::class)->names('site-promotions');
    Route::resource('member-promotions', MemberPromotionController::class)->names('member-promotions');
    
    // Kitchen Display
    Route::get('kitchen', [KitchenController::class, 'index'])->name('kitchen.index');
    Route::get('kitchen/{orderNumber}', [KitchenController::class, 'show'])->name('kitchen.show');
    Route::patch('kitchen/{orderNumber}/status', [KitchenController::class, 'updateStatus'])->name('kitchen.status');

    // Cashier Mode for Admin
    Route::get('cashier', [CashierController::class, 'index'])->name('cashier.index');
    Route::get('cashier/menu', [CashierController::class, 'menu'])->name('cashier.menu');
    Route::post('cashier/menu/categories', [CashierController::class, 'storeCategory'])->name('cashier.menu.categories.store');
    Route::put('cashier/menu/categories/{category}', [CashierController::class, 'updateCategory'])->name('cashier.menu.categories.update');
    Route::delete('cashier/menu/categories/{category}', [CashierController::class, 'destroyCategory'])->name('cashier.menu.categories.destroy');
    Route::post('cashier/menu/products', [CashierController::class, 'storeProduct'])->name('cashier.menu.products.store');
    Route::post('cashier/menu/products/{product}', [CashierController::class, 'updateProduct'])->name('cashier.menu.products.update');
    Route::delete('cashier/menu/products/{product}', [CashierController::class, 'destroyProduct'])->name('cashier.menu.products.destroy');
    Route::post('cashier/menu/products/{product}/options', [CashierController::class, 'storeProductOption'])->name('cashier.menu.products.options.store');
    Route::put('cashier/menu/products/{product}/options/{option}', [CashierController::class, 'updateProductOption'])->name('cashier.menu.products.options.update');
    Route::delete('cashier/menu/products/{product}/options/{option}', [CashierController::class, 'destroyProductOption'])->name('cashier.menu.products.options.destroy');
    Route::post('cashier/menu/products/{product}/options/{option}/values', [CashierController::class, 'storeProductOptionValue'])->name('cashier.menu.products.options.values.store');
    Route::put('cashier/menu/products/{product}/options/{option}/values/{value}', [CashierController::class, 'updateProductOptionValue'])->name('cashier.menu.products.options.values.update');
    Route::delete('cashier/menu/products/{product}/options/{option}/values/{value}', [CashierController::class, 'destroyProductOptionValue'])->name('cashier.menu.products.options.values.destroy');
    Route::get('cashier/orders', [CashierController::class, 'orders'])->name('cashier.orders');
    Route::get('cashier/orders-count', [CashierController::class, 'ordersCount'])->name('cashier.orders-count');
    Route::post('cashier/checkout', [CashierController::class, 'checkout'])->name('cashier.checkout');
    Route::post('cashier/orders/bulk', [CashierController::class, 'bulk'])->name('cashier.orders.bulk');
    Route::get('cashier/orders/{orderNumber}/receipt', [CashierController::class, 'receipt'])->name('cashier.receipt');
    
    // Staff Management
    Route::resource('staff', CashierController::class);
});
