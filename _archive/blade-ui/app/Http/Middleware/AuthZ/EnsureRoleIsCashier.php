<?php

namespace App\Http\Middleware\AuthZ;

use App\Services\Auth\DummyAuthService;
use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;

class EnsureRoleIsCashier
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

        $loginPath = $tenantSlug !== ''
            ? "/t/{$tenantSlug}/cashier/login"
            : '/cashier/login';

        $user = $this->auth->currentCashierUser($request);
        if (!$user) {
            return redirect($loginPath)
                ->with('error', 'Silakan login kasir terlebih dahulu.');
        }

        if ((string) ($user['role'] ?? '') !== 'cashier') {
            if ($request->expectsJson()) {
                abort(403);
            }

            return redirect($loginPath)
                ->with('error', 'Akses ditolak: role bukan cashier.');
        }

        if ($effectiveTenantSlug !== '' && (string) ($user['tenant'] ?? '') !== $effectiveTenantSlug) {
            if ($request->expectsJson()) {
                abort(403);
            }

            return redirect($loginPath)
                ->with('error', 'Akses ditolak: sesi kasir tenant berbeda.');
        }

        return $next($request);
    }
}
