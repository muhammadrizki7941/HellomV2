<?php

namespace App\Http\Controllers\Api\V1\Hellom;

use App\Http\Controllers\Api\V1\Hellom\Concerns\ResolvesSellerOrganization;
use App\Models\LandingPageOrder;
use App\Models\Organization;
use App\Models\SellerBalance;
use App\Models\SellerLedgerEntry;
use App\Models\SellerWithdrawal;
use App\Services\SellerFinance\FinanceException;
use App\Services\SellerFinance\FinanceSettings;
use App\Services\SellerFinance\SellerLedger;
use App\Services\SellerFinance\WithdrawalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Seller side of landing-page sales money ("Saldo Penjualan"). Always scoped to the
 * user's current organization; only owners/admins of that organization see money.
 * Not behind the app subscription: a seller must be able to withdraw after it ends.
 */
class SellerFinanceController extends BaseApiController
{
    use ResolvesSellerOrganization;

    public function __construct(
        private readonly SellerLedger $ledger,
        private readonly WithdrawalService $withdrawals,
        private readonly FinanceSettings $settings,
    ) {
    }

    public function summary(Request $request): JsonResponse
    {
        [$organization, $error] = $this->organization($request);
        if ($error) {
            return $error;
        }
        $balance = SellerBalance::query()->find($organization->id);
        $settings = $this->settings->all();

        return $this->ok([
            'balance' => [
                'pending' => (int) ($balance->pending ?? 0),
                'available' => (int) ($balance->available ?? 0),
                'processing' => (int) ($balance->processing ?? 0),
                'withdrawn' => (int) ($balance->withdrawn ?? 0),
                'is_frozen' => (bool) ($balance->is_frozen ?? false),
            ],
            'payout_account' => $this->withdrawals->payoutAccount((int) $organization->id, $request->user()),
            'rules' => [
                'min_withdrawal' => (int) $settings['min_withdrawal'],
                'withdrawal_fee_flat' => (int) $settings['withdrawal_fee_flat'],
                'hold_days' => $this->ledger->holdDaysFor((int) $organization->id),
                'platform_fee_percent' => (float) $settings['platform_fee_percent'],
                'sla_hours' => (int) $settings['sla_hours'],
            ],
            'sales' => [
                'paid_orders' => LandingPageOrder::query()->where('organization_id', $organization->id)
                    ->whereIn('status', [LandingPageOrder::STATUS_PAID, LandingPageOrder::STATUS_FULFILLED])->count(),
                'gross_total' => (int) LandingPageOrder::query()->where('organization_id', $organization->id)
                    ->whereIn('status', [LandingPageOrder::STATUS_PAID, LandingPageOrder::STATUS_FULFILLED])->sum('amount'),
            ],
        ], 'Saldo penjualan');
    }

    /** Transaction history; sale rows carry the price / fee / net breakdown of their order. */
    public function ledger(Request $request): JsonResponse
    {
        [$organization, $error] = $this->organization($request);
        if ($error) {
            return $error;
        }
        $rows = SellerLedgerEntry::query()
            ->where('organization_id', $organization->id)
            ->whereNot(fn ($q) => $q->where('type', SellerLedgerEntry::TYPE_RELEASE)->where('bucket', SellerLedgerEntry::BUCKET_PENDING))
            ->with(['order:id,reference_id,product_name,amount,commission_amount,net_amount,buyer_name,paid_at,payment_method', 'withdrawal:id,reference,status'])
            ->orderByDesc('id')
            ->paginate(min(50, max(10, (int) $request->query('per_page', 20))));

        $rows->getCollection()->transform(fn (SellerLedgerEntry $row) => [
            'id' => $row->id,
            'type' => $row->type,
            'type_label' => SellerLedgerEntry::LABELS[$row->type] ?? $row->type,
            'bucket' => $row->bucket,
            'amount' => (int) $row->amount,
            'pending_after' => (int) $row->pending_after,
            'available_after' => (int) $row->available_after,
            'description' => $row->description,
            'available_at' => optional($row->available_at)->toIso8601String(),
            'created_at' => optional($row->created_at)->toIso8601String(),
            'order' => $row->order ? [
                'reference' => $row->order->reference_id,
                'product_name' => $row->order->product_name,
                'buyer_name' => $row->order->buyer_name,
                'price' => (int) $row->order->amount,
                'fee' => (int) $row->order->commission_amount,
                'net' => (int) $row->order->net_amount,
                'payment_method' => $row->order->payment_method,
            ] : null,
            'withdrawal' => $row->withdrawal ? ['reference' => $row->withdrawal->reference, 'status' => $row->withdrawal->status] : null,
        ]);

        return $this->ok($rows, 'Riwayat saldo');
    }

    public function withdrawals(Request $request): JsonResponse
    {
        [$organization, $error] = $this->organization($request);
        if ($error) {
            return $error;
        }
        $items = SellerWithdrawal::query()->where('organization_id', $organization->id)->orderByDesc('id')->paginate(20);
        $items->getCollection()->transform(fn (SellerWithdrawal $w) => $this->withdrawalPayload($w));

        return $this->ok($items, 'Riwayat penarikan');
    }

    public function requestWithdrawal(Request $request): JsonResponse
    {
        [$organization, $error] = $this->organization($request);
        if ($error) {
            return $error;
        }
        $validated = $request->validate([
            'amount' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'notes' => ['nullable', 'string', 'max:300'],
        ]);

        try {
            $withdrawal = $this->withdrawals->request($organization, $request->user(), (int) $validated['amount'], $validated['notes'] ?? null);
        } catch (FinanceException $e) {
            return $this->fail($e->getMessage(), ['code' => $e->errorCode], $e->status);
        }

        return $this->ok(['withdrawal' => $this->withdrawalPayload($withdrawal)], 'Penarikan diajukan', 201);
    }

    public function cancelWithdrawal(Request $request, int $withdrawalId): JsonResponse
    {
        [$organization, $error] = $this->organization($request);
        if ($error) {
            return $error;
        }
        $withdrawal = SellerWithdrawal::query()->where('organization_id', $organization->id)->find($withdrawalId);
        if (!$withdrawal) {
            return $this->fail('Penarikan tidak ditemukan', ['code' => 'WITHDRAWAL_NOT_FOUND'], 404);
        }
        try {
            $withdrawal = $this->withdrawals->cancel($withdrawal, $request->user());
        } catch (FinanceException $e) {
            return $this->fail($e->getMessage(), ['code' => $e->errorCode], $e->status);
        }

        return $this->ok(['withdrawal' => $this->withdrawalPayload($withdrawal)], 'Penarikan dibatalkan, dana kembali ke saldo tersedia');
    }

    /** @return array<string, mixed> */
    public static function withdrawalPayload(SellerWithdrawal $w): array
    {
        return [
            'id' => $w->id,
            'reference' => $w->reference,
            'status' => $w->status,
            'status_label' => SellerWithdrawal::LABELS[$w->status] ?? $w->status,
            'amount' => (int) $w->amount,
            'fee_amount' => (int) $w->fee_amount,
            'net_amount' => (int) $w->net_amount,
            'destination_type' => $w->destination_type,
            'bank_code' => $w->bank_code,
            'bank_name' => $w->bank_name,
            'account_number_masked' => strlen((string) $w->account_number) > 4 ? '••••' . substr((string) $w->account_number, -4) : $w->account_number,
            'account_name' => $w->account_name,
            'mode' => $w->mode,
            'failure_reason' => $w->failure_reason,
            'created_at' => optional($w->created_at)->toIso8601String(),
            'processing_at' => optional($w->processing_at)->toIso8601String(),
            'paid_at' => optional($w->paid_at)->toIso8601String(),
        ];
    }

    /** @return array{0: ?Organization, 1: ?JsonResponse} */
    private function organization(Request $request): array
    {
        return $this->sellerOrganization($request, 'Hanya pemilik/admin toko yang bisa melihat saldo penjualan');
    }
}
