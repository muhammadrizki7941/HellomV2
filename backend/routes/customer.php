<?php

// Customer Routes for Single Restaurant App (Self-Order)

use App\Http\Controllers\Customer\HomePageController;
use App\Http\Controllers\Customer\OrderPageController;
use App\Http\Controllers\Customer\CheckoutController;
use App\Http\Controllers\Customer\OrdersPageController;
use App\Http\Controllers\Customer\OrderStatusController;
use App\Http\Controllers\Customer\MemberDashboardController;
use App\Http\Controllers\Customer\PromoPageController;
use App\Http\Controllers\Customer\ReservationController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use Illuminate\Support\Facades\Route;
use App\Http\Middleware\SetCustomerTenant;

// Legacy POS landing page (moved to /pos/ to avoid conflict with Hellom root)
Route::get('/pos', HomePageController::class)->name('home');
Route::get('/pos', HomePageController::class)->name('customer.home');

// Self-Order (with table) - with tenant context for customer
Route::middleware([SetCustomerTenant::class])->group(function () {
    Route::get('/order', OrderPageController::class)->name('order.page');
    Route::get('/customer/order/{tableToken}', OrderPageController::class)->name('customer.order.page');

    // Order Pending - redirect to order page
    Route::get('/order/pending', function () {
        return redirect()->route('order.page');
    })->name('order.pending');

    Route::post('/order/add-to-cart', [OrderPageController::class, 'addToCart'])->name('customer.cart.add');
    Route::delete('/order/cart/remove', [OrderPageController::class, 'removeFromCart'])->name('customer.cart.remove');

    // Checkout
    Route::post('/order/checkout', [CheckoutController::class, 'store'])->name('customer.checkout');
    Route::get('/order/success/{orderNumber}', [CheckoutController::class, 'thanks'])->name('customer.order.success');
    Route::get('/order/status/{orderNumber}', [CheckoutController::class, 'status']);
});

// Order Status (for guests) - also needs tenant context? The OrderStatusController may need tenant for brand settings.
Route::middleware([SetCustomerTenant::class])->group(function () {
    Route::get('/pesanan', OrdersPageController::class)->name('customer.orders');
    Route::get('/pesanan/{orderNumber}', [OrderStatusController::class, 'show'])->name('customer.order.status');
    Route::post('/pesanan/check', [OrderStatusController::class, 'check'])->name('customer.order.check');
});

// Member Routes
Route::get('/member/register', [RegisteredUserController::class, 'create'])->name('customer.member.register');
Route::post('/member/register', [RegisteredUserController::class, 'store'])->name('customer.member.register.submit');

Route::get('/member/login', [AuthenticatedSessionController::class, 'create'])->name('customer.member.login');
Route::post('/member/login', [AuthenticatedSessionController::class, 'store'])->name('customer.member.login.submit');

Route::middleware(['auth'])->group(function () {
    Route::get('/member/dashboard', MemberDashboardController::class)->name('member.dashboard');
    Route::post('/member/logout', [AuthenticatedSessionController::class, 'destroy'])->name('customer.member.logout');
});

// Promotions - also needs tenant context for brand/promos
Route::middleware([SetCustomerTenant::class])->group(function () {
    Route::get('/promo', PromoPageController::class)->name('customer.promo');
});

// Reservations - list all spaces
Route::middleware([SetCustomerTenant::class])->group(function () {
    Route::get('/reservations', [ReservationController::class, 'index'])->name('customer.reservations');
    Route::get('/reservations', [ReservationController::class, 'index'])->name('reservation.index');

    // Reservations - detail/show
    Route::get('/reservations/{space}', [ReservationController::class, 'show'])->name('reservation.show');

    // Reservation availability check
    Route::get('/reservations/{space}/availability', [ReservationController::class, 'availability'])->name('reservation.availability');

    // Store reservation
    Route::post('/reservations/{space}', [ReservationController::class, 'store'])->name('reservation.store');

    // Reservation thanks
    Route::get('/reservations/thanks/{reservation}', [ReservationController::class, 'thanks'])->name('reservation.thanks');

    Route::post('/reservations', [ReservationController::class, 'store'])->name('customer.reservations.submit');
});
