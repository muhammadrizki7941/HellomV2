<?php

namespace App\Services\Security;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Cloudflare Turnstile on Hellom Page checkout (LB-25), only for repeated checkouts: after
 * THRESHOLD orders from one IP within WINDOW seconds the next checkout needs a token.
 * Off when TURNSTILE_SITE_KEY / TURNSTILE_SECRET_KEY are empty. Rate limits stay the
 * first line of defence; if Cloudflare cannot be reached the check is skipped (logged).
 */
class CheckoutCaptcha
{
    public const THRESHOLD = 3;

    public const WINDOW = 1800;

    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function enabled(): bool
    {
        return $this->siteKey() !== '' && (string) config('services.turnstile.secret_key') !== '';
    }

    public function siteKey(): string
    {
        return (string) config('services.turnstile.site_key');
    }

    public function required(Request $request): bool
    {
        return $this->enabled() && RateLimiter::attempts($this->key($request)) >= self::THRESHOLD;
    }

    /** Call after an order was created. */
    public function recordCheckout(Request $request): void
    {
        if ($this->enabled()) {
            RateLimiter::hit($this->key($request), self::WINDOW);
        }
    }

    public function verify(?string $token, Request $request): bool
    {
        if (!is_string($token) || $token === '' || strlen($token) > 2048) {
            return false;
        }
        try {
            $response = Http::asForm()->timeout(8)->post(self::VERIFY_URL, [
                'secret' => (string) config('services.turnstile.secret_key'),
                'response' => $token,
                'remoteip' => $request->ip(),
            ]);
        } catch (Throwable $e) {
            Log::warning('Turnstile unreachable; checkout allowed', ['error' => $e->getMessage()]);

            return true;
        }

        return $response->json('success') === true;
    }

    private function key(Request $request): string
    {
        return 'checkout-captcha:' . $request->ip();
    }
}
