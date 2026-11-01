<?php

namespace App\Http\Middleware\Api;

use App\Models\ApiToken;
use App\Models\PosStaff;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AuthenticateApiToken
{
    public function handle(Request $request, Closure $next)
    {
        $bearer = (string) $request->bearerToken();
        if ($bearer === '') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
                'data' => null,
                'error' => ['code' => 'UNAUTHORIZED', 'detail' => 'Missing bearer token'],
            ], 401);
        }

        $tokenHash = hash('sha256', $bearer);

        $apiToken = ApiToken::query()
            ->with('user')
            ->where('token_hash', $tokenHash)
            ->first();

        if (!$apiToken || !$apiToken->user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
                'data' => null,
                'error' => ['code' => 'UNAUTHORIZED', 'detail' => 'Invalid token'],
            ], 401);
        }

        if ($apiToken->expires_at !== null && $apiToken->expires_at->isPast()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
                'data' => null,
                'error' => ['code' => 'UNAUTHORIZED', 'detail' => 'Token expired'],
            ], 401);
        }

        if ($apiToken->user->isSuspended()) {
            return response()->json([
                'success' => false,
                'message' => __('hellom.account_suspended'),
                'data' => null,
                'error' => ['code' => 'ACCOUNT_SUSPENDED'],
            ], 403);
        }

        $user = $apiToken->user;
        if ($user->must_change_password
            && !$request->routeIs('*.auth.change_password', '*.auth.logout')) {
            return response()->json([
                'success' => false,
                'message' => 'Ganti kata sandi sementara sebelum menggunakan POS.',
                'data' => null,
                'error' => ['code' => 'PASSWORD_CHANGE_REQUIRED'],
            ], 403);
        }

        $organizationId = (int) ($user->current_organization_id ?? 0);
        if ($organizationId > 0) {
            $membershipRole = DB::table('organization_user')
                ->where('organization_id', $organizationId)
                ->where('user_id', $user->id)
                ->value('role');
            $isOrganizationAdmin = in_array((string) $membershipRole, ['owner', 'admin'], true);
            $staffRecords = PosStaff::query()
                ->forOrganization($organizationId)
                ->where('linked_user_id', $user->id);
            $hasStaffRecord = (clone $staffRecords)->exists();
            $hasActiveStaffRecord = (clone $staffRecords)->where('employment_status', 'active')->exists();

            if ($hasStaffRecord && !$hasActiveStaffRecord && !$isOrganizationAdmin) {
                return response()->json([
                    'success' => false,
                    'message' => 'Akun POS ini sudah dinonaktifkan. Hubungi Admin Utama.',
                    'data' => null,
                    'error' => ['code' => 'POS_ACCOUNT_INACTIVE'],
                ], 403);
            }
        }

        $apiToken->forceFill(['last_used_at' => Carbon::now()])->save();

        $request->attributes->set('apiToken', $apiToken);
        $request->setUserResolver(fn() => $apiToken->user);

        return $next($request);
    }
}
