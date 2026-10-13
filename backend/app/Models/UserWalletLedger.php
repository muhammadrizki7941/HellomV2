<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserWalletLedger extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'type',
        'reference_type',
        'reference_id',
        'currency',
        'amount',
        'balance_before',
        'balance_after',
        'description',
        'metadata',
        'effective_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'effective_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Helper methods for different transaction types
    public static function recordDeposit(int $userId, int $amount, ?string $referenceType = null, ?int $referenceId = null, ?string $description = null): self
    {
        $currentBalance = self::getUserBalance($userId);
        $newBalance = $currentBalance + $amount;

        // A paid top-up from a payment gateway webhook: tell the user and the super admins.
        if ($referenceType !== null && str_ends_with($referenceType, '_webhook') && $amount > 0) {
            $notifier = app(\App\Services\Notifications\Notifier::class);
            $rupiah = \App\Services\Notifications\Notifier::rupiah($amount);
            $notifier->user($userId, 'transaction', 'Top up saldo berhasil · ' . $rupiah, 'Saldo wallet kamu bertambah ' . $rupiah . '.', '/dashboard/billing');
            $notifier->admin('wallet_topup', 'Top up wallet · ' . $rupiah,
                (User::query()->whereKey($userId)->value('email') ?? 'User #' . $userId) . ' top up ' . $rupiah . ' lewat ' . str_replace('_webhook', '', $referenceType) . '.',
                ['action_url' => '/admin/finance', 'data' => ['user_id' => $userId, 'amount' => $amount], 'email' => false]);
        }

        return self::create([
            'user_id' => $userId,
            'type' => 'deposit',
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'currency' => 'IDR',
            'amount' => $amount,
            'balance_before' => $currentBalance,
            'balance_after' => $newBalance,
            'description' => $description,
            'effective_at' => now(),
        ]);
    }

    public static function recordWithdrawal(int $userId, int $amount, ?string $referenceType = null, ?int $referenceId = null, ?string $description = null): self
    {
        $currentBalance = self::getUserBalance($userId);
        $newBalance = $currentBalance - $amount;

        return self::create([
            'user_id' => $userId,
            'type' => 'withdrawal',
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'currency' => 'IDR',
            'amount' => -$amount, // negative for withdrawal
            'balance_before' => $currentBalance,
            'balance_after' => $newBalance,
            'description' => $description,
            'effective_at' => now(),
        ]);
    }

    public static function recordPayment(int $userId, int $amount, ?string $referenceType = null, ?int $referenceId = null, ?string $description = null): self
    {
        $currentBalance = self::getUserBalance($userId);
        $newBalance = $currentBalance - $amount;

        return self::create([
            'user_id' => $userId,
            'type' => 'payment',
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'currency' => 'IDR',
            'amount' => -$amount, // negative for payment
            'balance_before' => $currentBalance,
            'balance_after' => $newBalance,
            'description' => $description,
            'effective_at' => now(),
        ]);
    }

    public static function getUserBalance(int $userId): int
    {
        return (int) self::query()
            ->where('user_id', $userId)
            ->latest('effective_at')
            ->value('balance_after') ?? 0;
    }

    public static function getTotalUserDeposits(): int
    {
        return (int) self::query()
            ->where('type', 'deposit')
            ->sum('amount');
    }

    public static function getUserDepositSummary(int $userId, int $days = 30): array
    {
        $startAt = now()->subDays($days);

        $deposits = self::query()
            ->where('user_id', $userId)
            ->where('type', 'deposit')
            ->where('effective_at', '>=', $startAt)
            ->get();

        return [
            'total_deposits' => $deposits->sum('amount'),
            'deposit_count' => $deposits->count(),
        ];
    }
}
