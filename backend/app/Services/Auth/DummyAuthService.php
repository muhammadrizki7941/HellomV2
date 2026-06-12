<?php

namespace App\Services\Auth;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use App\Models\Tenant;

final class DummyAuthService
{
    private const SESSION_GLOBAL = 'dummy_auth.global_user';
    private const SESSION_CASHIER = 'dummy_auth.cashier_user';

    /** @return array<string,mixed>|null */
    public function currentGlobalUser(Request $request): ?array
    {
        $u = $request->session()->get(self::SESSION_GLOBAL);
        return is_array($u) ? $u : null;
    }

    /** @return array<string,mixed>|null */
    public function currentCashierUser(Request $request): ?array
    {
        $u = $request->session()->get(self::SESSION_CASHIER);
        return is_array($u) ? $u : null;
    }

    public function logoutGlobal(Request $request): void
    {
        $request->session()->forget(self::SESSION_GLOBAL);
    }

    public function logoutCashier(Request $request): void
    {
        $request->session()->forget(self::SESSION_CASHIER);
    }

    /**
     * Whether dummy auth is enabled via config/env.
     */
    private function isEnabled(): bool
    {
        return (bool) config('dummy_auth.enabled', true);
    }

    /** @return array<string,mixed>|null */
    public function attemptGlobalLogin(Request $request, string $email, string $password): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $user = $this->findUser($email, $password);
        if (!$user) {
            $user = $this->dynamicTenantAdminUser($email, $password);
        }
        if (!$user) {
            return null;
        }

        if (($user['role'] ?? null) === 'cashier') {
            return null;
        }

        $request->session()->put(self::SESSION_GLOBAL, $user);

        return $user;
    }

    /**
     * Dev helper: login without password.
     * Keep local-only at route/middleware level.
     *
     * @return array<string,mixed>|null
     */
    public function loginGlobalAs(Request $request, string $email): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $email = strtolower(trim($email));
        $users = (array) config('dummy_auth.users', []);

        foreach ($users as $u) {
            if (!is_array($u)) {
                continue;
            }

            if (strtolower((string) ($u['email'] ?? '')) !== $email) {
                continue;
            }

            if (($u['role'] ?? null) === 'cashier') {
                return null;
            }

            $request->session()->put(self::SESSION_GLOBAL, $u);

            return $u;
        }

        return null;
    }

    /** @return array<string,mixed>|null */
    public function attemptCashierLogin(Request $request, string $tenantSlug, string $email, string $password): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $user = $this->findUser($email, $password);
        if (!$user) {
            $user = $this->dynamicCashierUser($tenantSlug, $email, $password);
        }
        if (!$user) {
            return null;
        }

        if (($user['role'] ?? null) !== 'cashier') {
            return null;
        }

        $allowedTenants = (array) ($user['allowed_tenants'] ?? []);
        if (!in_array('*', $allowedTenants, true) && !in_array($tenantSlug, $allowedTenants, true)) {
            return null;
        }

        $user['tenant'] = $tenantSlug;
        $request->session()->put(self::SESSION_CASHIER, $user);

        return $user;
    }

    /** @return array<string,mixed>|null */
    private function findUser(string $email, string $password): ?array
    {
        $email = strtolower(trim($email));
        $users = (array) config('dummy_auth.users', []);

        foreach ($users as $u) {
            if (!is_array($u)) {
                continue;
            }

            if (strtolower((string) ($u['email'] ?? '')) !== $email) {
                continue;
            }

            if ((string) ($u['password'] ?? '') !== $password) {
                return null;
            }

            return $u;
        }

        return null;
    }

    /** @return array<string,mixed>|null */
    private function dynamicTenantAdminUser(string $email, string $password): ?array
    {
        if (!(bool) config('dummy_auth.dynamic_users', true)) {
            return null;
        }

        $email = strtolower(trim($email));
        if ($password !== 'admin') {
            return null;
        }

        if (!preg_match('/^admin@([a-z0-9-]+)\.test$/', $email, $m)) {
            return null;
        }

        $slug = (string) ($m[1] ?? '');
        if ($slug === '' || !$this->tenantExists($slug)) {
            return null;
        }

        return [
            'email' => $email,
            'password' => 'admin',
            'name' => strtoupper($slug).' Admin',
            'role' => 'tenant_admin',
            'allowed_tenants' => [$slug],
        ];
    }

    /** @return array<string,mixed>|null */
    private function dynamicCashierUser(string $tenantSlug, string $email, string $password): ?array
    {
        if (!(bool) config('dummy_auth.dynamic_users', true)) {
            return null;
        }

        $tenantSlug = strtolower(trim($tenantSlug));
        $email = strtolower(trim($email));

        if ($tenantSlug === '' || $password !== 'cashier') {
            return null;
        }

        if ($email !== "cashier@{$tenantSlug}.test") {
            return null;
        }

        if (!$this->tenantExists($tenantSlug)) {
            return null;
        }

        return [
            'email' => $email,
            'password' => 'cashier',
            'name' => strtoupper($tenantSlug).' Cashier',
            'role' => 'cashier',
            'allowed_tenants' => [$tenantSlug],
        ];
    }

    private function tenantExists(string $slug): bool
    {
        $slug = strtolower(trim($slug));
        if ($slug === '') {
            return false;
        }

        try {
            if (Schema::hasTable('tenants')) {
                return Tenant::query()->where('slug', $slug)->exists();
            }
        } catch (\Throwable) {
            // ignore
        }

        $tenants = (array) config('tenancy.tenants', []);
        return array_key_exists($slug, $tenants);
    }
}
