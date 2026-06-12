<?php

namespace App\Http\Middleware;

use App\Models\AppCatalog;
use App\Models\Entitlement;
use App\Models\Organization;
use App\Models\User;
use App\Services\Hellom\PosProvisioningService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureWebPosEntitlement
{
    public function __construct(private readonly PosProvisioningService $posProvisioning)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->deny($request, 'Unauthorized', 'UNAUTHORIZED', 401);
        }

        $organizationId = (int) ($user->current_organization_id ?? 0);

        // Keep legacy admin operation intact when organization context is not yet used.
        if ($organizationId <= 0 && $user->isAdmin()) {
            return $next($request);
        }

        if ($organizationId <= 0) {
            return $this->deny($request, 'No active organization', 'NO_ACTIVE_ORGANIZATION', 403);
        }

        $organization = Organization::query()->find($organizationId);
        if (!$organization instanceof Organization || (string) $organization->status !== 'active') {
            return $this->deny($request, 'Organization is not active', 'ORG_INACTIVE', 403);
        }

        $appId = AppCatalog::query()
            ->where('slug', 'pos')
            ->where('is_active', true)
            ->value('id');

        if (!$appId) {
            return $this->deny($request, 'App not available', 'APP_NOT_AVAILABLE', 404);
        }

        $entitlement = Entitlement::query()
            ->where('organization_id', $organizationId)
            ->where('app_id', $appId)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->first();

        $status = (string) ($entitlement?->status ?? 'locked');
        $allowed = in_array($status, ['active', 'trialing'], true);

        if ($allowed && $entitlement?->ends_at && $entitlement->ends_at->isPast()) {
            $allowed = false;
            $status = 'expired';
        }

        if (!$allowed) {
            return $this->deny(
                $request,
                'App is locked for current organization',
                'APP_LOCKED',
                403,
                [
                    'app' => 'pos',
                    'status' => $status,
                ]
            );
        }

        $organization = $this->posProvisioning->ensureProvisionedForPos($organizationId) ?? $organization;

        $request->attributes->set('currentOrganization', $organization);
        $request->attributes->set('currentEntitlement', $entitlement);

        return $next($request);
    }

    private function deny(Request $request, string $message, string $code, int $status, ?array $data = null): Response
    {
        if ($request->expectsJson()) {
            return response()->json([
                'success' => false,
                'message' => $message,
                'data' => $data,
                'error' => ['code' => $code],
            ], $status);
        }

        return response($message, $status);
    }
}
