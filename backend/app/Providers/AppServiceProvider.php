<?php

namespace App\Providers;

use App\Models\BrandSetting;
use App\Models\OrganizationLandingPage;
use App\Policies\LandingPagePolicy;
use Illuminate\Support\Facades\Gate;
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
