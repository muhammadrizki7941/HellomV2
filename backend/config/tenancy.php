<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Domains
    |--------------------------------------------------------------------------
    | app_domain  : where marketing/auth/gateway live (global, non-tenant)
    | base_domain : base for tenant subdomains (e.g. {tenant}.base_domain)
    |
    | In local you can keep defaults, or set:
    | - TENANCY_APP_DOMAIN=saas.test
    | - TENANCY_BASE_DOMAIN=saas.test
    | and map hosts like alpha.saas.test to 127.0.0.1
    */

    'app_domain' => env('TENANCY_APP_DOMAIN', parse_url(env('APP_URL', 'http://localhost'), PHP_URL_HOST) ?: 'localhost'),

    /*
    | Allow multiple global domains for local/dev convenience.
    | Example: TENANCY_APP_DOMAINS=localhost,127.0.0.1
    */
    'app_domains' => (function (): array {
        $raw = (string) env('TENANCY_APP_DOMAINS', '');
        if (trim($raw) !== '') {
            $parts = array_map('trim', explode(',', $raw));
            $parts = array_values(array_unique(array_filter($parts, fn ($d) => $d !== '')));
            return $parts;
        }

        $appDomain = (string) env('TENANCY_APP_DOMAIN', parse_url(env('APP_URL', 'http://localhost'), PHP_URL_HOST) ?: 'localhost');
        if (in_array($appDomain, ['localhost', '127.0.0.1'], true)) {
            return ['localhost', '127.0.0.1'];
        }

        return [$appDomain];
    })(),
    'base_domain' => env('TENANCY_BASE_DOMAIN', env('TENANCY_APP_DOMAIN', parse_url(env('APP_URL', 'http://localhost'), PHP_URL_HOST) ?: 'localhost')),

    /*
    |--------------------------------------------------------------------------
    | Dummy tenant registry (no DB)
    |--------------------------------------------------------------------------
    | Foundation stage only.
    | Replace with Tenant model + database lookup later.
    */

    'tenants' => [
        'alpha' => [
            'id' => 1,
            'name' => 'Alpha Cafe',
            'plan' => 'trial',
            // DEMO toggle:
            // - set TENANCY_DEMO_ALPHA_INACTIVE=true to simulate inactive tenant
            // - set TENANCY_DEMO_ALPHA_TRIAL_STARTED_AT=YYYY-MM-DD to simulate trial window changes
            'status' => env('TENANCY_DEMO_ALPHA_INACTIVE', false) ? 'inactive' : 'active',
            // Trial window is validated by service (3 days). Start date is used as reference.
            'trial_started_at' => env('TENANCY_DEMO_ALPHA_TRIAL_STARTED_AT', '2026-01-15'),
            'active_until' => null,
            'subdomain' => 'alpha',
            'custom_domain' => null,
        ],
        'beta' => [
            'id' => 2,
            'name' => 'Beta Resto',
            'plan' => 'pro',
            'status' => 'active',
            'trial_started_at' => null,
            // DEMO toggle:
            // - set TENANCY_DEMO_BETA_ACTIVE_UNTIL=YYYY-MM-DD to simulate expired pro tenant
            'active_until' => env('TENANCY_DEMO_BETA_ACTIVE_UNTIL', '2026-12-31'),
            'subdomain' => 'beta',
            'custom_domain' => 'kasir.beta.example',
        ],

        // DEMO tenant (always expired) for quick UI testing.
        'expired' => [
            'id' => 3,
            'name' => 'Expired Demo',
            'plan' => 'trial',
            'status' => 'active',
            'trial_started_at' => '2025-01-01',
            'active_until' => null,
            'subdomain' => 'expired',
            'custom_domain' => null,
        ],
    ],
];
