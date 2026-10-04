<?php

namespace App\Services\Landing;

use App\Jobs\SendPlatformMail;
use App\Models\LandingPageOrder;
use App\Models\LandingRefund;
use App\Models\Organization;
use App\Models\SellerLedgerEntry;
use App\Models\User;
use App\Services\SellerFinance\FinanceException;
use App\Services\SellerFinance\SellerLedger;
use App\Support\FrontendUrl;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seller-initiated refunds. The refund amount leaves the seller's TERSEDIA balance at once
 * (ledger "refund" row, under the balance lock); Hellom sends it to the buyer's account and
 * marks it paid, or marks it failed and the money goes back to the seller.
 * Hellom's service fee is not returned (seller bears the full refund) — see policy page.
 */
final class RefundService
{
    public function __construct(private readonly SellerLedger $ledger)
    {
    }

    /** @param array{amount:int, reason:string, destination_type:string, bank_code:string, bank_name:?string, account_number:string, account_name:string} $data */
    public function request(LandingPageOrder $order, User $user, array $data): LandingRefund
    {
        $refund = DB::transaction(function () use ($order, $user, $data): LandingRefund {
            $locked = LandingPageOrder::query()->lockForUpdate()->findOrFail($order->id);
            if (!$locked->isPaid()) {
                throw new FinanceException('Hanya pesanan lunas yang bisa direfund.', 'ORDER_NOT_PAID');
            }
            if (LandingRefund::query()->where('order_id', $locked->id)->whereIn('status', [LandingRefund::STATUS_REQUESTED, LandingRefund::STATUS_PAID])->exists()) {
                throw new FinanceException('Pesanan ini sudah punya refund.', 'REFUND_EXISTS');
            }
            $amount = (int) $data['amount'];
            if ($amount < 1 || $amount > (int) $locked->amount) {
                throw new FinanceException('Nominal refund maksimal ' . $this->rupiah((int) $locked->amount) . '.', 'REFUND_AMOUNT_INVALID');
            }

            $balance = $this->ledger->lock((int) $locked->organization_id);
            // A sale still on hold is released first so the refund comes from one bucket.
            $this->ledger->releaseOrder($balance, $locked);
            if ((int) $balance->available < $amount) {
                throw new FinanceException('Saldo tersedia tidak cukup untuk refund (' . $this->rupiah((int) $balance->available) . ').', 'INSUFFICIENT_BALANCE');
            }

            $refund = LandingRefund::query()->create([
                'organization_id' => $locked->organization_id,
                'order_id' => $locked->id,
                'requested_by_user_id' => $user->id,
                'reference' => 'rfd_' . Str::upper(Str::random(16)),
                'status' => LandingRefund::STATUS_REQUESTED,
                'amount' => $amount,
                'reason' => Str::limit(trim($data['reason']), 490, ''),
                'destination_type' => $data['destination_type'] === 'ewallet' ? 'ewallet' : 'bank',
                'bank_code' => strtoupper(trim($data['bank_code'])),
                'bank_name' => $data['bank_name'] ?? null,
                'account_number' => preg_replace('/\s+/', '', $data['account_number']),
                'account_name' => trim($data['account_name']),
            ]);
            $this->ledger->post($balance, SellerLedgerEntry::TYPE_REFUND, SellerLedgerEntry::BUCKET_AVAILABLE, -$amount, [
                'order_id' => $locked->id,
                'idempotency_key' => "refund:{$refund->id}",
                'description' => 'Refund ke pembeli: ' . $locked->product_name,
                'created_by_user_id' => $user->id,
            ]);

            return $refund;
        }, 3);

        $this->notifyBuyer($refund, 'Refund sedang diproses', 'Penjual mengajukan pengembalian dana untuk pesanan kamu. Tim Hellom akan mentransfernya ke rekening yang didaftarkan.');

        return $refund;
    }

    public function markPaid(LandingRefund $refund, User $admin, ?UploadedFile $proof = null): LandingRefund
    {
        $updated = DB::transaction(function () use ($refund, $admin, $proof): LandingRefund {
            $locked = LandingRefund::query()->lockForUpdate()->findOrFail($refund->id);
            if ($locked->status !== LandingRefund::STATUS_REQUESTED) {
                throw new FinanceException('Refund ini sudah selesai.', 'REFUND_CLOSED');
            }
            $order = LandingPageOrder::query()->lockForUpdate()->findOrFail($locked->order_id);
            if ($order->canTransitionTo(LandingPageOrder::STATUS_REFUNDED)) {
                $order->forceFill(['status' => LandingPageOrder::STATUS_REFUNDED, 'refunded_at' => now()])->save();
                app(BookingService::class)->cancelForOrder($order); // rental: the time is free again
            }
            $locked->forceFill([
                'status' => LandingRefund::STATUS_PAID,
                'paid_at' => now(),
                'reviewed_by_user_id' => $admin->id,
                'proof_path' => $proof ? $proof->store('landing-refunds/' . $locked->organization_id, 'local') : $locked->proof_path,
            ])->save();

            return $locked;
        }, 3);

        $this->notifyBuyer($updated, 'Dana sudah dikembalikan', 'Pengembalian dana untuk pesanan kamu sudah ditransfer. Waktu masuk tergantung bank/e-wallet tujuan.');

        return $updated;
    }

    public function markFailed(LandingRefund $refund, User $admin, string $reason): LandingRefund
    {
        $updated = DB::transaction(function () use ($refund, $admin, $reason): LandingRefund {
            $locked = LandingRefund::query()->lockForUpdate()->findOrFail($refund->id);
            if ($locked->status !== LandingRefund::STATUS_REQUESTED) {
                throw new FinanceException('Refund ini sudah selesai.', 'REFUND_CLOSED');
            }
            $balance = $this->ledger->lock((int) $locked->organization_id);
            $this->ledger->post($balance, SellerLedgerEntry::TYPE_REFUND_REVERSAL, SellerLedgerEntry::BUCKET_AVAILABLE, (int) $locked->amount, [
                'order_id' => $locked->order_id,
                'idempotency_key' => "refund_reversal:{$locked->id}",
                'description' => 'Refund gagal: ' . Str::limit($reason, 200, ''),
                'created_by_user_id' => $admin->id,
            ]);
            $locked->forceFill([
                'status' => LandingRefund::STATUS_FAILED,
                'failed_at' => now(),
                'failure_reason' => Str::limit($reason, 250, ''),
                'reviewed_by_user_id' => $admin->id,
            ])->save();

            return $locked;
        }, 3);

        $sellerEmails = Organization::query()->with('users')->find($updated->organization_id)?->users
            ->filter(fn ($m) => in_array((string) ($m->pivot->role ?? ''), ['owner', 'admin'], true))->pluck('email')->filter()->unique()->values()->all() ?? [];
        SendPlatformMail::dispatch($sellerEmails, 'Refund gagal — ' . $updated->reference, [
            'headline' => 'Refund belum berhasil',
            'intro' => 'Refund ' . $this->rupiah((int) $updated->amount) . ' gagal ditransfer: ' . $reason . '. Dana sudah kembali ke saldo tersedia kamu.',
            'cta_url' => FrontendUrl::to('/dashboard/apps/landing-builder?tab=pesanan'),
            'cta_label' => 'Buka Pesanan',
        ]);

        return $updated;
    }

    private function notifyBuyer(LandingRefund $refund, string $headline, string $intro): void
    {
        $order = LandingPageOrder::query()->find($refund->order_id);
        if (!$order?->buyer_email) {
            return;
        }
        SendPlatformMail::dispatch([(string) $order->buyer_email], $headline . ' — ' . $order->reference_id, [
            'headline' => $headline,
            'intro' => $intro,
            'details' => [
                'No. pesanan' => (string) $order->reference_id,
                'Produk' => (string) $order->product_name,
                'Nominal refund' => $this->rupiah((int) $refund->amount),
                'Tujuan' => ($refund->bank_name ?: $refund->bank_code) . ' ••••' . substr((string) $refund->account_number, -4) . ' a.n. ' . $refund->account_name,
                'Status' => LandingRefund::LABELS[$refund->status] ?? $refund->status,
            ],
        ]);
    }

    private function rupiah(int $value): string
    {
        return 'Rp ' . number_format($value, 0, ',', '.');
    }
}
