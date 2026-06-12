<?php

namespace App\Http\Controllers\Cashier;

use App\Http\Controllers\Controller;
use App\Services\Auth\DummyAuthService;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantUrlService;
use Illuminate\Http\Request;

class CashierLoginController extends Controller
{
    public function __construct(
        private readonly DummyAuthService $auth,
        private readonly TenantUrlService $urls,
    )
    {
    }

    public function showLoginForm(Request $request)
    {
        return $this->show($request);
    }

    public function show(Request $request)
    {
        $tenant = $this->resolveTenant($request);

        $basePath = $this->urls->basePathForRequestMode(
            routeTenantParam: $request->route('tenant'),
            tenantSlug: $tenant->slug,
        );

        return view('cashier.login', [
            'tenant' => $tenant,
            'basePath' => $basePath,
        ]);
    }

    public function login(Request $request)
    {
        $tenant = $this->resolveTenant($request);

        $basePath = $this->urls->basePathForRequestMode(
            routeTenantParam: $request->route('tenant'),
            tenantSlug: $tenant->slug,
        );

        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = $this->auth->attemptCashierLogin(
            $request,
            $tenant->slug,
            (string) $validated['email'],
            (string) $validated['password'],
        );

        if (!$user) {
            return back()->withErrors(['email' => 'Login kasir gagal (dummy auth).'])->withInput();
        }

        return redirect($this->urls->tenantCashier($basePath));
    }

    public function logout(Request $request)
    {
        $tenant = $this->resolveTenant($request);
        $basePath = $this->urls->basePathForRequestMode(
            routeTenantParam: $request->route('tenant'),
            tenantSlug: $tenant->slug,
        );
        $this->auth->logoutCashier($request);

        return redirect($this->urls->cashierLogin($basePath));
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
