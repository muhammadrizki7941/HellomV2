<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $allowed = ['id', 'en'];

        $locale = session('locale');

        if (!is_string($locale) || !in_array($locale, $allowed, true)) {
            $preferred = $request->getPreferredLanguage($allowed);
            $default = config('app.locale', 'id');
            $locale = in_array($preferred, $allowed, true) ? $preferred : (in_array($default, $allowed, true) ? $default : 'id');
            session(['locale' => $locale]);
        }

        app()->setLocale($locale);

        return $next($request);
    }
}
