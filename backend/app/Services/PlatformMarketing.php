<?php

namespace App\Services;

use App\Models\PlatformMarketingEvent;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class PlatformMarketing
{
    public function config(bool $secret = false): array
    {
        $config = json_decode(SystemSetting::get('platform_marketing', '{}'), true) ?: [];
        $token = (string) config('services.meta.access_token')
            ?: SystemSetting::get('platform_marketing_token', '');
        $pixelId = (string) config('services.meta.pixel_id')
            ?: (string) ($config['pixel_id'] ?? '');
        $apiVersion = (string) config('services.meta.api_version')
            ?: (string) ($config['api_version'] ?? 'v25.0');
        return [...['enabled' => true, 'meta_enabled' => false, 'pixel_id' => '', 'api_version' => 'v25.0', 'test_event_code' => ''], ...$config,
            'pixel_id' => $pixelId,
            'api_version' => $apiVersion,
            'token_configured' => $token !== '',
            ...($secret ? ['token' => $token
                ? (config('services.meta.access_token') ? $token : Crypt::decryptString($token))
                : ''] : [])];
    }

    public function save(array $data): array
    {
        if (!empty($data['clear_token'])) SystemSetting::set('platform_marketing_token', '');
        if (!empty($data['token'])) SystemSetting::set('platform_marketing_token', Crypt::encryptString($data['token']));
        unset($data['token'], $data['clear_token']);
        SystemSetting::set('platform_marketing', json_encode($data));
        return $this->config();
    }

    public function context(): array
    {
        $input = request()->input('marketing', []);
        if (!is_array($input) || ($input['consent'] ?? '') !== 'granted') return [];
        $result = ['consent' => 'granted'];
        foreach (['visitor_id', 'session_id', 'source', 'medium', 'campaign', 'content', 'fbclid', 'app_slug', 'fbp', 'fbc'] as $key) {
            if (isset($input[$key]) && is_string($input[$key])) $result[$key] = mb_substr($input[$key], 0, in_array($key, ['fbp', 'fbc', 'fbclid']) ? 255 : 120);
        }
        foreach (['visitor_id', 'session_id'] as $key) {
            if (!preg_match('/^[a-zA-Z0-9-]{8,64}$/', $result[$key] ?? '')) return [];
        }
        $path = is_string($input['path'] ?? null) ? parse_url($input['path'], PHP_URL_PATH) : '/';
        $result['path'] = $this->safePath($path ?: '/');
        $result['ip'] = request()->ip();
        $result['ua'] = mb_substr((string) request()->userAgent(), 0, 500);
        return $result;
    }

    public function safePath(string $path): string
    {
        return preg_match('#^/(?:aplikasi(?:/[a-zA-Z0-9_-]+)?|produk(?:/[a-zA-Z0-9_-]+)?|layanan|tentang|kontak|wawasan(?:/[a-zA-Z0-9_-]+)?|register|login|checkout-pos|pembayaran(?:/[a-zA-Z0-9_-]+)?)?/?$#', $path)
            ? (str_starts_with($path, '/pembayaran/') ? '/pembayaran' : mb_substr($path, 0, 255))
            : '/';
    }

    // Observers must never interrupt checkout or registration when analytics is unavailable.
    public function record(string $name, string $id, array $context, int $amount = 0, string $appSlug = '', ?User $user = null, bool $test = false): ?PlatformMarketingEvent
    {
        try {
            $config = $this->config();
            if (!$config['enabled'] || ($context['consent'] ?? '') !== 'granted') return null;
            if ($user && $user->role === 'super_admin') return null;
            if ($user) $context['em'] = hash('sha256', strtolower(trim($user->email)));
            $event = PlatformMarketingEvent::firstOrCreate(['event_id' => $id], [
                'event_name' => $name, 'visitor_id' => $context['visitor_id'] ?? null, 'session_id' => $context['session_id'] ?? null,
                'path' => $context['path'] ?? '/', 'source' => mb_substr($context['source'] ?? 'direct', 0, 120),
                'campaign' => mb_substr($context['campaign'] ?? '', 0, 120), 'app_slug' => mb_substr($appSlug ?: ($context['app_slug'] ?? ''), 0, 80),
                'device' => preg_match('/Mobile|Android|iPhone/i', $context['ua'] ?? '') ? 'mobile' : 'desktop',
                'amount' => max(0, $amount), 'context' => $context, 'is_test' => $test,
                'delivery_status' => $config['meta_enabled'] && $config['pixel_id'] && $config['token_configured'] ? 'pending' : 'disabled',
            ]);
            if ($event->wasRecentlyCreated && $event->delivery_status === 'pending' && !$test) {
                \Illuminate\Support\Facades\DB::afterCommit(fn () => \App\Jobs\SendPlatformMetaEvent::dispatchAfterResponse($event->id));
            }
            return $event;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Platform analytics could not record event', ['event' => $name, 'exception' => get_class($e)]);
            return null;
        }
    }

    public function deliver(int $id): void
    {
        $lock = Cache::lock('platform-meta-' . $id, 30);
        if (!$lock->get()) return;
        try {
            $event = PlatformMarketingEvent::findOrFail($id);
            if ($event->sent_at || $event->created_at->lt(now()->subDays(7))) return;
            $config = $this->config(true);
            if (!$config['enabled'] || !$config['meta_enabled'] || !$config['pixel_id'] || !$config['token']) return;
            if ($event->is_test && empty($config['test_event_code'])) return;
            $ctx = $event->context ?? [];
            if (($ctx['consent'] ?? '') !== 'granted') return;
            $event->increment('attempts');
            $body = ['data' => [[
                'event_name' => $event->event_name, 'event_id' => $event->event_id, 'event_time' => $event->created_at->timestamp,
                'action_source' => 'website', 'event_source_url' => \App\Support\FrontendUrl::to($event->path),
                'user_data' => array_filter(['em' => isset($ctx['em']) ? [$ctx['em']] : null,
                    'external_id' => isset($ctx['visitor_id']) ? [hash('sha256', $ctx['visitor_id'])] : null,
                    'client_ip_address' => $ctx['ip'] ?? null, 'client_user_agent' => $ctx['ua'] ?? null,
                    'fbp' => $ctx['fbp'] ?? null, 'fbc' => $ctx['fbc'] ?? null]),
                'custom_data' => ['currency' => 'IDR', 'value' => $event->amount, 'content_ids' => $event->app_slug ? [$event->app_slug] : [], 'content_type' => 'product'],
            ]]];
            if ($event->is_test) $body['test_event_code'] = $config['test_event_code'];
            $response = Http::withToken($config['token'])->timeout(8)->post('https://graph.facebook.com/' . $config['api_version'] . '/' . $config['pixel_id'] . '/events', $body);
            $accepted = $response->successful() && (int) $response->json('events_received', 0) === 1;
            $event->update(['delivery_status' => $accepted ? 'sent' : 'failed', 'sent_at' => $accepted ? now() : null,
                'delivery_message' => $accepted ? 'Diterima Meta' : 'Meta HTTP ' . $response->status() . ', kode ' . (int) $response->json('error.code', 0) . '. Periksa token, Pixel ID, dan izin dataset.']);
        } catch (\Throwable $e) {
            PlatformMarketingEvent::whereKey($id)->update(['delivery_status' => 'failed', 'delivery_message' => 'Koneksi Meta gagal. Coba ulang atau periksa konfigurasi.']);
        } finally { $lock->release(); }
    }
}
