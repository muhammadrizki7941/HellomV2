<?php

namespace App\Providers;

use App\Models\OrganizationLandingPage;
use App\Policies\LandingPagePolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Proof required to redeem member points (swap for an OTP verifier later).
        $this->app->bind(\App\Services\Pos\Verification\PointRedemptionVerifier::class, \App\Services\Pos\Verification\NameConfirmationVerifier::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // ─── API rate limits (routes/api/public.php) ───
        // Auth: brute-force protection per email + IP.
        RateLimiter::for('hellom-auth', function (Request $request) {
            return Limit::perMinute(10)->by(strtolower((string) $request->input('email')) . '|' . $request->ip());
        });
        // Public writes (self-order, member register, promo claim, reservations,
        // landing leads/checkout). Generous: restaurant guests often share one IP.
        RateLimiter::for('hellom-public-write', function (Request $request) {
            return Limit::perMinute(60)->by($request->ip());
        });
        // Member lookup by phone number (enumeration protection).
        RateLimiter::for('hellom-public-lookup', function (Request $request) {
            return Limit::perMinute(30)->by($request->ip());
        });
        // Guest digital-product checkout: creates accounts and sends email.
        RateLimiter::for('hellom-guest-checkout', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        // Hellom Page checkout: each call opens a gateway session and reserves stock.
        RateLimiter::for('hellom-landing-checkout', function (Request $request) {
            return [
                Limit::perMinute(10)->by('ip:' . $request->ip()),
                Limit::perHour(20)->by('buyer:' . strtolower((string) $request->input('buyer_email')) . '|' . (string) $request->route('publicId')),
            ];
        });
        // "Cek pesanan", resend access email, verification email: sends mail.
        RateLimiter::for('hellom-landing-mail', function (Request $request) {
            return [Limit::perMinute(5)->by($request->ip()), Limit::perHour(30)->by($request->ip())];
        });
        // "Laporkan" from public pages.
        RateLimiter::for('hellom-landing-report', function (Request $request) {
            return Limit::perHour(10)->by($request->ip());
        });

        // Self-order submit: per QR token (one table cannot flood the kitchen) and per IP.
        RateLimiter::for('hellom-self-order', function (Request $request) {
            $token = (string) $request->input('table_token', $request->route('tableToken') ?? '');

            // The shop-link "counter" token is shared by every online guest, hence the
            // tighter per-guest limit and the looser per-token one.
            return [
                Limit::perMinute(6)->by('table-ip:' . $token . '|' . $request->ip()),
                Limit::perMinute(30)->by('table:' . $token),
                Limit::perMinute(20)->by('ip:' . $request->ip()),
            ];
        });

        // ─── POS order side effects (stock, points, socket, audit, fraud) ───
        Event::subscribe(\App\Listeners\Pos\OrderStockSubscriber::class);
        Event::subscribe(\App\Listeners\Pos\OrderSideEffectsSubscriber::class);

        // ─── RBAC: Policy bindings + super-admin bypass ───
        Gate::policy(OrganizationLandingPage::class, LandingPagePolicy::class);

        Gate::before(function ($user, $ability) {
            if ($user->role === 'super_admin') {
                return true; // super-admin bypasses all policies
            }
            return null;
        });

        // Load global system settings if available and apply them to runtime config
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('system_settings')) {
                $s = \App\Models\SystemSetting::allAsArray();

                if (!empty($s['app_name'])) {
                    config(['app.name' => $s['app_name']]);
                }

                if (!empty($s['default_locale'])) {
                    config(['app.locale' => $s['default_locale']]);
                }

                if (!empty($s['timezone'])) {
                    config(['app.timezone' => $s['timezone']]);
                    date_default_timezone_set($s['timezone']);
                }

                if (!empty($s['support_email'])) {
                    config(['mail.from.address' => $s['support_email']]);
                }

                // currency is app-specific; store it in config for runtime access
                if (!empty($s['currency'])) {
                    config(['app.currency' => $s['currency']]);
                }
            }
        } catch (\Throwable $e) {
            // ignore (migrations not run yet or DB unavailable during some operations)
        }
    }
}
