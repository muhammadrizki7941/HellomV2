<?php

namespace App\Http\Middleware\Api;

use App\Models\PaymentWebhookLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stores every payment webhook as received (payment_webhook_logs, append-only) before
 * the controller runs, then records the outcome. Secrets (callback tokens, signatures)
 * are masked. Controllers may add details via $request->attributes 'webhook_log'
 * (event_id, reference, signature_valid, outcome, error).
 */
class LogPaymentWebhook
{
    private const SECRET_KEYS = ['token', 'x-callback-token', 'x-ipaymu-token', 'x-doku-token', 'signature', 'authorization'];

    public function handle(Request $request, Closure $next, string $provider): Response
    {
        $log = null;
        try {
            $headers = [];
            foreach (['content-type', 'user-agent', 'x-callback-token', 'x-ipaymu-token', 'client-id', 'request-id', 'request-timestamp', 'signature', 'webhook-id'] as $name) {
                if ($request->headers->has($name)) {
                    $headers[$name] = in_array($name, self::SECRET_KEYS, true) ? '***' : (string) $request->headers->get($name);
                }
            }
            $query = $request->query();
            foreach (array_keys($query) as $key) {
                if (in_array(strtolower((string) $key), self::SECRET_KEYS, true)) {
                    $query[$key] = '***';
                }
            }
            $headers['query'] = $query;

            $log = PaymentWebhookLog::query()->create([
                'provider' => $provider,
                'payload' => mb_substr((string) $request->getContent(), 0, 60000) ?: json_encode($request->request->all()),
                'headers' => $headers,
                'ip' => (string) $request->ip(),
                'received_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e); // logging must never block a payment notification
        }

        $response = $next($request);

        if ($log) {
            $details = (array) $request->attributes->get('webhook_log', []);
            try {
                $log->forceFill([
                    'event_id' => isset($details['event_id']) ? mb_substr((string) $details['event_id'], 0, 160) : null,
                    'reference' => isset($details['reference']) ? mb_substr((string) $details['reference'], 0, 120) : null,
                    'signature_valid' => (bool) ($details['signature_valid'] ?? $response->getStatusCode() !== 401),
                    'outcome' => (string) ($details['outcome'] ?? ($response->isSuccessful() ? 'processed' : 'rejected')),
                    'error' => isset($details['error']) ? mb_substr((string) $details['error'], 0, 2000) : null,
                ])->save();
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $response;
    }
}
