<?php

namespace App\Providers;

use App\Models\BrandSetting;
use App\Models\OrganizationLandingPage;
use App\Policies\LandingPagePolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\View;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        View::share('brand', BrandSetting::current());

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
