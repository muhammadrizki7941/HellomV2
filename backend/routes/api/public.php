<?php

/*
| Public endpoints (no token): landing pages, showcase/insights, product catalog, POS public (payment methods, member register/lookup), gateway webhooks, auth (register/login/reset/SSO), customer self-order.
| Loaded from routes/api.php inside the v1/hellom prefix group.
*/

use App\Http\Controllers\Api\V1\Hellom\AuthController;
use App\Http\Controllers\Api\V1\Hellom\BannerController;
use App\Http\Controllers\Api\V1\Hellom\Billing\LandingCheckoutController;
use App\Http\Controllers\Api\V1\Hellom\BrandSettingController;
use App\Http\Controllers\Api\V1\Hellom\CustomerOrderController;
use App\Http\Controllers\Api\V1\Hellom\DokuWebhookController;
use App\Http\Controllers\Api\V1\Hellom\EmailVerificationController;
use App\Http\Controllers\Api\V1\Hellom\IpaymuWebhookController;
use App\Http\Controllers\Api\V1\Hellom\LandingBuilderController;
use App\Http\Controllers\Api\V1\Hellom\LandingContentController;
use App\Http\Controllers\Api\V1\Hellom\LandingSaleController;
use App\Http\Controllers\Api\V1\Hellom\Pos\PosExperienceController;
use App\Http\Controllers\Api\V1\Hellom\Pos\PosMemberController;
use App\Http\Controllers\Api\V1\Hellom\Pos\PosPaymentSettingController;
use App\Http\Controllers\Api\V1\Hellom\PublicStoreController;
use App\Http\Controllers\Api\V1\Hellom\ShowcaseController;
use App\Http\Controllers\Api\V1\Hellom\XenditWebhookController;
use App\Http\Controllers\Api\V1\Public\GuestProductCheckoutController;
use App\Http\Controllers\Api\V1\Public\ProductController as PublicProductController;
use Illuminate\Support\Facades\Route;

// ─── PUBLIC — no auth ───
Route::get('/public/landing/domain/{domain}', [LandingBuilderController::class, 'publicShowByDomain'])
    ->name('public.landing.show_by_domain');
Route::get('/public/landingpage/{organizationSlug}', [LandingBuilderController::class, 'publicShowByOrganization'])
    ->name('public.landing.show_by_organization');
Route::get('/public/landing/{organizationSlug}/{pageSlug}', [LandingBuilderController::class, 'publicShow'])
    ->name('public.landing.show');
Route::post('/public/landing/{landingPageId}/customers', [LandingBuilderController::class, 'publicStoreCustomer'])->middleware('throttle:hellom-public-write')
    ->name('public.landing.customers.store');
// Public buyer checkout for landing-page product/PDF sales (gateway only)
Route::post('/public/landingpage/{organizationSlug}/orders', [LandingCheckoutController::class, 'publicLandingCheckout'])->middleware('throttle:hellom-guest-checkout')
    ->name('public.landing.orders.checkout');
Route::post('/public/landingpage/orders/{reference}/returned', [LandingSaleController::class, 'returned'])->middleware('throttle:hellom-public-lookup')
    ->name('public.landing.orders.returned');
Route::get('/public/landingpage/orders/{reference}/status', [LandingSaleController::class, 'status'])->middleware('throttle:hellom-public-lookup')
    ->name('public.landing.orders.status');
Route::get('/public/landingpage/orders/{token}/download', [LandingSaleController::class, 'download'])
    ->name('public.landing.orders.download');
Route::get('/public/landingpage/orders/{reference}/qr', [LandingSaleController::class, 'qr'])
    ->name('public.landing.orders.qr');
// Hellom Page selling (Fase 3): checkout page /beli/{id}, access page /akses/{token}, "cek pesanan", "laporkan".
Route::get('/public/landing-products/{publicId}', [PublicStoreController::class, 'product'])->middleware('throttle:hellom-public-lookup')
    ->name('public.landing.products.show');
Route::post('/public/landing-products/{publicId}/quote', [PublicStoreController::class, 'quote'])->middleware('throttle:hellom-public-lookup')
    ->name('public.landing.products.quote');
Route::post('/public/landing-products/{publicId}/checkout', [PublicStoreController::class, 'checkout'])->middleware('throttle:hellom-landing-checkout')
    ->name('public.landing.products.checkout');
Route::get('/public/landing-access/{token}', [PublicStoreController::class, 'access'])->middleware('throttle:hellom-public-lookup')
    ->name('public.landing.access.show');
Route::post('/public/landing-access/{token}/open', [PublicStoreController::class, 'open'])->middleware('throttle:hellom-public-lookup')
    ->name('public.landing.access.open');
Route::get('/public/landing-access/{token}/download', [PublicStoreController::class, 'download'])->middleware('throttle:hellom-public-lookup')
    ->name('public.landing.access.download');
Route::post('/public/landing-access/{token}/resend', [PublicStoreController::class, 'resend'])->middleware('throttle:hellom-landing-mail')
    ->name('public.landing.access.resend');
Route::post('/public/landing-orders/lookup', [PublicStoreController::class, 'lookup'])->middleware('throttle:hellom-landing-mail')
    ->name('public.landing.orders.lookup');
Route::post('/public/landing-reports', [PublicStoreController::class, 'report'])->middleware('throttle:hellom-landing-report')
    ->name('public.landing.reports.store');
// Fase 4: stats beacons, QR of a shop link, one-time Purchase pixel event for the thank-you page.
Route::post('/public/landing-events', [PublicStoreController::class, 'event'])->middleware('throttle:hellom-landing-events')
    ->name('public.landing.events');
Route::get('/public/landing-qr', [PublicStoreController::class, 'qr'])->middleware('throttle:hellom-public-lookup')
    ->name('public.landing.qr');
Route::post('/public/landingpage/orders/{reference}/purchase-event', [PublicStoreController::class, 'purchaseEvent'])->middleware('throttle:hellom-public-lookup')
    ->name('public.landing.orders.purchase_event');
Route::get('/public/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])->middleware('throttle:hellom-public-lookup')
    ->name('public.email.verify');
Route::get('/public/showcase/portfolios', [ShowcaseController::class, 'publicPortfolios'])->name('public.showcase.portfolios');
Route::get('/public/showcase/clients', [ShowcaseController::class, 'publicClients'])->name('public.showcase.clients');
Route::get('/public/landing-content', [LandingContentController::class, 'publicContent'])->name('public.landing_content');
Route::get('/public/insights', [LandingContentController::class, 'publicArticles'])->name('public.insights.index');
Route::get('/public/insights/{slug}', [LandingContentController::class, 'publicArticle'])->name('public.insights.show');
Route::get('/public/brand', [BrandSettingController::class, 'publicShow'])->name('public.brand');
Route::get('/public/banners', [BannerController::class, 'publicIndex'])->name('public.banners.index');
Route::get('/public/products', [PublicProductController::class, 'index'])->name('public.products.index');
Route::get('/public/flagship-apps', [PublicProductController::class, 'flagship'])->name('public.products.flagship');
Route::get('/public/products/categories', [PublicProductController::class, 'categories'])->name('public.products.categories');
Route::get('/public/products/{slug}', [PublicProductController::class, 'show'])->name('public.products.show');
// Guest checkout for platform digital products (no login; access delivered by email)
Route::get('/public/products/{slug}/checkout', [GuestProductCheckoutController::class, 'options'])->name('public.products.checkout.options');
Route::post('/public/products/{slug}/checkout', [GuestProductCheckoutController::class, 'store'])->middleware('throttle:hellom-guest-checkout')
    ->name('public.products.checkout.store');
Route::get('/public/product-checkouts/{token}', [GuestProductCheckoutController::class, 'status'])->middleware('throttle:hellom-public-lookup')
    ->name('public.product_checkouts.status');
Route::post('/public/product-checkouts/{token}/resend-access', [GuestProductCheckoutController::class, 'resendAccess'])->middleware('throttle:hellom-guest-checkout')
    ->name('public.product_checkouts.resend_access');
Route::get('/pos/public/payment-methods/{tenantSlug}', [PosPaymentSettingController::class, 'publicSettings'])->name('pos.public.payment-methods');
Route::post('/pos/public/members/register', [PosMemberController::class, 'publicRegister'])->middleware('throttle:hellom-public-write')->name('pos.public.members.register');
Route::get('/pos/public/members/lookup', [PosMemberController::class, 'publicLookup'])->middleware('throttle:hellom-public-lookup')->name('pos.public.members.lookup');
// Every raw webhook is logged (payment_webhook_logs) before processing.
Route::post('/webhooks/xendit', [XenditWebhookController::class, 'handle'])->middleware('logPaymentWebhook:xendit')->name('webhooks.xendit');
Route::post('/webhooks/ipaymu', [IpaymuWebhookController::class, 'handle'])->middleware('logPaymentWebhook:ipaymu')->name('webhooks.ipaymu');
Route::post('/webhooks/doku', [DokuWebhookController::class, 'handle'])->middleware('logPaymentWebhook:doku')->name('webhooks.doku');

// Public auth endpoints (no token needed)
Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:hellom-auth')->name('auth.register');
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:hellom-auth')->name('auth.login');
Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:hellom-auth')->name('auth.forgot_password');
Route::post('/auth/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:hellom-auth')->name('auth.reset_password');
Route::post('/auth/sso-login', [AuthController::class, 'ssoLogin'])->middleware('throttle:hellom-auth')->name('auth.sso_login');
Route::post('/auth/magic-login', [AuthController::class, 'magicLogin'])->middleware('throttle:hellom-auth')->name('auth.magic_login');

// Public — customer self-order (scan QR, no login needed)
Route::get('/pos/customer/menu/{tableToken}', [CustomerOrderController::class, 'getMenu']);
Route::get('/pos/customer/organization/{organizationSlug}/outlets', [CustomerOrderController::class, 'getOrganizationOutlets']);
Route::get('/pos/customer/organization/{organizationSlug}/menu', [CustomerOrderController::class, 'getOrganizationMenu']);
Route::post('/pos/customer/order', [CustomerOrderController::class, 'createOrder'])->middleware('throttle:hellom-self-order');
Route::get('/pos/customer/order/{orderNumber}', [CustomerOrderController::class, 'getOrderStatus']);
Route::get('/pos/customer/table/{tableToken}/orders', [CustomerOrderController::class, 'getTableOrders'])->middleware('throttle:hellom-public-lookup');
Route::get('/pos/customer/table/{tableToken}/realtime-token', [CustomerOrderController::class, 'realtimeToken'])->middleware('throttle:hellom-public-lookup');
Route::post('/pos/customer/promos/{promoId}/claim', [PosExperienceController::class, 'claimPromo'])->middleware('throttle:hellom-public-write');
Route::post('/pos/customer/reservations', [PosExperienceController::class, 'createReservation'])->middleware('throttle:hellom-public-write');
