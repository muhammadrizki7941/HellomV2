<?php

namespace App\Http\Middleware\Api;

use App\Models\User;
use App\Services\Hellom\PosProvisioningService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class InjectPosContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $organization = $user->currentOrganization;
        if (!$organization) {
            return response()->json(['success' => false, 'message' => 'No organization'], 403);
        }

        $posTenantSlug = $organization->pos_tenant_slug;
        if (empty($posTenantSlug)) {
            $organization = app(PosProvisioningService::class)->ensureProvisionedForPos((int) $organization->id) ?: $organization;
            $posTenantSlug = $organization->pos_tenant_slug;
        }

        if (empty($posTenantSlug)) {
            return response()->json([
                'success' => false,
                'message' => 'POS belum siap untuk organisasi ini. Silakan klik Coba Lagi atau hubungi owner.',
                'error' => ['code' => 'POS_NOT_PROVISIONED'],
            ], 403);
        }

        $request->attributes->set('posTenantSlug', $posTenantSlug);
        $request->attributes->set('currentOrganizationId', $organization->id);

        return $next($request);
    }
}
