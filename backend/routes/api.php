<?php

use App\Http\Controllers\Api\V1\Hellom\AuthController;
use App\Http\Controllers\Api\V1\Hellom\AppCatalogController;
use App\Http\Controllers\Api\V1\Hellom\BannerController;
use App\Http\Controllers\Api\V1\Hellom\EntitlementController;
use App\Http\Controllers\Api\V1\Hellom\BillingController;
use App\Http\Controllers\Api\V1\Hellom\AdminMailController;
use App\Http\Controllers\Api\V1\Hellom\FileAssetController;
use App\Http\Controllers\Api\V1\Hellom\LandingBuilderController;
use App\Http\Controllers\Api\V1\Hellom\LandingContentController;
use App\Http\Controllers\Api\V1\Hellom\InvoiceController;
use App\Http\Controllers\Api\V1\Hellom\MemberDashboardController;
use App\Http\Controllers\Api\V1\Hellom\OrganizationController;
use App\Http\Controllers\Api\V1\Hellom\OrganizationTeamController;
use App\Http\Controllers\Api\V1\Hellom\OrderController as HellomOrderController;
use App\Http\Controllers\Api\V1\Hellom\Pos\PosOrderController;
use App\Http\Controllers\Api\V1\Hellom\Pos\PosCategoryController;
use App\Http\Controllers\Api\V1\Hellom\Pos\PosProductController;
use App\Http\Controllers\Api\V1\Hellom\Pos\PosTableController;
use App\Http\Controllers\Api\V1\Hellom\Pos\PosReportController;
use App\Http\Controllers\Api\V1\Hellom\Pos\PosMemberController;
use App\Http\Controllers\Api\V1\Hellom\Pos\PosLoyaltyController;
use App\Http\Controllers\Api\V1\Hellom\Pos\PosStaffController;
use App\Http\Controllers\Api\V1\Hellom\Pos\PosPaymentSettingController;
use App\Http\Controllers\Api\V1\Hellom\CustomerOrderController;
use App\Http\Controllers\Api\V1\Hellom\Pos\PosExperienceController;
use App\Http\Controllers\Api\V1\Hellom\PricingController;
use App\Http\Controllers\Api\V1\Hellom\PromoCampaignController;
use App\Http\Controllers\Api\V1\Hellom\ProductPurchaseSettingController;
use App\Http\Controllers\Api\V1\Hellom\ShowcaseController;
use App\Http\Controllers\Api\V1\Hellom\SuperAdminController;
use App\Http\Controllers\Api\V1\Hellom\WalletController;
use App\Http\Controllers\Api\V1\Hellom\DokuWebhookController;
use App\Http\Controllers\Api\V1\Hellom\IpaymuWebhookController;
use App\Http\Controllers\Api\V1\Hellom\XenditWebhookController;
use App\Http\Controllers\Api\V1\Hellom\BrandSettingController;
use App\Http\Controllers\Api\V1\Consumer\NotificationController as ConsumerNotificationController;
use App\Http\Controllers\Api\V1\Consumer\OnboardingController as ConsumerOnboardingController;
use App\Http\Controllers\Api\V1\Consumer\ProductController as ConsumerProductController;
use App\Http\Controllers\Api\V1\Public\ProductController as PublicProductController;
use App\Http\Controllers\Admin\DigitalProductController as AdminDigitalProductController;
use App\Http\Controllers\Admin\ProductPurchaseController;
use App\Http\Middleware\Api\AuthenticateApiToken;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/hellom')->name('api.v1.hellom.')->group(function () {

    // ─── PUBLIC — no auth ───
    Route::get('/public/landing/domain/{domain}', [LandingBuilderController::class, 'publicShowByDomain'])
        ->name('public.landing.show_by_domain');
    Route::get('/public/landingpage/{organizationSlug}', [LandingBuilderController::class, 'publicShowByOrganization'])
        ->name('public.landing.show_by_organization');
    Route::get('/public/landing/{organizationSlug}/{pageSlug}', [LandingBuilderController::class, 'publicShow'])
        ->name('public.landing.show');
    Route::post('/public/landing/{landingPageId}/customers', [LandingBuilderController::class, 'publicStoreCustomer'])
        ->name('public.landing.customers.store');
    Route::get('/public/showcase/portfolios', [ShowcaseController::class, 'publicPortfolios'])->name('public.showcase.portfolios');
    Route::get('/public/showcase/clients', [ShowcaseController::class, 'publicClients'])->name('public.showcase.clients');
    Route::get('/public/landing-content', [LandingContentController::class, 'publicContent'])->name('public.landing_content');
    Route::get('/public/brand', [BrandSettingController::class, 'publicShow'])->name('public.brand');
    Route::get('/public/banners', [BannerController::class, 'publicIndex'])->name('public.banners.index');
    Route::get('/public/products', [PublicProductController::class, 'index'])->name('public.products.index');
    Route::get('/public/products/categories', [PublicProductController::class, 'categories'])->name('public.products.categories');
    Route::get('/public/products/{slug}', [PublicProductController::class, 'show'])->name('public.products.show');
    Route::get('/pos/public/payment-methods/{tenantSlug}', [PosPaymentSettingController::class, 'publicSettings'])->name('pos.public.payment-methods');
    Route::post('/pos/public/members/register', [PosMemberController::class, 'publicRegister'])->name('pos.public.members.register');
    Route::get('/pos/public/members/lookup', [PosMemberController::class, 'publicLookup'])->name('pos.public.members.lookup');
    Route::post('/webhooks/xendit', [XenditWebhookController::class, 'handle'])->name('webhooks.xendit');
    Route::post('/webhooks/ipaymu', [IpaymuWebhookController::class, 'handle'])->name('webhooks.ipaymu');
    Route::post('/webhooks/doku', [DokuWebhookController::class, 'handle'])->name('webhooks.doku');

    // Public auth endpoints (no token needed)
    Route::post('/auth/register', [AuthController::class, 'register'])->name('auth.register');
    Route::post('/auth/login', [AuthController::class, 'login'])->name('auth.login');
    Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword'])->name('auth.forgot_password');
    Route::post('/auth/reset-password', [AuthController::class, 'resetPassword'])->name('auth.reset_password');
    Route::post('/auth/sso-login', [AuthController::class, 'ssoLogin'])->name('auth.sso_login');

    // Public — customer self-order (scan QR, no login needed)
    Route::get('/pos/customer/menu/{tableToken}', [CustomerOrderController::class, 'getMenu']);
    Route::get('/pos/customer/organization/{organizationSlug}/menu', [CustomerOrderController::class, 'getOrganizationMenu']);
    Route::post('/pos/customer/order', [CustomerOrderController::class, 'createOrder']);
    Route::get('/pos/customer/order/{orderNumber}', [CustomerOrderController::class, 'getOrderStatus']);
    Route::post('/pos/customer/promos/{promoId}/claim', [PosExperienceController::class, 'claimPromo']);
    Route::post('/pos/customer/reservations', [PosExperienceController::class, 'createReservation']);

    // ─── AUTH ONLY — AuthenticateApiToken ───
    Route::middleware([AuthenticateApiToken::class])->group(function () {
        Route::get('/auth/me', [AuthController::class, 'me'])->name('auth.me');
        Route::put('/auth/profile', [AuthController::class, 'updateProfile'])->name('auth.profile.update');
        Route::post('/auth/change-password', [AuthController::class, 'changePassword'])->name('auth.change_password');
        Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');

        Route::get('/organizations', [OrganizationController::class, 'index'])->name('organizations.index');
        Route::post('/organizations', [OrganizationController::class, 'store'])->name('organizations.store');
        Route::get('/organizations/current', [OrganizationController::class, 'current'])->name('organizations.current');
        Route::post('/organizations/switch', [OrganizationController::class, 'switch'])->name('organizations.switch');
        Route::get('/organizations/current/settings', [OrganizationController::class, 'settings'])->name('organizations.settings');
        Route::post('/organizations/current/settings', [OrganizationController::class, 'updateSettings'])->name('organizations.updateSettings');
        Route::get('/organizations/current/team', [OrganizationTeamController::class, 'index'])->name('organizations.current.team.index');
        Route::post('/organizations/current/team/invite', [OrganizationTeamController::class, 'invite'])->name('organizations.current.team.invite');
        Route::get('/organizations/current/team/invitations', [OrganizationTeamController::class, 'listInvitations'])->name('organizations.current.team.invitations.index');
        Route::post('/organizations/current/team/invitations', [OrganizationTeamController::class, 'inviteByToken'])->name('organizations.current.team.invitations.store');
        Route::post('/organizations/current/team/invitations/bulk-revoke', [OrganizationTeamController::class, 'bulkRevokeInvitations'])->name('organizations.current.team.invitations.bulk_revoke');
        Route::post('/organizations/current/team/invitations/bulk-resend', [OrganizationTeamController::class, 'bulkResendInvitations'])->name('organizations.current.team.invitations.bulk_resend');
        Route::get('/organizations/current/team/invitations/{invitationId}', [OrganizationTeamController::class, 'showInvitation'])->name('organizations.current.team.invitations.show');
        Route::post('/organizations/current/team/invitations/{invitationId}/resend', [OrganizationTeamController::class, 'resendInvitation'])->name('organizations.current.team.invitations.resend');
        Route::delete('/organizations/current/team/invitations/{invitationId}', [OrganizationTeamController::class, 'revokeInvitation'])->name('organizations.current.team.invitations.destroy');
        Route::post('/organizations/current/team/invitations/accept', [OrganizationTeamController::class, 'acceptInvitation'])->name('organizations.current.team.invitations.accept');
        Route::put('/organizations/current/team/{userId}/role', [OrganizationTeamController::class, 'updateRole'])->name('organizations.current.team.update_role');
        Route::delete('/organizations/current/team/{userId}', [OrganizationTeamController::class, 'destroy'])->name('organizations.current.team.destroy');

        Route::get('/wallet/overview', [WalletController::class, 'overview'])->name('wallet.overview');
        Route::get('/wallet/payout-policy', [WalletController::class, 'payoutPolicy'])->name('wallet.payout_policy');
        Route::get('/wallet/finance-summary', [WalletController::class, 'financeSummary'])->name('wallet.finance_summary');
        Route::get('/wallet/admin/payout-queue', [WalletController::class, 'adminPayoutQueue'])->name('wallet.admin.payout_queue');
        Route::get('/wallet/transactions', [WalletController::class, 'transactions'])->name('wallet.transactions');
        Route::get('/wallet/payout-history', [WalletController::class, 'payoutHistory'])->name('wallet.payout_history');
        Route::get('/wallet/withdrawals', [WalletController::class, 'withdrawals'])->name('wallet.withdrawals');
        Route::post('/wallet/withdrawals', [WalletController::class, 'requestWithdrawal'])->name('wallet.withdrawals.request');
        Route::post('/wallet/withdrawals/{withdrawalId}/approve', [WalletController::class, 'approveWithdrawal'])->name('wallet.withdrawals.approve');
        Route::post('/wallet/withdrawals/{withdrawalId}/reject', [WalletController::class, 'rejectWithdrawal'])->name('wallet.withdrawals.reject');
        Route::post('/wallet/withdrawals/{withdrawalId}/mark-paid', [WalletController::class, 'markWithdrawalPaid'])->name('wallet.withdrawals.mark_paid');
        Route::post('/wallet/withdrawals/{withdrawalId}/mark-failed', [WalletController::class, 'markWithdrawalFailed'])->name('wallet.withdrawals.mark_failed');
        Route::post('/wallet/withdrawals/{withdrawalId}/cancel', [WalletController::class, 'cancelWithdrawal'])->name('wallet.withdrawals.cancel');

        // Platform finance (super admin only)
        Route::get('/platform/finance-summary', [WalletController::class, 'platformFinanceSummary'])->name('platform.finance_summary');
        Route::post('/platform/payouts', [WalletController::class, 'createPlatformPayout'])->name('platform.payouts.create');

        Route::get('/entitlements', [EntitlementController::class, 'index'])->name('entitlements.index');
        Route::get('/entitlements/check/{slug}', [EntitlementController::class, 'check'])->name('entitlements.check');
        Route::get('/member/dashboard/cards', [MemberDashboardController::class, 'cards'])->name('member.dashboard.cards');
        Route::get('/catalog/apps', [AppCatalogController::class, 'index'])->name('catalog.apps.index');
        Route::get('/catalog/apps/{slug}', [AppCatalogController::class, 'show'])->name('catalog.apps.show');
        Route::get('/pricing/matrix', [PricingController::class, 'matrix'])->name('pricing.matrix');
        Route::post('/pricing/preview-upgrade', [PricingController::class, 'previewUpgrade'])->name('pricing.preview_upgrade');
        Route::get('/billing/overview', [BillingController::class, 'overview'])->name('billing.overview');
        Route::get('/billing/history', [BillingController::class, 'history'])->name('billing.history');
        Route::get('/billing/gateway-status', [BillingController::class, 'gatewayStatus'])->name('billing.gateway_status');
        Route::get('/billing/runtime-config', [BillingController::class, 'checkoutRuntimeConfig'])->name('billing.runtime_config');
        Route::post('/billing/checkout-start', [BillingController::class, 'checkoutStart'])->name('billing.checkout_start');
        Route::post('/billing/subscriptions/{subscriptionId}/renew-mock', [BillingController::class, 'renewSubscriptionMock'])->name('billing.subscriptions.renew_mock');
        Route::post('/billing/subscriptions/{subscriptionId}/renew-wallet', [BillingController::class, 'renewSubscriptionWallet'])->name('billing.subscriptions.renew_wallet');
        Route::post('/billing/subscriptions/{subscriptionId}/auto-renew-wallet', [BillingController::class, 'setSubscriptionWalletAutoRenew'])->name('billing.subscriptions.auto_renew_wallet');
        Route::post('/billing/checkout-intent-mock', [BillingController::class, 'checkoutIntentMock'])->name('billing.checkout_intent_mock');
        Route::post('/billing/checkout-confirm-mock', [BillingController::class, 'checkoutConfirmMock'])->name('billing.checkout_confirm_mock');
        Route::post('/billing/checkout-confirm-wallet', [BillingController::class, 'checkoutConfirmWallet'])->name('billing.checkout_confirm_wallet');
        Route::post('/billing/wallet/topup-session', [BillingController::class, 'walletTopupSession'])->name('billing.wallet.topup_session');
        Route::post('/billing/wallet/topup-mock', [BillingController::class, 'walletTopupMock'])->name('billing.wallet.topup_mock');
        Route::get('/billing/wallet/auto-renew-preview', [BillingController::class, 'walletAutoRenewPreview'])->name('billing.wallet.auto_renew_preview');

        Route::prefix('consumer/notifications')->group(function () {
            Route::get('/', [ConsumerNotificationController::class, 'index']);
            Route::get('/unread-count', [ConsumerNotificationController::class, 'unreadCount']);
            Route::post('/read-all', [ConsumerNotificationController::class, 'markAllRead']);
            Route::post('/{id}/read', [ConsumerNotificationController::class, 'markRead']);
        });

        Route::prefix('consumer')->group(function () {
            Route::get('/products', [ConsumerProductController::class, 'index']);
            Route::get('/products/{slug}', [ConsumerProductController::class, 'show']);
            Route::post('/products/{id}/purchase', [ConsumerProductController::class, 'purchase']);
            Route::get('/products/{id}/purchase/status', [ConsumerProductController::class, 'purchaseStatus']);
            Route::post('/products/{id}/purchase/cancel', [ConsumerProductController::class, 'cancelPurchase']);
            Route::post('/products/{id}/download/{fileId}', [ConsumerProductController::class, 'download']);
            Route::get('/products/{id}/docs/{docId}/preview', [ConsumerProductController::class, 'previewDoc']);
            Route::get('/my-purchases', [ConsumerProductController::class, 'myPurchases']);

            Route::get('/onboarding/tips', [ConsumerOnboardingController::class, 'tips']);
            Route::post('/onboarding/dismiss', [ConsumerOnboardingController::class, 'dismiss']);
        });

        Route::get('/apps/landing-builder/probe', [EntitlementController::class, 'probeAllowed'])
            ->middleware('canUseApp:landing_builder')
            ->name('apps.landing_builder.probe');
        Route::get('/apps/landing-builder/pages', [LandingBuilderController::class, 'index'])
            ->middleware('canUseApp:landing_builder')
            ->name('apps.landing_builder.pages.index');
        Route::get('/apps/landing-builder/customers', [LandingBuilderController::class, 'customers'])
            ->middleware('canUseApp:landing_builder')
            ->name('apps.landing_builder.customers.index');
        Route::post('/apps/landing-builder/pages', [LandingBuilderController::class, 'store'])
            ->middleware('canUseApp:landing_builder')
            ->name('apps.landing_builder.pages.store');
        Route::get('/apps/landing-builder/pages/{id}', [LandingBuilderController::class, 'show'])
            ->middleware('canUseApp:landing_builder')
            ->name('apps.landing_builder.pages.show');
        Route::put('/apps/landing-builder/pages/{id}', [LandingBuilderController::class, 'update'])
            ->middleware('canUseApp:landing_builder')
            ->name('apps.landing_builder.pages.update');
        Route::delete('/apps/landing-builder/pages/{id}', [LandingBuilderController::class, 'destroy'])
            ->middleware('canUseApp:landing_builder')
            ->name('apps.landing_builder.pages.destroy');
        Route::post('/apps/landing-builder/pages/{id}/duplicate', [LandingBuilderController::class, 'duplicate'])
            ->middleware('canUseApp:landing_builder')
            ->name('apps.landing_builder.pages.duplicate');
        Route::get('/apps/landing-builder/templates', [LandingBuilderController::class, 'templates'])
            ->middleware('canUseApp:landing_builder')
            ->name('apps.landing_builder.templates.index');
        Route::get('/apps/landing-builder/templates/{key}', [LandingBuilderController::class, 'templateDetail'])
            ->middleware('canUseApp:landing_builder')
            ->name('apps.landing_builder.templates.show');
        Route::get('/apps/landing-builder/templates/{key}/block-keys', [LandingBuilderController::class, 'templateBlockKeys'])
            ->middleware('canUseApp:landing_builder')
            ->name('apps.landing_builder.templates.block_keys');
        Route::post('/apps/landing-builder/pages/{id}/apply-template', [LandingBuilderController::class, 'applyTemplate'])
            ->middleware('canUseApp:landing_builder')
            ->name('apps.landing_builder.pages.apply_template');
        Route::get('/apps/landing-builder/pages/{id}/blocks', [LandingBuilderController::class, 'blocks'])
            ->middleware('canUseApp:landing_builder')
            ->name('apps.landing_builder.pages.blocks.index');
        Route::post('/apps/landing-builder/pages/{id}/blocks', [LandingBuilderController::class, 'storeBlock'])
            ->middleware('canUseApp:landing_builder')
            ->name('apps.landing_builder.pages.blocks.store');
        Route::post('/apps/landing-builder/pages/{id}/blocks/reorder', [LandingBuilderController::class, 'reorderBlocks'])
            ->middleware('canUseApp:landing_builder')
            ->name('apps.landing_builder.pages.blocks.reorder');
        Route::put('/apps/landing-builder/pages/{id}/blocks/{blockId}', [LandingBuilderController::class, 'updateBlock'])
            ->middleware('canUseApp:landing_builder')
            ->name('apps.landing_builder.pages.blocks.update');
        Route::delete('/apps/landing-builder/pages/{id}/blocks/{blockId}', [LandingBuilderController::class, 'destroyBlock'])
            ->middleware('canUseApp:landing_builder')
            ->name('apps.landing_builder.pages.blocks.destroy');
        Route::post('/apps/landing-builder/pages/{id}/publish', [LandingBuilderController::class, 'publish'])
            ->middleware('canUseApp:landing_builder')
            ->name('apps.landing_builder.pages.publish');
        Route::post('/apps/landing-builder/pages/{id}/unpublish', [LandingBuilderController::class, 'unpublish'])
            ->middleware('canUseApp:landing_builder')
            ->name('apps.landing_builder.pages.unpublish');
        Route::get('/apps/landing-builder/pages/{id}/versions', [LandingBuilderController::class, 'versions'])
            ->middleware('canUseApp:landing_builder')
            ->name('apps.landing_builder.pages.versions');
        Route::post('/apps/landing-builder/pages/{id}/versions/{versionId}/restore', [LandingBuilderController::class, 'restoreVersion'])
            ->middleware('canUseApp:landing_builder')
            ->name('apps.landing_builder.pages.versions.restore');
        Route::get('/apps/landing-builder/pages/{id}/domains', [LandingBuilderController::class, 'domains'])
            ->middleware('canUseApp:landing_builder')
            ->name('apps.landing_builder.pages.domains.index');
        Route::post('/apps/landing-builder/pages/{id}/domains', [LandingBuilderController::class, 'storeDomain'])
            ->middleware('canUseApp:landing_builder')
            ->name('apps.landing_builder.pages.domains.store');
        Route::put('/apps/landing-builder/pages/{id}/domains/{domainId}', [LandingBuilderController::class, 'updateDomain'])
            ->middleware('canUseApp:landing_builder')
            ->name('apps.landing_builder.pages.domains.update');
        Route::delete('/apps/landing-builder/pages/{id}/domains/{domainId}', [LandingBuilderController::class, 'destroyDomain'])
            ->middleware('canUseApp:landing_builder')
            ->name('apps.landing_builder.pages.domains.destroy');
        Route::get('/apps/landing-builder/stats', [LandingBuilderController::class, 'stats'])
            ->middleware('canUseApp:landing_builder')
            ->name('apps.landing_builder.stats');
        Route::get('/apps/landing-builder/stats/pages', [LandingBuilderController::class, 'pageStats'])
            ->middleware('canUseApp:landing_builder')
            ->name('apps.landing_builder.stats.pages');
        Route::get('/apps/landing-builder/stats/funnel', [LandingBuilderController::class, 'funnelKpi'])
            ->middleware('canUseApp:landing_builder')
            ->name('apps.landing_builder.stats.funnel');
        Route::get('/apps/landing-builder/stats/performance', [LandingBuilderController::class, 'performanceSummary'])
            ->middleware('canUseApp:landing_builder')
            ->name('apps.landing_builder.stats.performance');
        Route::get('/apps/landing-builder/assets', [FileAssetController::class, 'index'])
            ->middleware('canUseApp:landing_builder')
            ->name('apps.landing_builder.assets.index');
        Route::post('/apps/landing-builder/assets/upload', [FileAssetController::class, 'upload'])
            ->middleware('canUseApp:landing_builder')
            ->name('apps.landing_builder.assets.upload');

        // ─── Product Purchase Settings ───
        Route::prefix('purchase-settings')->name('purchase_settings.')->middleware('canUseApp:pos')->group(function () {
            Route::get('/', [ProductPurchaseSettingController::class, 'index'])->name('index');
            Route::get('/active', [ProductPurchaseSettingController::class, 'getActive'])->name('active');
            Route::post('/', [ProductPurchaseSettingController::class, 'store'])->name('store');
            Route::put('/{id}', [ProductPurchaseSettingController::class, 'update'])->name('update');
            Route::delete('/{id}', [ProductPurchaseSettingController::class, 'destroy'])->name('destroy');
        });

        // ─── Promo: Validate ───
        Route::post('/promo/validate', [PromoCampaignController::class, 'validateCode'])->name('promo.validate');

        // ─── Invoices: Member ───
        Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices.index');
        Route::get('/invoices/{id}', [InvoiceController::class, 'show'])->name('invoices.show');

        // ─── Locale ───
        Route::post('/locale/switch', function (\Illuminate\Http\Request $request) {
            $validated = $request->validate(['locale' => ['required', 'in:id,en']]);
            $user = $request->user();
            if ($user instanceof \App\Models\User) {
                $user->forceFill(['locale' => $validated['locale']])->save();
            }
            session(['locale' => $validated['locale']]);
            return response()->json([
                'success' => true,
                'message' => 'Locale switched',
                'data' => ['locale' => $validated['locale']],
                'error' => null,
            ]);
        })->name('locale.switch');

        // ─── POS Routes ───
        Route::middleware(['canUseApp:pos', 'App\Http\Middleware\Api\InjectPosContext'])->group(function () {
            Route::get('/pos/orders', [PosOrderController::class, 'index']);
            Route::post('/pos/orders', [PosOrderController::class, 'store']);
            Route::patch('/pos/orders/{orderId}/status', [PosOrderController::class, 'updateStatus']);
Route::post('/pos/orders/{orderId}/payment', [HellomOrderController::class, 'confirmPayment']);
            Route::get('/pos/orders/{orderId}/receipt', [PosOrderController::class, 'receipt']);
            Route::get('/pos/products', [PosProductController::class, 'index']);
            Route::post('/pos/products', [PosProductController::class, 'store']);
            Route::post('/pos/products/{productId}', [PosProductController::class, 'update']); // For FormData with _method spoofing
            Route::patch('/pos/products/{productId}', [PosProductController::class, 'update']);
            Route::delete('/pos/products/{productId}', [PosProductController::class, 'destroy']);

            Route::get('/pos/categories', [PosCategoryController::class, 'index']);
            Route::post('/pos/categories', [PosCategoryController::class, 'store']);
            Route::patch('/pos/categories/{categoryId}', [PosCategoryController::class, 'update']);
            Route::delete('/pos/categories/{categoryId}', [PosCategoryController::class, 'destroy']);

            Route::get('/pos/tables', [PosTableController::class, 'index']);
            Route::post('/pos/tables', [PosTableController::class, 'store']);
            Route::patch('/pos/tables/{tableId}', [PosTableController::class, 'update']);
            Route::delete('/pos/tables/{tableId}', [PosTableController::class, 'destroy']);

            Route::get('/pos/payment-settings', [PosPaymentSettingController::class, 'index']);
            Route::post('/pos/payment-settings', [PosPaymentSettingController::class, 'update']);

            // Member management
            Route::prefix('pos/members')->group(function () {
                Route::get('/', [PosMemberController::class, 'index']);
                Route::get('/search', [PosMemberController::class, 'search']);
                Route::post('/', [PosMemberController::class, 'store']);
                Route::get('/{id}', [PosMemberController::class, 'show']);
                Route::put('/{id}', [PosMemberController::class, 'update']);
                Route::get('/{id}/points', [PosMemberController::class, 'pointHistory']);
            });

            // Loyalty
            Route::prefix('pos/loyalty')->group(function () {
                Route::post('/calculate', [PosLoyaltyController::class, 'calculatePoints']);
                Route::post('/apply-reward', [PosLoyaltyController::class, 'applyReward']);
                Route::get('/settings', [PosLoyaltyController::class, 'getSettings']);
                Route::put('/settings', [PosLoyaltyController::class, 'updateSettings']);
                Route::get('/reward-rules', [PosLoyaltyController::class, 'rewardRules']);
                Route::post('/reward-rules', [PosLoyaltyController::class, 'storeRewardRule']);
                Route::put('/reward-rules/{id}', [PosLoyaltyController::class, 'updateRewardRule']);
                Route::delete('/reward-rules/{id}', [PosLoyaltyController::class, 'deleteRewardRule']);
            });

            Route::prefix('pos/customer-experience')->group(function () {
                Route::get('/dashboard', [PosExperienceController::class, 'dashboard']);
                Route::post('/promos', [PosExperienceController::class, 'storePromo']);
                Route::post('/promos/{id}', [PosExperienceController::class, 'updatePromo']);
                Route::delete('/promos/{id}', [PosExperienceController::class, 'destroyPromo']);
                Route::post('/spaces', [PosExperienceController::class, 'storeSpace']);
                Route::post('/spaces/{id}', [PosExperienceController::class, 'updateSpace']);
                Route::delete('/spaces/{id}', [PosExperienceController::class, 'destroySpace']);
                Route::patch('/reservations/{id}/status', [PosExperienceController::class, 'updateReservationStatus']);
            });

            Route::prefix('pos/reports')->group(function () {
                Route::get('/summary', [PosReportController::class, 'summary']);
                Route::get('/products', [PosReportController::class, 'products']);
                Route::get('/daily', [PosReportController::class, 'daily']);
                Route::get('/export', [PosReportController::class, 'export']);
            });

            Route::prefix('pos/staff')->group(function () {
                Route::get('/', [PosStaffController::class, 'index']);
                Route::post('/', [PosStaffController::class, 'store']);
                Route::post('/attendance/scan', [PosStaffController::class, 'scanAttendanceQr']);
                Route::put('/{staffId}', [PosStaffController::class, 'update']);
                Route::delete('/{staffId}', [PosStaffController::class, 'destroy']);
                Route::get('/{staffId}/attendance-qr', [PosStaffController::class, 'showAttendanceQr']);
                Route::post('/{staffId}/attendance-qr/regenerate', [PosStaffController::class, 'regenerateAttendanceQr']);
                Route::post('/shifts', [PosStaffController::class, 'storeShift']);
                Route::put('/shifts/{shiftId}', [PosStaffController::class, 'updateShift']);
                Route::post('/{staffId}/attendance/check-in', [PosStaffController::class, 'checkIn']);
                Route::post('/{staffId}/attendance/check-out', [PosStaffController::class, 'checkOut']);
                Route::post('/{staffId}/attendance/leave', [PosStaffController::class, 'markLeave']);
                Route::post('/{staffId}/cash/open', [PosStaffController::class, 'openCash']);
                Route::post('/{staffId}/cash/close', [PosStaffController::class, 'closeCash']);
                Route::get('/export/download', [PosStaffController::class, 'export']);
            });

            Route::get('/apps/pos/probe', [EntitlementController::class, 'probeLocked'])
                ->name('apps.pos.probe');
            Route::get('/apps/pos/access', [EntitlementController::class, 'posAccess'])
                ->name('apps.pos.access');
        });

        // ─── AUTH + superAdmin ───
        Route::prefix('admin')->name('admin.')->middleware('superAdmin')->group(function () {
            Route::get('/dashboard-stats', [SuperAdminController::class, 'dashboardStats'])->name('dashboard_stats');

            Route::get('/organizations', [SuperAdminController::class, 'listOrganizations'])->name('organizations.index');
            Route::get('/organizations/{organizationId}', [SuperAdminController::class, 'showOrganization'])->name('organizations.show');
            Route::post('/organizations/{organizationId}/suspend', [SuperAdminController::class, 'suspendOrganization'])->name('organizations.suspend');
            Route::post('/organizations/{organizationId}/reactivate', [SuperAdminController::class, 'reactivateOrganization'])->name('organizations.reactivate');

            Route::get('/users', [SuperAdminController::class, 'listUsers'])->name('users.index');
            Route::get('/users/{userId}', [SuperAdminController::class, 'showUser'])->name('users.show');
            Route::post('/users/{userId}/suspend', [SuperAdminController::class, 'suspendUser'])->name('users.suspend');
            Route::post('/users/{userId}/reactivate', [SuperAdminController::class, 'reactivateUser'])->name('users.reactivate');
            Route::delete('/users/{userId}', [SuperAdminController::class, 'deleteUser'])->name('users.destroy');
            Route::put('/users/{userId}/app-access', [SuperAdminController::class, 'updateUserAppAccess'])->name('users.app_access.update');

            Route::get('/apps', [SuperAdminController::class, 'listApps'])->name('apps.index');
            Route::put('/apps/{appId}', [SuperAdminController::class, 'updateApp'])->name('apps.update');

            Route::get('/plans', [SuperAdminController::class, 'listPlans'])->name('plans.index');
            Route::post('/plans', [SuperAdminController::class, 'createPlan'])->name('plans.store');
            Route::put('/plans/{planId}', [SuperAdminController::class, 'updatePlan'])->name('plans.update');
            Route::delete('/plans/{planId}', [SuperAdminController::class, 'deletePlan'])->name('plans.destroy');
            Route::get('/plans/{planId}/subscriptions', [SuperAdminController::class, 'planSubscriptions'])->name('plans.subscriptions');

            Route::post('/entitlements/override', [SuperAdminController::class, 'overrideEntitlement'])->name('entitlements.override');

            Route::get('/audit-logs', [SuperAdminController::class, 'auditLogs'])->name('audit_logs');
            Route::put('/billing/runtime-config', [BillingController::class, 'updateCheckoutRuntimeConfig'])->name('billing.runtime_config.update');
            Route::get('/billing/provider-config', [BillingController::class, 'adminGatewayConfig'])->name('billing.provider_config');
            Route::put('/billing/provider-config', [BillingController::class, 'updateAdminGatewayConfig'])->name('billing.provider_config.update');
            Route::post('/billing/provider-config/ipaymu/reset', [BillingController::class, 'resetIpaymuConfig'])->name('billing.provider_config.ipaymu.reset');
            Route::get('/billing/manual-payment-config', [BillingController::class, 'adminManualPaymentConfig'])->name('billing.manual_payment_config');
            Route::post('/billing/manual-payment-config', [BillingController::class, 'updateAdminManualPaymentConfig'])->name('billing.manual_payment_config.update');
            Route::get('/billing/manual-checkouts', [BillingController::class, 'adminPendingCheckouts'])->name('billing.manual_checkouts');
            Route::post('/billing/manual-checkouts/{intentId}/approve', [BillingController::class, 'adminApproveManualCheckout'])->name('billing.manual_checkouts.approve');
            Route::post('/billing/manual-checkouts/{intentId}/reject', [BillingController::class, 'adminRejectManualCheckout'])->name('billing.manual_checkouts.reject');

            // ─── Promo Campaigns CRUD ───
            Route::get('/promos', [PromoCampaignController::class, 'index'])->name('promos.index');
            Route::get('/promos/{id}', [PromoCampaignController::class, 'show'])->name('promos.show');
            Route::post('/promos', [PromoCampaignController::class, 'store'])->name('promos.store');
            Route::put('/promos/{id}', [PromoCampaignController::class, 'update'])->name('promos.update');
            Route::delete('/promos/{id}', [PromoCampaignController::class, 'destroy'])->name('promos.destroy');

            // ─── Admin Invoices ───
            Route::get('/invoices', [InvoiceController::class, 'adminIndex'])->name('invoices.index');

            // ─── Showcase Management ───
            Route::post('/showcase/upload-media', [ShowcaseController::class, 'uploadMedia'])->name('showcase.upload_media');
            Route::get('/showcase/portfolios', [ShowcaseController::class, 'indexPortfolios'])->name('showcase.portfolios.index');
            Route::post('/showcase/portfolios', [ShowcaseController::class, 'storePortfolio'])->name('showcase.portfolios.store');
            Route::put('/showcase/portfolios/{id}', [ShowcaseController::class, 'updatePortfolio'])->name('showcase.portfolios.update');
            Route::delete('/showcase/portfolios/{id}', [ShowcaseController::class, 'destroyPortfolio'])->name('showcase.portfolios.destroy');
            Route::get('/showcase/clients', [ShowcaseController::class, 'indexClients'])->name('showcase.clients.index');
            Route::post('/showcase/clients', [ShowcaseController::class, 'storeClient'])->name('showcase.clients.store');
            Route::put('/showcase/clients/{id}', [ShowcaseController::class, 'updateClient'])->name('showcase.clients.update');
            Route::delete('/showcase/clients/{id}', [ShowcaseController::class, 'destroyClient'])->name('showcase.clients.destroy');
            Route::get('/landing-content', [LandingContentController::class, 'adminContent'])->name('landing_content.index');
            Route::put('/landing-content/about', [LandingContentController::class, 'updateAbout'])->name('landing_content.about.update');
            Route::post('/landing-content/services', [LandingContentController::class, 'storeService'])->name('landing_content.services.store');
            Route::put('/landing-content/services/{id}', [LandingContentController::class, 'updateService'])->name('landing_content.services.update');
            Route::delete('/landing-content/services/{id}', [LandingContentController::class, 'destroyService'])->name('landing_content.services.destroy');
            Route::post('/landing-content/articles', [LandingContentController::class, 'storeArticle'])->name('landing_content.articles.store');
            Route::put('/landing-content/articles/{id}', [LandingContentController::class, 'updateArticle'])->name('landing_content.articles.update');
            Route::delete('/landing-content/articles/{id}', [LandingContentController::class, 'destroyArticle'])->name('landing_content.articles.destroy');

            // ─── Hellom Brand Settings ───
            Route::get('/banners', [BannerController::class, 'index']);
            Route::post('/banners', [BannerController::class, 'store']);
            Route::post('/banners/{id}', [BannerController::class, 'update']);
            Route::delete('/banners/{id}', [BannerController::class, 'destroy']);
            Route::get('/brand', [BrandSettingController::class, 'publicShow']);
            Route::put('/brand', [BrandSettingController::class, 'update']);
            Route::post('/brand', [BrandSettingController::class, 'update']);
            Route::get('/mail-settings', [AdminMailController::class, 'showSettings']);
            Route::put('/mail-settings', [AdminMailController::class, 'updateSettings']);
            Route::post('/mail-settings/test', [AdminMailController::class, 'sendTest']);
            Route::post('/mail-settings/promo', [AdminMailController::class, 'sendPromo']);
            Route::post('/mail-settings/billing-reminder/{subscriptionId}', [AdminMailController::class, 'sendBillingReminder']);

            Route::get('/notifications', [\App\Http\Controllers\Admin\OwnerNotificationController::class, 'index']);
            Route::get('/notifications/unread-count', [\App\Http\Controllers\Admin\OwnerNotificationController::class, 'unreadCount']);
            Route::get('/notifications/{id}', [\App\Http\Controllers\Admin\OwnerNotificationController::class, 'show']);
            Route::patch('/notifications/{id}/read', [\App\Http\Controllers\Admin\OwnerNotificationController::class, 'markAsRead']);
            Route::patch('/notifications/read-all', [\App\Http\Controllers\Admin\OwnerNotificationController::class, 'markAllAsRead']);
            Route::post('/notifications/{id}/execute', [\App\Http\Controllers\Admin\OwnerNotificationController::class, 'executeAction']);
            Route::post('/notifications/{id}/ignore', [\App\Http\Controllers\Admin\OwnerNotificationController::class, 'ignoreAction']);
            Route::delete('/notifications/{id}', [\App\Http\Controllers\Admin\OwnerNotificationController::class, 'destroy']);

            Route::apiResource('digital-products', AdminDigitalProductController::class);
            Route::post('digital-products/{id}/publish', [AdminDigitalProductController::class, 'publish']);
            Route::post('digital-products/{id}/unpublish', [AdminDigitalProductController::class, 'unpublish']);
            Route::post('digital-products/{id}/thumbnail', [AdminDigitalProductController::class, 'uploadThumbnail']);
            Route::post('digital-products/{id}/files', [AdminDigitalProductController::class, 'uploadFile']);
            Route::post('digital-products/{id}/docs', [AdminDigitalProductController::class, 'uploadDoc']);
            Route::delete('digital-products/files/{fileId}', [AdminDigitalProductController::class, 'deleteFile']);
            Route::delete('digital-products/docs/{docId}', [AdminDigitalProductController::class, 'deleteDoc']);
            Route::get('digital-products/docs/{docId}/preview', [AdminDigitalProductController::class, 'previewDoc']);

            Route::get('product-purchases', [ProductPurchaseController::class, 'index']);
            Route::get('product-purchases/{id}', [ProductPurchaseController::class, 'show']);
            Route::post('product-purchases/{id}/approve', [ProductPurchaseController::class, 'approve']);
            Route::post('product-purchases/{id}/refund', [ProductPurchaseController::class, 'refund']);
        });
    });
});
