<?php

namespace App\Http\Middleware\AuthZ;

use App\Services\Auth\DummyAuthService;
use Closure;
use Illuminate\Http\Request;

class EnsureRoleIsSuperAdmin
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

        if ((string) ($user['role'] ?? '') !== 'super_admin') {
            if ($request->expectsJson()) {
                abort(403);
            }

            return redirect($this->appUrl($request, '/gateway'))
                ->with('error', 'Akses ditolak: hanya super admin.');
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
