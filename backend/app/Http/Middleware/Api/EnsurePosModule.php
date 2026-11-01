<?php

namespace App\Http\Middleware\Api;

use App\Models\DiningTable;
use App\Models\Organization;
use App\Models\Outlet;
use App\Support\Pos\BusinessProfile;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePosModule
{
    public function handle(Request $request, Closure $next, string $module): Response
    {
        $organization = $this->resolveOrganization($request);
        if (!$organization || BusinessProfile::hasModule($organization, $module)) {
            return $next($request);
        }

        return response()->json([
            'success' => false,
            'message' => 'Modul ini tidak aktif untuk usaha kamu. Aktifkan melalui pengaturan POS.',
            'data' => null,
            'error' => ['code' => 'POS_MODULE_DISABLED', 'module' => $module],
        ], 403);
    }

    private function resolveOrganization(Request $request): ?Organization
    {
        $organizationId = $request->attributes->get('currentOrganizationId');
        if ($organizationId) {
            return Organization::query()->find((int) $organizationId);
        }

        $organizationSlug = $request->route('organizationSlug');
        if (is_string($organizationSlug) && $organizationSlug !== '') {
            return Organization::query()->where('slug', $organizationSlug)->first();
        }

        $tableToken = $request->route('tableToken')
            ?? $request->input('table_token')
            ?? $request->query('table_token');
        if (!is_string($tableToken) || $tableToken === '') {
            return null;
        }

        $table = DiningTable::withoutGlobalScope('tenant')
            ->where('public_id', $tableToken)
            ->first();
        if (!$table) {
            return null;
        }

        $outlet = $table->outlet_id
            ? Outlet::query()->find((int) $table->outlet_id)
            : Outlet::query()->where('tenant_slug', $table->tenant_id)->first();
        if (!$outlet || (string) $outlet->tenant_slug !== (string) $table->tenant_id) {
            return null;
        }

        return Organization::query()->find((int) $outlet->organization_id);
    }
}
