<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class XenditBalanceSnapshot extends Model
{
    use HasFactory;

    protected $fillable = [
        'available_balance',
        'pending_balance',
        'currency',
        'raw_response',
        'captured_at',
    ];

    protected function casts(): array
    {
        return [
            'raw_response' => 'array',
            'captured_at' => 'datetime',
        ];
    }

    public static function captureCurrentBalance(): ?self
    {
        try {
            $xenditService = app(\App\Services\Hellom\XenditService::class);
            $balance = $xenditService->getBalance();
            $availableBalance = (int) ($balance['available'] ?? $balance['balance'] ?? 0);
            $pendingBalance = (int) ($balance['pending'] ?? 0);

            return self::create([
                'available_balance' => $availableBalance,
                'pending_balance' => $pendingBalance,
                'currency' => $balance['currency'] ?? 'IDR',
                'raw_response' => $balance,
                'captured_at' => now(),
            ]);
        } catch (\Exception $e) {
            \Log::error('Failed to capture Xendit balance', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    public static function getLatestBalance(): array
    {
        $latest = self::captureCurrentBalance() ?: self::latest('captured_at')->first();

        return $latest ? [
            'available_balance' => $latest->available_balance,
            'pending_balance' => $latest->pending_balance,
            'currency' => $latest->currency,
            'captured_at' => $latest->captured_at,
        ] : [
            'available_balance' => 0,
            'pending_balance' => 0,
            'currency' => 'IDR',
            'captured_at' => null,
        ];
    }
}
