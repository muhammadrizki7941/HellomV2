<?php

namespace App\Http\Middleware\Api;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hellom Page (LB-24): only owners/admins of the current organization (or a super admin)
 * may edit the shop's pages, domains, files and read its leads. Cashiers/members of the
 * same organization (POS staff) may not.
 */
class EnsureShopManager
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->deny('Unauthorized', 'UNAUTHORIZED', 401);
        }
        if ((string) $user->role === 'super_admin') {
            return $next($request);
        }

        $role = (string) ($user->organizations()
            ->where('organizations.id', (int) $user->current_organization_id)
            ->first()?->pivot?->role ?? '');
        if (!in_array($role, ['owner', 'admin'], true)) {
            return $this->deny('Hanya pemilik/admin toko yang bisa mengubah halaman toko', 'INSUFFICIENT_ROLE', 403);
        }

        return $next($request);
    }

    private function deny(string $message, string $code, int $status): Response
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'data' => null,
            'error' => ['code' => $code],
        ], $status);
    }
}
