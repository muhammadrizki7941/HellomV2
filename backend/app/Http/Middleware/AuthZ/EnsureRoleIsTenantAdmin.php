<?php

namespace App\Http\Middleware\AuthZ;

use App\Services\Auth\DummyAuthService;
use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;

class EnsureRoleIsTenantAdmin
{
    public function __construct(private readonly DummyAuthService $auth)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        $user = $this->auth->currentGlobalUser($request);
        if (!$user) {
            return redirect($this->appUrl($request, '/login'))
                ->with('error', 'Silakan login terlebih dahulu.');
        }

        $role = (string) ($user['role'] ?? '');
        if (!in_array($role, ['tenant_admin', 'super_admin'], true)) {
            if ($request->expectsJson()) {
                abort(403);
            }

            return redirect($this->appUrl($request, '/gateway'))
                ->with('error', 'Akses ditolak: hanya tenant admin.');
        }

        if ($role === 'tenant_admin') {
            $routeTenantSlug = (string) ($request->route('tenant') ?? '');
            /** @var TenantContext|null $tenant */
            $tenant = $request->attributes->get('tenant');
            $effectiveTenantSlug = $routeTenantSlug !== '' ? $routeTenantSlug : (string) ($tenant?->slug ?? '');

            if ($effectiveTenantSlug !== '') {
                $allowedTenants = (array) ($user['allowed_tenants'] ?? []);
                $isAllowed = in_array('*', $allowedTenants, true) || in_array($effectiveTenantSlug, $allowedTenants, true);

                if (!$isAllowed) {
                    if ($request->expectsJson()) {
                        abort(403);
                    }

                    return redirect($this->appUrl($request, '/gateway'))
                        ->with('error', 'Akses ditolak: tenant admin tidak punya akses ke tenant ini.');
                }
            }
        }

        return $next($request);
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
