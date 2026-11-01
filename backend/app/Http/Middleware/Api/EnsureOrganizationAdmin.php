<?php

namespace App\Http\Middleware\Api;

use App\Models\User;
use App\Support\Pos\PosRoles;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOrganizationAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $organizationId = (int) ($user?->current_organization_id ?? 0);
        $role = $user instanceof User && $organizationId > 0
            ? $user->organizations()->where('organizations.id', $organizationId)->first()?->pivot?->role
            : null;

        if (!PosRoles::isOrganizationAdminMembership($role)) {
            return response()->json([
                'success' => false,
                'message' => 'Akses ini hanya tersedia untuk Admin Utama organisasi.',
                'data' => null,
                'error' => ['code' => 'ORGANIZATION_ADMIN_REQUIRED'],
            ], 403);
        }

        return $next($request);
    }
}
