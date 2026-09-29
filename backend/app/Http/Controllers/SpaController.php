<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The React app shell (frontend build in public/hellom). Served for every non-API path
 * that is not a Hellom Page shop; the React router resolves it.
 */
class SpaController extends Controller
{
    public function __invoke(Request $request): Response
    {
        if ($request->is('api/*')) {
            abort(404);
        }
        // A verified custom domain shows its Hellom Page at "/".
        if ($custom = app(LandingPublicController::class)->forCustomDomain($request)) {
            return $custom;
        }

        return self::shell();
    }

    public static function shell(): Response
    {
        $spaPath = public_path('hellom/index.html');
        if (!file_exists($spaPath)) {
            abort(503, 'Hellom UI assets not found. Run: npm --prefix frontend run build');
        }

        return response()->file($spaPath, ['Cache-Control' => 'no-cache']);
    }
}
