<?php

namespace App\Http\Controllers\Api\V1\Hellom;

use App\Models\Organization;
use App\Models\OrganizationPayoutProfile;
use App\Models\OrganizationWallet;
use App\Models\OrganizationWalletTransaction;
use App\Models\User;
use App\Models\WalletWithdrawalRequest;
use App\Services\Hellom\XenditService;
use App\Services\Hellom\XenditSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WalletController extends BaseApiController
{
    public function payoutPolicy(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        [$organization, $requesterRole, $error] = $this->resolveCurrentOrganizationContext($user);
        if ($error) {
            return $error;
        }

        $validated = $request->validate([
            'channel' => ['nullable', 'string', 'max:50'],
            'amount' => ['nullable', 'integer', 'min:1'],
        ]);

        $defaultPolicy = (array) config('payments.providers.xendit.policy.default', []);
        $channelPolicies = (array) config('payments.providers.xendit.policy.channels', []);

        $channel = $this->normalizePaymentChannel(isset($validated['channel']) ? (string) $validated['channel'] : null);
        $selectedPolicy = $this->resolvePaymentChannelPolicy($channel);

        $amount = isset($validated['amount']) ? (int) $validated['amount'] : null;
        $estimatedFee = null;
        $estimatedNet = null;

        if ($amount !== null) {
            $fixed = max(0, (int) ($selectedPolicy['fee_fixed'] ?? 0));
            $bps = max(0, (int) ($selectedPolicy['fee_bps'] ?? 0));
            $estimatedFee = $fixed + (int) floor(($amount * $bps) / 10000);
            $estimatedNet = max(0, $amount - $estimatedFee);
        }

        return $this->ok([
            'organization' => [
                'id' => (int) $organization->id,
                'name' => (string) $organization->name,
                'slug' => (string) $organization->slug,
            ],
            'requester_role' => $requesterRole,
            'query' => [
                'channel' => $channel,
                'amount' => $amount,
            ],
            'policy' => [
                'default' => $defaultPolicy,
                'channels' => $channelPolicies,
                'selected' => $selectedPolicy,
            ],
            'estimation' => [
                'fee_amount' => $estimatedFee,
                'net_amount' => $estimatedNet,
            ],
        ], 'Wallet payout policy');
    }

    public function overview(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        [$organization, $requesterRole, $error] = $this->resolveCurrentOrganizationContext($user);
        if ($error) {
            return $error;
        }

        $wallet = $this->ensureWallet((int) $organization->id);

        $recentTransactions = OrganizationWalletTransaction::query()
            ->where('organization_id', (int) $organization->id)
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        $pendingWithdrawals = WalletWithdrawalRequest::query()
            ->where('organization_id', (int) $organization->id)
            ->whereIn('status', ['pending', 'processing'])
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        return $this->ok([
            'organization' => [
                'id' => (int) $organization->id,
                'name' => (string) $organization->name,
                'slug' => (string) $organization->slug,
            ],
            'requester_role' => $requesterRole,
            'wallet' => $this->walletPayload($wallet),
            'recent_transactions' => $recentTransactions->map(fn(OrganizationWalletTransaction $transaction): array => $this->transactionPayload($transaction))->values(),
            'pending_withdrawals' => $pendingWithdrawals->map(fn(WalletWithdrawalRequest $withdrawal): array => $this->withdrawalPayload($withdrawal))->values(),
        ], 'Wallet overview');
    }

    public function transactions(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        [$organization, $requesterRole, $error] = $this->resolveCurrentOrganizationContext($user);
        if ($error) {
            return $error;
        }

        $validated = $request->validate([
            'type' => ['nullable', 'string', 'max:30'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'cursor' => ['nullable', 'integer', 'min:1'],
        ]);

        $limit = (int) ($validated['limit'] ?? 20);
        $cursor = isset($validated['cursor']) ? (int) $validated['cursor'] : null;

        $query = OrganizationWalletTransaction::query()
            ->where('organization_id', (int) $organization->id);

        if (!empty($validated['type'])) {
            $query->where('type', (string) $validated['type']);
        }

        if ($cursor !== null) {
            $query->where('id', '<', $cursor);
        }

        $rows = $query
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get();

        $hasMore = $rows->count() > $limit;
        $items = $hasMore ? $rows->take($limit) : $rows;
        $nextCursor = $hasMore ? (int) ($items->last()?->id ?? 0) : null;

        return $this->ok([
            'organization_id' => (int) $organization->id,
            'requester_role' => $requesterRole,
            'filters' => [
                'type' => $validated['type'] ?? null,
                'limit' => $limit,
                'cursor' => $cursor,
            ],
            'pagination' => [
                'has_more' => $hasMore,
                'next_cursor' => $nextCursor,
            ],
            'items' => $items->map(fn(OrganizationWalletTransaction $transaction): array => $this->transactionPayload($transaction))->values(),
        ], 'Wallet transactions');
    }

    public function requestWithdrawal(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        [$organization, $requesterRole, $error] = $this->resolveCurrentOrganizationContext($user);
        if ($error) {
            return $error;
        }

        if (!in_array($requesterRole, ['owner', 'admin', 'super_admin'], true)) {
            return $this->fail('Only owner/admin/super admin can request withdrawal', ['code' => 'INSUFFICIENT_ROLE'], 403);
        }

        // KYC gate: KTP + bank account must be verified by Hellom before any withdrawal.
        $profile = OrganizationPayoutProfile::query()
            ->where('organization_id', (int) $organization->id)
            ->first();

        if (!$profile instanceof OrganizationPayoutProfile || !$profile->isVerified()) {
            return $this->fail('Verifikasi KTP & rekening belum disetujui. Lengkapi & tunggu verifikasi sebelum menarik saldo.', [
                'code' => 'KYC_NOT_VERIFIED',
                'kyc_status' => $profile instanceof OrganizationPayoutProfile ? (string) $profile->status : OrganizationPayoutProfile::STATUS_UNVERIFIED,
            ], 422);
        }

        $minWithdrawal = (int) config('payments.wallet.min_withdrawal', 100000);

        $validated = $request->validate([
            'amount' => ['required', 'integer', 'min:' . $minWithdrawal],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $feeFlat = (int) config('payments.providers.xendit.withdrawal_fee_flat', 5000);

        $result = DB::transaction(function () use ($organization, $user, $validated, $feeFlat, $profile) {
            $wallet = OrganizationWallet::query()
                ->where('organization_id', (int) $organization->id)
                ->lockForUpdate()
                ->first();

            if (!$wallet instanceof OrganizationWallet) {
                $wallet = OrganizationWallet::query()->create([
                    'organization_id' => (int) $organization->id,
                    'currency' => 'IDR',
                    'available_balance' => 0,
                    'pending_balance' => 0,
                    'total_in' => 0,
                    'total_out' => 0,
                    'status' => 'active',
                ]);
            }

            $amount = (int) $validated['amount'];
            $fee = max(0, $feeFlat);
            $net = max(0, $amount - $fee);

            if ($wallet->available_balance < $amount) {
                return ['error' => $this->fail('Insufficient available balance', [
                    'code' => 'INSUFFICIENT_BALANCE',
                    'available_balance' => (int) $wallet->available_balance,
                    'requested_amount' => $amount,
                ], 422)];
            }

            $wallet->forceFill([
                'available_balance' => (int) $wallet->available_balance - $amount,
                'pending_balance' => (int) $wallet->pending_balance + $amount,
            ])->save();

            $externalRef = 'wd_' . Str::upper(Str::random(16));

            $withdrawal = WalletWithdrawalRequest::query()->create([
                'organization_id' => (int) $organization->id,
                'wallet_id' => (int) $wallet->id,
                'requested_by_user_id' => (int) $user->id,
                'status' => 'pending',
                'amount' => $amount,
                'fee_amount' => $fee,
                'net_amount' => $net,
                'bank_code' => (string) $profile->bank_code,
                'account_number' => (string) $profile->account_number,
                'account_name' => (string) $profile->account_name,
                'provider' => 'xendit',
                'external_ref' => $externalRef,
                'notes' => isset($validated['notes']) ? (string) $validated['notes'] : null,
                'metadata' => [
                    'source' => 'api_wallet_withdrawal_request',
                ],
            ]);

            OrganizationWalletTransaction::query()->create([
                'organization_id' => (int) $organization->id,
                'wallet_id' => (int) $wallet->id,
                'user_id' => (int) $user->id,
                'type' => 'withdrawal_hold',
                'direction' => 'debit',
                'amount' => $amount,
                'balance_after' => (int) $wallet->available_balance,
                'reference_type' => 'wallet_withdrawal_requests',
                'reference_id' => (string) $withdrawal->id,
                'external_ref' => $externalRef,
                'description' => 'Withdrawal request hold balance',
                'metadata' => [
                    'fee_amount' => $fee,
                    'net_amount' => $net,
                ],
            ]);

            return [
                'wallet' => $wallet->fresh(),
                'withdrawal' => $withdrawal,
            ];
        });

        if (isset($result['error']) && $result['error'] instanceof JsonResponse) {
            return $result['error'];
        }

        return $this->ok([
            'wallet' => $this->walletPayload($result['wallet']),
            'withdrawal' => $this->withdrawalPayload($result['withdrawal']),
            'next_step' => [
                'integration' => 'xendit_disbursement',
                'external_ref' => (string) $result['withdrawal']->external_ref,
            ],
        ], 'Withdrawal requested', 201);
    }

    public function withdrawals(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        [$organization, $requesterRole, $error] = $this->resolveCurrentOrganizationContext($user);
        if ($error) {
            return $error;
        }

        $validated = $request->validate([
            'status' => ['nullable', 'in:pending,processing,paid,rejected,cancelled,failed'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'cursor' => ['nullable', 'integer', 'min:1'],
        ]);

        $limit = (int) ($validated['limit'] ?? 20);
        $cursor = isset($validated['cursor']) ? (int) $validated['cursor'] : null;

        $query = WalletWithdrawalRequest::query()->where('organization_id', (int) $organization->id);

        if (!empty($validated['status'])) {
            $query->where('status', (string) $validated['status']);
        }

        if ($cursor !== null) {
            $query->where('id', '<', $cursor);
        }

        $rows = $query->orderByDesc('id')->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $items = $hasMore ? $rows->take($limit) : $rows;
        $nextCursor = $hasMore ? (int) ($items->last()?->id ?? 0) : null;

        return $this->ok([
            'organization_id' => (int) $organization->id,
            'requester_role' => $requesterRole,
            'filters' => [
                'status' => $validated['status'] ?? null,
                'limit' => $limit,
                'cursor' => $cursor,
            ],
            'pagination' => [
                'has_more' => $hasMore,
                'next_cursor' => $nextCursor,
            ],
            'items' => $items->map(fn(WalletWithdrawalRequest $withdrawal): array => $this->withdrawalPayload($withdrawal))->values(),
        ], 'Wallet withdrawal requests');
    }

    public function payoutHistory(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        [$organization, $requesterRole, $error] = $this->resolveCurrentOrganizationContext($user);
        if ($error) {
            return $error;
        }

        $validated = $request->validate([
            'status' => ['nullable', 'in:pending,processing,paid,rejected,cancelled,failed'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'cursor' => ['nullable', 'integer', 'min:1'],
        ]);

        $limit = (int) ($validated['limit'] ?? 20);
        $cursor = isset($validated['cursor']) ? (int) $validated['cursor'] : null;

        $query = WalletWithdrawalRequest::query()->where('organization_id', (int) $organization->id);

        if (!empty($validated['status'])) {
            $query->where('status', (string) $validated['status']);
        }

        if ($cursor !== null) {
            $query->where('id', '<', $cursor);
        }

        $rows = $query->orderByDesc('id')->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $items = $hasMore ? $rows->take($limit) : $rows;
        $nextCursor = $hasMore ? (int) ($items->last()?->id ?? 0) : null;

        $statsBase = WalletWithdrawalRequest::query()->where('organization_id', (int) $organization->id);

        return $this->ok([
            'organization_id' => (int) $organization->id,
            'requester_role' => $requesterRole,
            'filters' => [
                'status' => $validated['status'] ?? null,
                'limit' => $limit,
                'cursor' => $cursor,
            ],
            'pagination' => [
                'has_more' => $hasMore,
                'next_cursor' => $nextCursor,
            ],
            'summary' => [
                'total_requests' => (int) (clone $statsBase)->count(),
                'pending_or_processing' => (int) (clone $statsBase)->whereIn('status', ['pending', 'processing'])->count(),
                'paid_count' => (int) (clone $statsBase)->where('status', 'paid')->count(),
                'rejected_or_cancelled' => (int) (clone $statsBase)->whereIn('status', ['rejected', 'cancelled'])->count(),
            ],
            'items' => $items->map(fn(WalletWithdrawalRequest $withdrawal): array => $this->withdrawalPayload($withdrawal))->values(),
        ], 'Wallet payout history');
    }

    public function adminPayoutQueue(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        [$organization, $requesterRole, $error] = $this->resolveCurrentOrganizationContext($user);
        if ($error) {
            return $error;
        }

        if (!in_array($requesterRole, ['owner', 'admin', 'super_admin'], true)) {
            return $this->fail('Only owner/admin/super admin can access payout queue', ['code' => 'INSUFFICIENT_ROLE'], 403);
        }

        $validated = $request->validate([
            'status' => ['nullable', 'in:pending,processing,paid,rejected,cancelled,failed'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'cursor' => ['nullable', 'integer', 'min:1'],
        ]);

        $limit = (int) ($validated['limit'] ?? 20);
        $cursor = isset($validated['cursor']) ? (int) $validated['cursor'] : null;

        $query = WalletWithdrawalRequest::query()->where('organization_id', (int) $organization->id);

        if (!empty($validated['status'])) {
            $query->where('status', (string) $validated['status']);
        } else {
            $query->whereIn('status', ['pending', 'processing', 'failed']);
        }

        if ($cursor !== null) {
            $query->where('id', '<', $cursor);
        }

        $rows = $query->orderByDesc('id')->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $items = $hasMore ? $rows->take($limit) : $rows;
        $nextCursor = $hasMore ? (int) ($items->last()?->id ?? 0) : null;

        $summaryBase = WalletWithdrawalRequest::query()->where('organization_id', (int) $organization->id);

        return $this->ok([
            'organization' => [
                'id' => (int) $organization->id,
                'name' => (string) $organization->name,
                'slug' => (string) $organization->slug,
            ],
            'requester_role' => $requesterRole,
            'filters' => [
                'status' => $validated['status'] ?? null,
                'limit' => $limit,
                'cursor' => $cursor,
            ],
            'pagination' => [
                'has_more' => $hasMore,
                'next_cursor' => $nextCursor,
            ],
            'summary' => [
                'pending_count' => (int) (clone $summaryBase)->where('status', 'pending')->count(),
                'processing_count' => (int) (clone $summaryBase)->where('status', 'processing')->count(),
                'failed_count' => (int) (clone $summaryBase)->where('status', 'failed')->count(),
                'paid_count' => (int) (clone $summaryBase)->where('status', 'paid')->count(),
            ],
            'items' => $items->map(function (WalletWithdrawalRequest $withdrawal): array {
                $status = (string) $withdrawal->status;

                return array_merge($this->withdrawalPayload($withdrawal), [
                    'actions' => [
                        'can_approve' => $status === 'pending',
                        'can_reject' => in_array($status, ['pending', 'processing'], true),
                        'can_mark_paid' => in_array($status, ['pending', 'processing'], true),
                        'can_mark_failed' => in_array($status, ['pending', 'processing'], true),
                        'can_cancel' => $status === 'pending',
                    ],
                ]);
            })->values(),
        ], 'Wallet admin payout queue');
    }

    public function cancelWithdrawal(Request $request, int $withdrawalId): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        [$organization, $requesterRole, $error] = $this->resolveCurrentOrganizationContext($user);
        if ($error) {
            return $error;
        }

        if (!in_array($requesterRole, ['owner', 'admin', 'super_admin'], true)) {
            return $this->fail('Only owner/admin/super admin can cancel withdrawal', ['code' => 'INSUFFICIENT_ROLE'], 403);
        }

        $result = DB::transaction(function () use ($organization, $user, $withdrawalId) {
            $withdrawal = WalletWithdrawalRequest::query()
                ->where('organization_id', (int) $organization->id)
                ->where('id', $withdrawalId)
                ->lockForUpdate()
                ->first();

            if (!$withdrawal instanceof WalletWithdrawalRequest) {
                return ['error' => $this->fail('Withdrawal request not found', ['code' => 'WITHDRAWAL_NOT_FOUND'], 404)];
            }

            if ((string) $withdrawal->status !== 'pending') {
                return ['error' => $this->fail('Only pending withdrawal can be cancelled', ['code' => 'WITHDRAWAL_NOT_PENDING'], 422)];
            }

            $wallet = OrganizationWallet::query()
                ->where('id', (int) $withdrawal->wallet_id)
                ->lockForUpdate()
                ->first();

            if (!$wallet instanceof OrganizationWallet) {
                return ['error' => $this->fail('Wallet not found', ['code' => 'WALLET_NOT_FOUND'], 404)];
            }

            $amount = (int) $withdrawal->amount;

            $wallet->forceFill([
                'available_balance' => (int) $wallet->available_balance + $amount,
                'pending_balance' => max(0, (int) $wallet->pending_balance - $amount),
            ])->save();

            $withdrawal->forceFill([
                'status' => 'cancelled',
                'reviewed_by_user_id' => (int) $user->id,
                'processed_at' => now(),
            ])->save();

            OrganizationWalletTransaction::query()->create([
                'organization_id' => (int) $organization->id,
                'wallet_id' => (int) $wallet->id,
                'user_id' => (int) $user->id,
                'type' => 'withdrawal_release',
                'direction' => 'credit',
                'amount' => $amount,
                'balance_after' => (int) $wallet->available_balance,
                'reference_type' => 'wallet_withdrawal_requests',
                'reference_id' => (string) $withdrawal->id,
                'external_ref' => (string) ($withdrawal->external_ref ?? ''),
                'description' => 'Withdrawal cancelled and balance released',
            ]);

            return [
                'wallet' => $wallet->fresh(),
                'withdrawal' => $withdrawal->fresh(),
            ];
        });

        if (isset($result['error']) && $result['error'] instanceof JsonResponse) {
            return $result['error'];
        }

        return $this->ok([
            'wallet' => $this->walletPayload($result['wallet']),
            'withdrawal' => $this->withdrawalPayload($result['withdrawal']),
        ], 'Withdrawal cancelled');
    }

    public function approveWithdrawal(Request $request, int $withdrawalId): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        [$organization, $requesterRole, $error] = $this->resolveCurrentOrganizationContext($user);
        if ($error) {
            return $error;
        }

        if (!in_array($requesterRole, ['owner', 'admin', 'super_admin'], true)) {
            return $this->fail('Only owner/admin/super admin can approve withdrawal', ['code' => 'INSUFFICIENT_ROLE'], 403);
        }

        $withdrawal = WalletWithdrawalRequest::query()
            ->where('organization_id', (int) $organization->id)
            ->where('id', $withdrawalId)
            ->first();

        if (!$withdrawal instanceof WalletWithdrawalRequest) {
            return $this->fail('Withdrawal request not found', ['code' => 'WITHDRAWAL_NOT_FOUND'], 404);
        }

        if ((string) $withdrawal->status !== 'pending') {
            return $this->fail('Only pending withdrawal can be approved', ['code' => 'WITHDRAWAL_NOT_PENDING'], 422);
        }

        $providerResponse = null;
        $payoutCreated = false;

        if (app(XenditSettingsService::class)->isReady()) {
            try {
                $providerResponse = app(XenditService::class)->createPayout([
                    'reference_id' => (string) ($withdrawal->external_ref ?? ''),
                    'channel_code' => $this->resolvePayoutChannelCode((string) $withdrawal->bank_code),
                    'channel_properties' => [
                        'account_number' => (string) $withdrawal->account_number,
                        'account_holder_name' => (string) $withdrawal->account_name,
                    ],
                    'amount' => (int) $withdrawal->net_amount,
                    'description' => 'Hellom withdrawal payout',
                    'currency' => 'IDR',
                    'metadata' => [
                        'organization_id' => (int) $organization->id,
                        'withdrawal_id' => (int) $withdrawal->id,
                    ],
                ], (string) ($withdrawal->external_ref ?? ''));

                $meta = is_array($withdrawal->metadata) ? $withdrawal->metadata : [];
                $meta['xendit_payout'] = $providerResponse;

                $withdrawal->forceFill([
                    'status' => 'processing',
                    'reviewed_by_user_id' => (int) $user->id,
                    'provider_ref' => (string) (data_get($providerResponse, 'id') ?? data_get($providerResponse, 'payout_id') ?? ''),
                    'metadata' => $meta,
                ])->save();

                $payoutCreated = true;
            } catch (\Throwable $exception) {
                return $this->fail($exception->getMessage(), [
                    'code' => 'XENDIT_PAYOUT_CREATE_FAILED',
                    'withdrawal_id' => (int) $withdrawal->id,
                ], 422);
            }
        } else {
            // No Xendit integration - mark as processing anyway for manual processing
            $withdrawal->forceFill([
                'status' => 'processing',
                'reviewed_by_user_id' => (int) $user->id,
            ])->save();

            $payoutCreated = true;
        }

        return $this->ok([
            'withdrawal' => $this->withdrawalPayload($withdrawal->fresh()),
            'next_step' => [
                'integration' => 'xendit_disbursement',
                'provider' => 'xendit',
                'external_ref' => (string) ($withdrawal->external_ref ?? ''),
                'provider_ref' => (string) ($withdrawal->provider_ref ?? ''),
                'amount' => (int) $withdrawal->net_amount,
                'bank_code' => (string) ($withdrawal->bank_code ?? ''),
                'account_number' => (string) ($withdrawal->account_number ?? ''),
                'account_name' => (string) ($withdrawal->account_name ?? ''),
                'provider_requested' => $providerResponse !== null,
            ],
        ], 'Withdrawal approved and moved to processing');
    }

    public function markWithdrawalPaid(Request $request, int $withdrawalId): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        [$organization, $requesterRole, $error] = $this->resolveCurrentOrganizationContext($user);
        if ($error) {
            return $error;
        }

        if (!in_array($requesterRole, ['owner', 'admin', 'super_admin'], true)) {
            return $this->fail('Only owner/admin/super admin can mark withdrawal as paid', ['code' => 'INSUFFICIENT_ROLE'], 403);
        }

        $validated = $request->validate([
            'provider_ref' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $result = DB::transaction(function () use ($organization, $user, $withdrawalId, $validated) {
            $withdrawal = WalletWithdrawalRequest::query()
                ->where('organization_id', (int) $organization->id)
                ->where('id', $withdrawalId)
                ->lockForUpdate()
                ->first();

            if (!$withdrawal instanceof WalletWithdrawalRequest) {
                return ['error' => $this->fail('Withdrawal request not found', ['code' => 'WITHDRAWAL_NOT_FOUND'], 404)];
            }

            if (!in_array((string) $withdrawal->status, ['pending', 'processing'], true)) {
                return ['error' => $this->fail('Only pending/processing withdrawal can be marked as paid', ['code' => 'WITHDRAWAL_NOT_PAYABLE'], 422)];
            }

            $wallet = OrganizationWallet::query()
                ->where('id', (int) $withdrawal->wallet_id)
                ->lockForUpdate()
                ->first();

            if (!$wallet instanceof OrganizationWallet) {
                return ['error' => $this->fail('Wallet not found', ['code' => 'WALLET_NOT_FOUND'], 404)];
            }

            $amount = (int) $withdrawal->amount;
            $wallet->forceFill([
                'pending_balance' => max(0, (int) $wallet->pending_balance - $amount),
                'total_out' => (int) $wallet->total_out + $amount,
            ])->save();

            $withdrawal->forceFill([
                'status' => 'paid',
                'reviewed_by_user_id' => (int) $user->id,
                'provider_ref' => isset($validated['provider_ref']) ? (string) $validated['provider_ref'] : $withdrawal->provider_ref,
                'processed_at' => now(),
                'notes' => isset($validated['notes']) ? (string) $validated['notes'] : $withdrawal->notes,
            ])->save();

            OrganizationWalletTransaction::query()->create([
                'organization_id' => (int) $organization->id,
                'wallet_id' => (int) $wallet->id,
                'user_id' => (int) $user->id,
                'type' => 'withdrawal_paid_manual',
                'direction' => 'debit',
                'amount' => $amount,
                'balance_after' => (int) $wallet->available_balance,
                'reference_type' => 'wallet_withdrawal_requests',
                'reference_id' => (string) $withdrawal->id,
                'external_ref' => (string) ($withdrawal->external_ref ?? ''),
                'description' => 'Withdrawal marked as paid manually',
                'metadata' => [
                    'provider_ref' => isset($validated['provider_ref']) ? (string) $validated['provider_ref'] : null,
                    'notes' => isset($validated['notes']) ? (string) $validated['notes'] : null,
                ],
            ]);

            return [
                'wallet' => $wallet->fresh(),
                'withdrawal' => $withdrawal->fresh(),
            ];
        });

        if (isset($result['error']) && $result['error'] instanceof JsonResponse) {
            return $result['error'];
        }

        return $this->ok([
            'wallet' => $this->walletPayload($result['wallet']),
            'withdrawal' => $this->withdrawalPayload($result['withdrawal']),
        ], 'Withdrawal marked as paid');
    }

    public function markWithdrawalFailed(Request $request, int $withdrawalId): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        [$organization, $requesterRole, $error] = $this->resolveCurrentOrganizationContext($user);
        if ($error) {
            return $error;
        }

        if (!in_array($requesterRole, ['owner', 'admin', 'super_admin'], true)) {
            return $this->fail('Only owner/admin/super admin can mark withdrawal as failed', ['code' => 'INSUFFICIENT_ROLE'], 403);
        }

        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $result = DB::transaction(function () use ($organization, $user, $withdrawalId, $validated) {
            $withdrawal = WalletWithdrawalRequest::query()
                ->where('organization_id', (int) $organization->id)
                ->where('id', $withdrawalId)
                ->lockForUpdate()
                ->first();

            if (!$withdrawal instanceof WalletWithdrawalRequest) {
                return ['error' => $this->fail('Withdrawal request not found', ['code' => 'WITHDRAWAL_NOT_FOUND'], 404)];
            }

            if (!in_array((string) $withdrawal->status, ['pending', 'processing'], true)) {
                return ['error' => $this->fail('Only pending/processing withdrawal can be marked as failed', ['code' => 'WITHDRAWAL_NOT_FAILABLE'], 422)];
            }

            $wallet = OrganizationWallet::query()
                ->where('id', (int) $withdrawal->wallet_id)
                ->lockForUpdate()
                ->first();

            if (!$wallet instanceof OrganizationWallet) {
                return ['error' => $this->fail('Wallet not found', ['code' => 'WALLET_NOT_FOUND'], 404)];
            }

            $amount = (int) $withdrawal->amount;
            $wallet->forceFill([
                'available_balance' => (int) $wallet->available_balance + $amount,
                'pending_balance' => max(0, (int) $wallet->pending_balance - $amount),
            ])->save();

            $withdrawal->forceFill([
                'status' => 'failed',
                'reviewed_by_user_id' => (int) $user->id,
                'processed_at' => now(),
                'notes' => isset($validated['notes']) ? (string) $validated['notes'] : $withdrawal->notes,
            ])->save();

            OrganizationWalletTransaction::query()->create([
                'organization_id' => (int) $organization->id,
                'wallet_id' => (int) $wallet->id,
                'user_id' => (int) $user->id,
                'type' => 'withdrawal_failed_release',
                'direction' => 'credit',
                'amount' => $amount,
                'balance_after' => (int) $wallet->available_balance,
                'reference_type' => 'wallet_withdrawal_requests',
                'reference_id' => (string) $withdrawal->id,
                'external_ref' => (string) ($withdrawal->external_ref ?? ''),
                'description' => 'Withdrawal marked failed and balance released',
                'metadata' => [
                    'notes' => isset($validated['notes']) ? (string) $validated['notes'] : null,
                ],
            ]);

            return [
                'wallet' => $wallet->fresh(),
                'withdrawal' => $withdrawal->fresh(),
            ];
        });

        if (isset($result['error']) && $result['error'] instanceof JsonResponse) {
            return $result['error'];
        }

        return $this->ok([
            'wallet' => $this->walletPayload($result['wallet']),
            'withdrawal' => $this->withdrawalPayload($result['withdrawal']),
        ], 'Withdrawal marked as failed');
    }

    public function financeSummary(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        [$organization, $requesterRole, $error] = $this->resolveCurrentOrganizationContext($user);
        if ($error) {
            return $error;
        }

        $wallet = $this->ensureWallet((int) $organization->id);

        $validated = $request->validate([
            'days' => ['nullable', 'integer', 'min:1', 'max:90'],
        ]);

        $days = (int) ($validated['days'] ?? 30);
        $startAt = now()->subDays($days);

        $transactions = OrganizationWalletTransaction::query()
            ->where('organization_id', (int) $organization->id)
            ->where('created_at', '>=', $startAt)
            ->get();

        $periodIn = (int) $transactions
            ->where('direction', 'credit')
            ->sum('amount');

        $periodOut = (int) $transactions
            ->where('direction', 'debit')
            ->sum('amount');

        $withdrawalBase = WalletWithdrawalRequest::query()->where('organization_id', (int) $organization->id);

        return $this->ok([
            'organization' => [
                'id' => (int) $organization->id,
                'name' => (string) $organization->name,
                'slug' => (string) $organization->slug,
            ],
            'requester_role' => $requesterRole,
            'range' => [
                'days' => $days,
                'start_at' => $startAt,
                'end_at' => now(),
            ],
            'wallet' => $this->walletPayload($wallet),
            'period' => [
                'inflow' => $periodIn,
                'outflow' => $periodOut,
                'net' => $periodIn - $periodOut,
                'transaction_count' => (int) $transactions->count(),
            ],
            'withdrawals' => [
                'pending_count' => (int) (clone $withdrawalBase)->where('status', 'pending')->count(),
                'processing_count' => (int) (clone $withdrawalBase)->where('status', 'processing')->count(),
                'paid_count' => (int) (clone $withdrawalBase)->where('status', 'paid')->count(),
                'failed_count' => (int) (clone $withdrawalBase)->where('status', 'failed')->count(),
                'rejected_count' => (int) (clone $withdrawalBase)->where('status', 'rejected')->count(),
                'cancelled_count' => (int) (clone $withdrawalBase)->where('status', 'cancelled')->count(),
            ],
        ], 'Wallet finance summary');
    }

    public function platformFinanceSummary(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        // Only super_admin can access platform-wide finance
        if ((string) ($user->role ?? '') !== 'super_admin') {
            return $this->fail('Only super admin can access platform finance', ['code' => 'INSUFFICIENT_ROLE'], 403);
        }

        $validated = $request->validate([
            'days' => ['nullable', 'integer', 'min:1', 'max:90'],
        ]);

        $days = (int) ($validated['days'] ?? 30);
        $startAt = now()->subDays($days);

        // Get Xendit balance
        $xenditBalance = \App\Models\XenditBalanceSnapshot::getLatestBalance();

        // Get platform revenue summary
        $platformRevenue = \App\Models\PlatformFinanceLedger::getRevenueSummary($days);

        // Get user deposits summary
        $totalUserDeposits = \App\Models\UserWalletLedger::getTotalUserDeposits();

        // Get pending payouts
        $pendingPayouts = \App\Models\PlatformPayout::query()
            ->whereIn('status', ['pending', 'processing'])
            ->sum('amount');

        // Get organization wallet totals
        $orgWalletStats = \DB::table('organization_wallets')
            ->selectRaw('SUM(available_balance) as total_available, SUM(pending_balance) as total_pending, SUM(total_in) as total_in, SUM(total_out) as total_out')
            ->first();

        // Get platform payout statistics
        $payoutStats = \App\Models\PlatformPayout::query()
            ->selectRaw("
                COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_count,
                COUNT(CASE WHEN status = 'processing' THEN 1 END) as processing_count,
                COUNT(CASE WHEN status = 'paid' THEN 1 END) as paid_count,
                COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed_count,
                SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END) as total_paid_amount,
                SUM(CASE WHEN status IN ('pending', 'processing') THEN amount ELSE 0 END) as total_pending_amount
            ")
            ->first();

        // Get user withdrawal statistics
        $userWithdrawalStats = \DB::table('wallet_withdrawal_requests')
            ->selectRaw("
                COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_count,
                COUNT(CASE WHEN status = 'processing' THEN 1 END) as processing_count,
                COUNT(CASE WHEN status = 'paid' THEN 1 END) as paid_count,
                COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed_count,
                SUM(CASE WHEN status IN ('pending', 'processing') THEN amount ELSE 0 END) as total_pending_amount
            ")
            ->first();

        $withdrawableRevenue = \App\Models\PlatformFinanceLedger::getWithdrawableRevenue();

        $organizationWallets = [
            'total_available' => (int) ($orgWalletStats->total_available ?? 0),
            'total_pending' => (int) ($orgWalletStats->total_pending ?? 0),
            'total_inflow' => (int) ($orgWalletStats->total_in ?? 0),
            'total_outflow' => (int) ($orgWalletStats->total_out ?? 0),
        ];

        $platformPayouts = [
            'pending_count' => (int) ($payoutStats->pending_count ?? 0),
            'processing_count' => (int) ($payoutStats->processing_count ?? 0),
            'paid_count' => (int) ($payoutStats->paid_count ?? 0),
            'failed_count' => (int) ($payoutStats->failed_count ?? 0),
            'total_paid_amount' => (int) ($payoutStats->total_paid_amount ?? 0),
            'total_pending_amount' => (int) ($payoutStats->total_pending_amount ?? 0),
        ];

        $userWithdrawals = [
            'pending_count' => (int) ($userWithdrawalStats->pending_count ?? 0),
            'processing_count' => (int) ($userWithdrawalStats->processing_count ?? 0),
            'paid_count' => (int) ($userWithdrawalStats->paid_count ?? 0),
            'failed_count' => (int) ($userWithdrawalStats->failed_count ?? 0),
            'total_pending_amount' => (int) ($userWithdrawalStats->total_pending_amount ?? 0),
        ];

        return $this->ok([
            'range' => [
                'days' => $days,
                'start_at' => $startAt,
                'end_at' => now(),
            ],
            'xendit_balance' => $xenditBalance,
            'platform_revenue' => [
                'total_revenue' => $platformRevenue['total_revenue'],
                'revenue_count' => $platformRevenue['revenue_count'],
                'by_category' => $platformRevenue['by_category'],
                'withdrawable_revenue' => $withdrawableRevenue,
                'pending_payouts' => $pendingPayouts,
            ],
            'user_deposits' => [
                'total_deposits' => $totalUserDeposits,
                'active_users_count' => \DB::table('user_wallet_ledgers')
                    ->distinct('user_id')
                    ->count('user_id'),
            ],
            'organization_wallets' => $organizationWallets,
            'platform_payouts' => $platformPayouts,
            'user_withdrawals' => $userWithdrawals,
            'wallet' => [
                'available_balance' => $organizationWallets['total_available'],
                'pending_balance' => $organizationWallets['total_pending'],
                'total_in' => $organizationWallets['total_inflow'],
                'total_out' => $organizationWallets['total_outflow'],
            ],
            'period' => [
                'inflow' => (int) ($platformRevenue['total_revenue'] ?? 0),
                'outflow' => $platformPayouts['total_paid_amount'],
                'net' => (int) ($withdrawableRevenue - $platformPayouts['total_pending_amount']),
                'transaction_count' => (int) ($platformRevenue['revenue_count'] ?? 0),
            ],
            'withdrawals' => [
                'pending_count' => $platformPayouts['pending_count'] + $userWithdrawals['pending_count'],
                'processing_count' => $platformPayouts['processing_count'] + $userWithdrawals['processing_count'],
                'paid_count' => $platformPayouts['paid_count'] + $userWithdrawals['paid_count'],
                'failed_count' => $platformPayouts['failed_count'] + $userWithdrawals['failed_count'],
                'rejected_count' => 0,
                'cancelled_count' => 0,
            ],
        ], 'Platform finance summary');
    }

    public function createPlatformPayout(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        // Only super_admin can create platform payouts
        if ((string) ($user->role ?? '') !== 'super_admin') {
            return $this->fail('Only super admin can create platform payouts', ['code' => 'INSUFFICIENT_ROLE'], 403);
        }

        $validated = $request->validate([
            'amount' => ['required', 'integer', 'min:10000'], // Minimum 10k
            'bank_code' => ['required', 'string', 'size:3'],
            'account_number' => ['required', 'string', 'max:20'],
            'account_holder_name' => ['required', 'string', 'max:100'],
        ]);

        $amount = (int) $validated['amount'];
        $withdrawableRevenue = \App\Models\PlatformFinanceLedger::getWithdrawableRevenue();

        if ($amount > $withdrawableRevenue) {
            return $this->fail('Insufficient withdrawable revenue', [
                'code' => 'INSUFFICIENT_FUNDS',
                'available' => $withdrawableRevenue,
                'requested' => $amount,
            ], 400);
        }

        // Create platform payout record
        $payout = \App\Models\PlatformPayout::create([
            'status' => \App\Models\PlatformPayout::STATUS_PENDING,
            'currency' => 'IDR',
            'amount' => $amount,
            'bank_code' => $validated['bank_code'],
            'account_number' => $validated['account_number'],
            'account_holder_name' => $validated['account_holder_name'],
            'account_number_masked' => $this->maskAccountNumber($validated['account_number']),
        ]);

        // Try to create Xendit payout
        try {
            $xenditService = app(\App\Services\Hellom\XenditService::class);

            $payoutData = [
                'amount' => $amount,
                'bank_code' => $validated['bank_code'],
                'account_number' => $validated['account_number'],
                'account_holder_name' => $validated['account_holder_name'],
                'description' => 'Platform Revenue Withdrawal',
            ];

            $xenditResponse = $xenditService->createPayout($payoutData);

            // Mark as processing with external ID
            $payout->markAsProcessing($xenditResponse['id'] ?? null);

            // Record expense in platform ledger
            \App\Models\PlatformFinanceLedger::recordExpense(
                'owner_payout',
                $amount,
                'platform_payout',
                $payout->id,
                "Platform payout to {$validated['account_holder_name']} - {$validated['bank_code']} {$payout->account_number_masked}"
            );

        } catch (\Exception $e) {
            // Mark payout as failed if Xendit creation fails
            $payout->markAsFailed('XENDIT_ERROR', $e->getMessage());

            return $this->fail('Failed to create payout with Xendit', [
                'code' => 'XENDIT_PAYOUT_FAILED',
                'error' => $e->getMessage(),
            ], 500);
        }

        return $this->ok([
            'payout' => $payout,
        ], 'Platform payout created successfully');
    }

    public function rejectWithdrawal(Request $request, int $withdrawalId): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        [$organization, $requesterRole, $error] = $this->resolveCurrentOrganizationContext($user);
        if ($error) {
            return $error;
        }

        if (!in_array($requesterRole, ['owner', 'admin', 'super_admin'], true)) {
            return $this->fail('Only owner/admin/super admin can reject withdrawal', ['code' => 'INSUFFICIENT_ROLE'], 403);
        }

        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $result = DB::transaction(function () use ($organization, $user, $withdrawalId, $validated) {
            $withdrawal = WalletWithdrawalRequest::query()
                ->where('organization_id', (int) $organization->id)
                ->where('id', $withdrawalId)
                ->lockForUpdate()
                ->first();

            if (!$withdrawal instanceof WalletWithdrawalRequest) {
                return ['error' => $this->fail('Withdrawal request not found', ['code' => 'WITHDRAWAL_NOT_FOUND'], 404)];
            }

            if (!in_array((string) $withdrawal->status, ['pending', 'processing'], true)) {
                return ['error' => $this->fail('Only pending/processing withdrawal can be rejected', ['code' => 'WITHDRAWAL_NOT_REJECTABLE'], 422)];
            }

            $wallet = OrganizationWallet::query()
                ->where('id', (int) $withdrawal->wallet_id)
                ->lockForUpdate()
                ->first();

            if (!$wallet instanceof OrganizationWallet) {
                return ['error' => $this->fail('Wallet not found', ['code' => 'WALLET_NOT_FOUND'], 404)];
            }

            $amount = (int) $withdrawal->amount;

            $wallet->forceFill([
                'available_balance' => (int) $wallet->available_balance + $amount,
                'pending_balance' => max(0, (int) $wallet->pending_balance - $amount),
            ])->save();

            $withdrawal->forceFill([
                'status' => 'rejected',
                'reviewed_by_user_id' => (int) $user->id,
                'processed_at' => now(),
                'notes' => isset($validated['notes']) ? (string) $validated['notes'] : $withdrawal->notes,
            ])->save();

            OrganizationWalletTransaction::query()->create([
                'organization_id' => (int) $organization->id,
                'wallet_id' => (int) $wallet->id,
                'user_id' => (int) $user->id,
                'type' => 'withdrawal_reject_release',
                'direction' => 'credit',
                'amount' => $amount,
                'balance_after' => (int) $wallet->available_balance,
                'reference_type' => 'wallet_withdrawal_requests',
                'reference_id' => (string) $withdrawal->id,
                'external_ref' => (string) ($withdrawal->external_ref ?? ''),
                'description' => 'Withdrawal rejected and balance released',
                'metadata' => [
                    'notes' => isset($validated['notes']) ? (string) $validated['notes'] : null,
                ],
            ]);

            return [
                'wallet' => $wallet->fresh(),
                'withdrawal' => $withdrawal->fresh(),
            ];
        });

        if (isset($result['error']) && $result['error'] instanceof JsonResponse) {
            return $result['error'];
        }

        return $this->ok([
            'wallet' => $this->walletPayload($result['wallet']),
            'withdrawal' => $this->withdrawalPayload($result['withdrawal']),
        ], 'Withdrawal rejected');
    }

    private function ensureWallet(int $organizationId): OrganizationWallet
    {
        return OrganizationWallet::query()->firstOrCreate(
            ['organization_id' => $organizationId],
            [
                'currency' => 'IDR',
                'available_balance' => 0,
                'pending_balance' => 0,
                'total_in' => 0,
                'total_out' => 0,
                'status' => 'active',
            ]
        );
    }

    private function resolveCurrentOrganizationContext(User $user): array
    {
        $orgId = (int) ($user->current_organization_id ?? 0);
        if ($orgId <= 0) {
            return [null, null, $this->fail('No active organization selected', ['code' => 'NO_ACTIVE_ORGANIZATION'], 403)];
        }

        if (in_array((string) ($user->role ?? ''), ['admin', 'super_admin'], true)) {
            $organization = Organization::query()->find($orgId);
            if (!$organization instanceof Organization) {
                return [null, null, $this->fail('Current organization not found', ['code' => 'ORG_NOT_FOUND'], 404)];
            }

            return [$organization, (string) ($user->role ?? '') === 'super_admin' ? 'super_admin' : 'owner', null];
        }

        $organization = $user->organizations()->where('organizations.id', $orgId)->first();
        if (!$organization instanceof Organization) {
            return [null, null, $this->fail('Current organization not found in your access list', ['code' => 'ORG_NOT_ACCESSIBLE'], 404)];
        }

        $requesterRole = (string) ($organization->pivot->role ?? 'member');

        return [$organization, $requesterRole, null];
    }

    private function walletPayload(OrganizationWallet $wallet): array
    {
        return [
            'available_balance' => (int) $wallet->available_balance,
            'pending_balance' => (int) $wallet->pending_balance,
            'total_in' => (int) $wallet->total_in,
            'total_out' => (int) $wallet->total_out,
        ];
    }

    private function maskAccountNumber(string $accountNumber): string
    {
        $length = strlen($accountNumber);
        if ($length <= 4) {
            return $accountNumber;
        }

        $visibleStart = 4;
        $visibleEnd = 4;
        $maskLength = $length - $visibleStart - $visibleEnd;

        if ($maskLength <= 0) {
            return $accountNumber;
        }

        $start = substr($accountNumber, 0, $visibleStart);
        $end = substr($accountNumber, -$visibleEnd);
        $mask = str_repeat('*', $maskLength);

        return $start . $mask . $end;
    }

    private function transactionPayload(OrganizationWalletTransaction $transaction): array
    {
        return [
            'id' => (int) $transaction->id,
            'type' => (string) $transaction->type,
            'direction' => (string) $transaction->direction,
            'amount' => (int) $transaction->amount,
            'balance_after' => (int) $transaction->balance_after,
            'reference_type' => $transaction->reference_type,
            'reference_id' => $transaction->reference_id,
            'external_ref' => $transaction->external_ref,
            'description' => $transaction->description,
            'metadata' => $transaction->metadata,
            'created_at' => $transaction->created_at,
        ];
    }

    private function withdrawalPayload(WalletWithdrawalRequest $withdrawal): array
    {
        return [
            'id' => (int) $withdrawal->id,
            'status' => (string) $withdrawal->status,
            'amount' => (int) $withdrawal->amount,
            'fee_amount' => (int) $withdrawal->fee_amount,
            'net_amount' => (int) $withdrawal->net_amount,
            'bank_code' => $withdrawal->bank_code,
            'account_number_masked' => $withdrawal->account_number ? str_repeat('*', max(0, strlen((string) $withdrawal->account_number) - 4)) . substr((string) $withdrawal->account_number, -4) : null,
            'account_name' => $withdrawal->account_name,
            'provider' => (string) $withdrawal->provider,
            'external_ref' => $withdrawal->external_ref,
            'provider_ref' => $withdrawal->provider_ref,
            'notes' => $withdrawal->notes,
            'processed_at' => $withdrawal->processed_at,
            'created_at' => $withdrawal->created_at,
            'updated_at' => $withdrawal->updated_at,
        ];
    }

    private function normalizePaymentChannel(?string $channel): string
    {
        $value = strtolower(trim((string) $channel));
        if ($value === '') {
            return 'default';
        }

        if (str_contains($value, 'qris')) {
            return 'qris';
        }

        if (str_contains($value, 'va') || str_contains($value, 'virtual')) {
            return 'va';
        }

        if (str_contains($value, 'ewallet') || in_array($value, ['ovo', 'dana', 'linkaja', 'shopeepay', 'gopay'], true)) {
            return 'ewallet';
        }

        if (str_contains($value, 'card') || str_contains($value, 'credit')) {
            return 'card';
        }

        return $value;
    }

    private function resolvePayoutChannelCode(string $bankCode): string
    {
        $normalized = strtoupper(trim($bankCode));

        if (str_starts_with($normalized, 'ID_')) {
            return $normalized;
        }

        return 'ID_' . $normalized;
    }

    private function resolvePaymentChannelPolicy(string $channel): array
    {
        $defaultPolicy = (array) config('payments.providers.xendit.policy.default', []);
        $channelPolicies = (array) config('payments.providers.xendit.policy.channels', []);
        $selected = (array) ($channelPolicies[$channel] ?? []);

        return array_merge($defaultPolicy, $selected, [
            'channel' => $channel,
        ]);
    }
}
