<?php

namespace App\Http\Middleware\AuthZ;

use App\Services\Auth\DummyAuthService;
use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;

class EnsureRoleIsTenantAdminOrCashier
{
    public function __construct(private readonly DummyAuthService $auth)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        $tenantSlug = (string) ($request->route('tenant') ?? '');
        /** @var TenantContext|null $tenant */
        $tenant = $request->attributes->get('tenant');
        $effectiveTenantSlug = $tenantSlug !== '' ? $tenantSlug : (string) ($tenant?->slug ?? '');

        // Try global auth first (for tenant admins)
        $globalUser = $this->auth->currentGlobalUser($request);
        if ($globalUser) {
            $role = (string) ($globalUser['role'] ?? '');
            if (in_array($role, ['tenant_admin', 'super_admin'], true)) {
                if ($role === 'tenant_admin') {
                    $allowedTenants = (array) ($globalUser['allowed_tenants'] ?? []);
                    $isAllowed = in_array('*', $allowedTenants, true) || in_array($effectiveTenantSlug, $allowedTenants, true);

                    if (!$isAllowed) {
                        if ($request->expectsJson()) {
                            abort(403);
                        }
                        return redirect($this->appUrl($request, '/gateway'))
                            ->with('error', 'Akses ditolak: tenant admin tidak punya akses ke tenant ini.');
                    }
                }
                return $next($request);
            }
        }

        // Try cashier auth
        $cashierUser = $this->auth->currentCashierUser($request);
        if ($cashierUser) {
            if ((string) ($cashierUser['role'] ?? '') === 'cashier') {
                if ($effectiveTenantSlug !== '' && (string) ($cashierUser['tenant'] ?? '') !== $effectiveTenantSlug) {
                    if ($request->expectsJson()) {
                        abort(403);
                    }
                    $loginPath = $tenantSlug !== '' ? "/t/{$tenantSlug}/cashier/login" : '/cashier/login';
                    return redirect($loginPath)
                        ->with('error', 'Akses ditolak: sesi kasir tenant berbeda.');
                }
                return $next($request);
            }
        }

        // No valid authentication found
        return redirect($this->appUrl($request, '/login'))
            ->with('error', 'Silakan login terlebih dahulu.');
    }

    private function appUrl(Request $request, string $path): string
    {
        $domains = (array) config('tenancy.app_domains', [config('tenancy.app_domain', '')]);
        $domains = array_values(array_filter($domains, fn ($d) => is_string($d) && trim($d) !== ''));

        $appDomain = (string) ($domains[0] ?? config('tenancy.app_domain', ''));
        if ($appDomain === '') {
            return $path;
        }

        $host = strtolower((string) $request->getHost());
        foreach ($domains as $d) {
            if ($host === strtolower((string) $d)) {
                return $path;
            }
        }

        $scheme = $request->getScheme();
        return $scheme.'://'.$appDomain.$path;
    }
}