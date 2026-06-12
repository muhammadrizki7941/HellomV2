<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class PaymentSetting extends Model
{
    protected $fillable = [
        'cash_enabled',
        'qris_static_enabled',
        'qris_dynamic_enabled',
        'gopay_enabled',
        'gopay_account_name',
        'gopay_account_number',
        'gopay_deeplink_template',
        'dana_enabled',
        'dana_account_name',
        'dana_account_number',
        'dana_deeplink_template',
        'default_method',
        'qris_static_image_path',
        'qris_static_payload',
        'dynamic_provider',
        'dynamic_sandbox',
        'auto_complete_when_paid',
        'require_paid_before_complete',
        'require_paid_before_submit',
    ];

    protected $casts = [
        'cash_enabled' => 'boolean',
        'qris_static_enabled' => 'boolean',
        'qris_dynamic_enabled' => 'boolean',
        'gopay_enabled' => 'boolean',
        'dana_enabled' => 'boolean',
        'dynamic_sandbox' => 'boolean',
        'auto_complete_when_paid' => 'boolean',
        'require_paid_before_complete' => 'boolean',
        'require_paid_before_submit' => 'boolean',
    ];

    public static function current(): ?self
    {
        if (!Schema::hasTable('payment_settings')) {
            return null;
        }

        $cacheKey = 'payment_settings.current';

        return Cache::rememberForever($cacheKey, function () {
            $row = static::query()->first();
            if ($row) {
                return $row;
            }

            return static::query()->create([
                'cash_enabled' => true,
                'qris_static_enabled' => true,
                'qris_dynamic_enabled' => false,
                'gopay_enabled' => false,
                'gopay_account_name' => null,
                'gopay_account_number' => null,
                'gopay_deeplink_template' => null,
                'dana_enabled' => false,
                'dana_account_name' => null,
                'dana_account_number' => null,
                'dana_deeplink_template' => null,
                'default_method' => 'cash',
                'qris_static_image_path' => null,
                'qris_static_payload' => null,
                'dynamic_provider' => 'midtrans',
                'dynamic_sandbox' => true,
                'auto_complete_when_paid' => true,
                'require_paid_before_complete' => true,
                'require_paid_before_submit' => false,
            ]);
        });
    }

    public static function forgetCache(): void
    {
        Cache::forget('payment_settings.current');
    }

    public function enabledMethods(): array
    {
        $methods = [];

        if ($this->cash_enabled) {
            $methods[] = 'cash';
        }
        if ($this->qris_static_enabled) {
            $methods[] = 'qris_static';
        }
        if ($this->qris_dynamic_enabled) {
            $methods[] = 'qris_dynamic';
        }
        if ($this->gopay_enabled) {
            $methods[] = 'gopay';
        }
        if ($this->dana_enabled) {
            $methods[] = 'dana';
        }

        return $methods;
    }

    public function qrisStaticImageUrl(): ?string
    {
        if (!$this->qris_static_image_path) {
            return null;
        }

        $path = trim((string) $this->qris_static_image_path);
        $publicBase = trim((string) config('filesystems.disks.public.url', '/storage'));
        if ($publicBase === '') {
            $publicBase = '/storage';
        }
        if (Str::startsWith($publicBase, ['http://', 'https://', '//'])) {
            $publicBase = rtrim($publicBase, '/');
        } else {
            $publicBase = '/' . trim($publicBase, '/');
        }

        if (Str::startsWith($path, ['http://', 'https://', '//'])) {
            $parsedPath = parse_url($path, PHP_URL_PATH);
            if (is_string($parsedPath) && Str::contains($parsedPath, '/storage/')) {
                return $publicBase . '/' . ltrim(Str::after($parsedPath, '/storage/'), '/');
            }
            if (is_string($parsedPath) && Str::contains($parsedPath, '/media/')) {
                return $publicBase . '/' . ltrim(Str::after($parsedPath, '/media/'), '/');
            }

            return $path;
        }

        $path = ltrim($path, '/');

        if (Str::startsWith($path, 'storage/')) {
            $path = Str::after($path, 'storage/');
        }
        if (Str::startsWith($path, 'media/')) {
            $path = Str::after($path, 'media/');
        }

        return $publicBase . '/' . ltrim($path, '/');
    }
}
