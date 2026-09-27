<?php

namespace App\Http\Controllers\Api\V1\Hellom\Billing\Concerns;

use App\Models\OrganizationWallet;
use App\Models\OrganizationWalletTransaction;

/**
 * JSON shapes for organization wallets and wallet transactions in billing responses.
 */
trait PresentsWallets
{
    private function walletPayload(OrganizationWallet $wallet): array
    {
        return [
            'id' => (int) $wallet->id,
            'organization_id' => (int) $wallet->organization_id,
            'currency' => (string) $wallet->currency,
            'available_balance' => (int) $wallet->available_balance,
            'pending_balance' => (int) $wallet->pending_balance,
            'total_in' => (int) $wallet->total_in,
            'total_out' => (int) $wallet->total_out,
            'status' => (string) $wallet->status,
            'updated_at' => $wallet->updated_at,
        ];
    }

    private function walletTransactionPayload(OrganizationWalletTransaction $transaction): array
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
}
