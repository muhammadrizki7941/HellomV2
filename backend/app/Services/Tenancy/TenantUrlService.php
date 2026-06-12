<?php

namespace App\Services\Tenancy;

final class TenantUrlService
{
    public function basePathForRequestMode(mixed $routeTenantParam, string $tenantSlug): string
    {
        $routeTenant = is_string($routeTenantParam) ? trim($routeTenantParam) : '';

        if ($routeTenant !== '') {
            return '/t/' . $routeTenant;
        }

        return '';
    }

    public function tenantCashier(string $basePath): string
    {
        return ($basePath === '' ? '' : $basePath) . '/cashier';
    }

    public function cashierLogin(string $basePath): string
    {
        return ($basePath === '' ? '' : $basePath) . '/cashier/login';
    }
}
