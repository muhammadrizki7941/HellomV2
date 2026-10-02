<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(\App\Http\Middleware\TrustProxies::class);
        $middleware->web(append: [
            \App\Http\Middleware\SetLocale::class,
        ]);

        $middleware->alias([
            'set.locale' => \App\Http\Middleware\SetLocale::class,
            'canUseApp' => \App\Http\Middleware\Api\EnsureAppEntitlement::class,
            'superAdmin' => \App\Http\Middleware\Api\EnsureSuperAdmin::class,
            'shopManager' => \App\Http\Middleware\Api\EnsureShopManager::class,
            'posPermission' => \App\Http\Middleware\Api\EnsurePosPermission::class,
            'injectPosContext' => \App\Http\Middleware\Api\InjectPosContext::class,
            'billing.mock' => \App\Http\Middleware\Api\EnsureBillingMockEnabled::class,
            'logPaymentWebhook' => \App\Http\Middleware\Api\LogPaymentWebhook::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // API errors use the same envelope as BaseApiController::fail(); never a stack trace.
        $exceptions->render(function (\Throwable $exception, \Illuminate\Http\Request $request) {
            if (!$request->is('api/*')) {
                return null;
            }

            if ($exception instanceof \Illuminate\Validation\ValidationException) {
                return response()->json([
                    'success' => false,
                    'message' => $exception->getMessage(),
                    'data' => null,
                    'error' => ['code' => 'VALIDATION_ERROR'],
                    'errors' => $exception->errors(), // read by the frontend client per field
                ], $exception->status);
            }

            [$status, $code, $message] = match (true) {
                $exception instanceof \Illuminate\Database\Eloquent\ModelNotFoundException,
                $exception instanceof \Symfony\Component\HttpKernel\Exception\NotFoundHttpException => [404, 'NOT_FOUND', 'Data tidak ditemukan'],
                $exception instanceof \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException => [405, 'METHOD_NOT_ALLOWED', 'Metode request tidak didukung'],
                $exception instanceof \Illuminate\Http\Exceptions\ThrottleRequestsException => [429, 'TOO_MANY_REQUESTS', 'Terlalu banyak permintaan. Coba lagi sebentar lagi.'],
                $exception instanceof \Illuminate\Auth\AuthenticationException => [401, 'UNAUTHORIZED', 'Silakan masuk dulu'],
                $exception instanceof \Illuminate\Auth\Access\AuthorizationException => [403, 'FORBIDDEN', 'Kamu tidak punya akses untuk aksi ini'],
                $exception instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface => [$exception->getStatusCode(), 'HTTP_' . $exception->getStatusCode(), $exception->getMessage() ?: 'Permintaan tidak bisa diproses'],
                default => [500, 'SERVER_ERROR', 'Terjadi kesalahan di server. Coba lagi, atau hubungi admin bila berulang.'],
            };

            $headers = $exception instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface ? $exception->getHeaders() : [];
            $error = ['code' => $code];
            if ($status === 500 && config('app.debug')) {
                $error['detail'] = $exception->getMessage(); // local development only
            }

            return response()->json(['success' => false, 'message' => $message, 'data' => null, 'error' => $error], $status, $headers);
        });
    })->create();
