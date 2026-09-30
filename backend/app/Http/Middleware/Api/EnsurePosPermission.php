<?php

namespace App\Http\Middleware\Api;

use App\Models\PosStaff;
use App\Support\Pos\PosPermissions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `posPermission:<key>` on POS routes. Runs after InjectPosContext, which attaches the
 * `posStaff` record only for non-managers (cashiers / POS staff accounts). Org owners and
 * admins have no `posStaff` attribute and always pass. `posPermission:manager` = owner/admin only.
 */
class EnsurePosPermission
{
    public function handle(Request $request, Closure $next, string $key): Response
    {
        $staff = $request->attributes->get('posStaff');
        if (!$staff instanceof PosStaff || PosPermissions::allows($staff, $key)) {
            return $next($request);
        }

        $label = PosPermissions::CATALOG[$key][0] ?? null;

        return response()->json([
            'success' => false,
            'message' => $label
                ? "Akun kamu belum punya akses \"{$label}\". Minta owner/admin mengaktifkannya di POS › Staff."
                : 'Fitur ini hanya untuk owner/admin outlet.',
            'data' => null,
            'error' => ['code' => 'POS_PERMISSION_DENIED', 'permission' => $key],
        ], 403);
    }
}
