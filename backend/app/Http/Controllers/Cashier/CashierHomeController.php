<?php

namespace App\Http\Controllers\Cashier;

use App\Http\Controllers\Controller;
use App\Services\Auth\DummyAuthService;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantUrlService;
use Illuminate\Http\Request;

class CashierHomeController extends Controller
{
    public function __construct(
        private readonly DummyAuthService $auth,
        private readonly TenantUrlService $urls,
    )
    {
    }

    public function index(Request $request)
    {
        return $this->__invoke($request);
    }

    public function __invoke(Request $request)
    {
        $tenant = $this->resolveTenant($request);
        $user = $this->auth->currentCashierUser($request);

        $basePath = $this->urls->basePathForRequestMode(
            routeTenantParam: $request->route('tenant'),
            tenantSlug: $tenant->slug,
        );

        return view('cashier.home', [
            'tenant' => $tenant,
            'user' => $user,
            'basePath' => $basePath,
        ]);
    }

    private function resolveTenant(Request $request): TenantContext
    {
        $tenant = $request->attributes->get('tenant');
        if ($tenant instanceof TenantContext) {
            return $tenant;
        }

        $slug = (string) ($request->route('tenant') ?? '');
        $all = (array) config('tenancy.tenants', []);

        if ($slug !== '' && isset($all[$slug]) && is_array($all[$slug])) {
            $row = $all[$slug];
            return new TenantContext(
                id: null,
                slug: $slug,
                name: (string) ($row['name'] ?? strtoupper($slug)),
                plan: (string) ($row['plan'] ?? 'trial'),
                status: (string) ($row['status'] ?? 'active'),
                trialStartedAt: isset($row['trial_started_at']) ? (string) $row['trial_started_at'] : null,
                activeUntil: isset($row['active_until']) ? (string) $row['active_until'] : null,
                subdomain: isset($row['subdomain']) ? (string) $row['subdomain'] : null,
                customDomain: isset($row['custom_domain']) ? (string) $row['custom_domain'] : null,
            );
        }

        foreach ($all as $fallbackSlug => $row) {
            if (!is_string($fallbackSlug) || !is_array($row)) {
                continue;
            }

            return new TenantContext(
                id: null,
                slug: $fallbackSlug,
                name: (string) ($row['name'] ?? strtoupper($fallbackSlug)),
                plan: (string) ($row['plan'] ?? 'trial'),
                status: (string) ($row['status'] ?? 'active'),
                trialStartedAt: isset($row['trial_started_at']) ? (string) $row['trial_started_at'] : null,
                activeUntil: isset($row['active_until']) ? (string) $row['active_until'] : null,
                subdomain: isset($row['subdomain']) ? (string) $row['subdomain'] : null,
                customDomain: isset($row['custom_domain']) ? (string) $row['custom_domain'] : null,
            );
        }

        return new TenantContext(
            id: null,
            slug: 'default',
            name: 'Default Organization',
            plan: 'trial',
            status: 'active',
            trialStartedAt: null,
            activeUntil: null,
            subdomain: null,
            customDomain: null,
        );
    }
}
