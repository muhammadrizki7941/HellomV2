<?php

namespace App\Http\Middleware\AuthZ;

use App\Services\Auth\DummyAuthService;
use Closure;
use Illuminate\Http\Request;

class EnsureGlobalAuthenticated
{
    public function __construct(private readonly DummyAuthService $auth)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        if (!$this->auth->currentGlobalUser($request)) {
            $loginUrl = $this->appUrl($request, '/login');

            return redirect($loginUrl)
                ->with('error', 'Silakan login terlebih dahulu.');
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
