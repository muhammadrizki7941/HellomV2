<?php

namespace App\Services\Hellom;

use App\Mail\HellomCheckoutStatusMail;
use App\Models\LandingBlock;
use App\Models\LandingPageOrder;
use App\Models\Organization;
use App\Models\OrganizationLandingPage;
use App\Models\OrganizationWallet;
use App\Models\OrganizationWalletTransaction;
use App\Models\PlatformFinanceLedger;
use App\Services\Hellom\PaymentGatewaySettingsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Handles landing-page product/PDF sales: buyer pays via the platform's active
 * gateway (money lands in Hellom's account), then on webhook confirmation the
 * seller's wallet is credited (pending balance, minus platform commission) and
 * the commission is recorded as platform revenue. Pending balance is later
 * released to available by ReleasePendingWalletSettlementsCommand.
 */
class LandingSaleService
{
    /** Parse a rupiah-ish string ("Rp 199.000") to an integer. */
    public function parsePrice(mixed $value): int
    {
        if (is_int($value)) {
            return max(0, $value);
        }
        $digits = preg_replace('/[^\d]/', '', (string) $value);

        return $digits === '' ? 0 : (int) $digits;
    }

    /** Now + settlement delay, rolled forward past Sat/Sun (weekday payouts). */
    public function nextWeekdayEta(): Carbon
    {
        $hours = (int) config('payments.wallet.settlement_delay_hours', 24);
        $eta = now()->addHours(max(1, $hours));

        // Skip weekend: if ETA lands on Sat/Sun, push to Monday.
        while ($eta->isWeekend()) {
            $eta = $eta->addDay()->startOfDay()->addHours(9); // 09:00 next business day
        }

        return $eta;
    }

    public function commissionPercent(): float
    {
        // Admin-configurable (Admin → Payment settings); falls back to config default.
        return (float) app(PaymentGatewaySettingsService::class)
            ->getRuntimeConfig()['sale_commission_percent'];
    }

    /**
     * Validate the block/price server-side and create a pending order row.
     * Returns the order (caller creates the gateway session + sets provider/url).
     */
    public function createPendingOrder(OrganizationLandingPage $page, LandingBlock $block, array $buyer): LandingPageOrder
    {
        $content = is_array($block->content) ? $block->content : [];
        $kind = (string) $block->block_type === 'pdf' ? 'pdf' : 'product';

        $amount = $this->parsePrice($content['price'] ?? 0);
        $productName = (string) ($content['name'] ?? $content['title'] ?? 'Produk');
        $fileUrl = $kind === 'pdf' ? (string) ($content['fileUrl'] ?? '') : (string) ($content['fileUrl'] ?? $content['downloadUrl'] ?? '');

        $commission = (int) floor($amount * $this->commissionPercent() / 100);
        $net = max(0, $amount - $commission);

        return LandingPageOrder::query()->create([
            'organization_id' => (int) $page->organization_id,
            'landing_page_id' => (int) $page->id,
            'block_id' => (string) $block->id,
            'product_kind' => $kind,
            'product_name' => $productName,
            'amount' => $amount,
            'commission_amount' => $commission,
            'net_amount' => $net,
            'buyer_name' => $buyer['name'] ?? null,
            'buyer_email' => $buyer['email'] ?? null,
            'buyer_phone' => $buyer['phone'] ?? null,
            'status' => LandingPageOrder::STATUS_PENDING,
            'reference_id' => 'lps_' . Str::upper(Str::random(18)),
            'file_url' => $fileUrl !== '' ? $fileUrl : null,
            'metadata' => ['commission_percent' => $this->commissionPercent()],
        ]);
    }

    /**
     * Idempotently settle a paid order: credit seller wallet (pending) minus
     * commission, record platform revenue, and prepare buyer delivery.
     */
    public function settlePaidOrderByReference(string $referenceId, array $ctx = []): ?LandingPageOrder
    {
        if ($referenceId === '') {
            return null;
        }

        $newlySettled = false;

        $result = DB::transaction(function () use ($referenceId, $ctx, &$newlySettled): ?LandingPageOrder {
            $order = LandingPageOrder::query()
                ->where('reference_id', $referenceId)
                ->lockForUpdate()
                ->first();

            if (!$order instanceof LandingPageOrder) {
                return null;
            }

            if ($order->isPaid()) {
                return $order; // already settled — idempotent
            }

            $newlySettled = true;

            $eta = $this->nextWeekdayEta();

            $order->forceFill([
                'status' => LandingPageOrder::STATUS_PAID,
                'provider' => (string) ($ctx['provider'] ?? $order->provider),
                'gateway_ref' => (string) ($ctx['gateway_ref'] ?? $order->gateway_ref),
                'download_token' => $order->download_token ?: Str::random(48),
                'settlement_eta' => $eta,
                'paid_at' => now(),
            ])->save();

            $net = (int) $order->net_amount;

            if ($net > 0) {
                $wallet = OrganizationWallet::query()
                    ->where('organization_id', (int) $order->organization_id)
                    ->lockForUpdate()
                    ->first();

                if (!$wallet instanceof OrganizationWallet) {
                    $wallet = OrganizationWallet::query()->create([
                        'organization_id' => (int) $order->organization_id,
                        'currency' => 'IDR',
                        'available_balance' => 0,
                        'pending_balance' => 0,
                        'total_in' => 0,
                        'total_out' => 0,
                        'status' => 'active',
                    ]);
                }

                $wallet->forceFill([
                    'pending_balance' => (int) $wallet->pending_balance + $net,
                    'total_in' => (int) $wallet->total_in + $net,
                ])->save();

                OrganizationWalletTransaction::query()->create([
                    'organization_id' => (int) $wallet->organization_id,
                    'wallet_id' => (int) $wallet->id,
                    'user_id' => null,
                    'type' => 'payment_credit_pending',
                    'direction' => 'credit',
                    'amount' => $net,
                    'balance_after' => (int) $wallet->available_balance,
                    'reference_type' => 'landing_page_orders',
                    'reference_id' => (string) $order->id,
                    'external_ref' => (string) $order->reference_id,
                    'description' => 'Penjualan landing page: ' . (string) $order->product_name,
                    'metadata' => [
                        'settlement_eta' => $eta->toISOString(),
                        'settlement_status' => 'pending',
                        'gross_amount' => (int) $order->amount,
                        'commission_amount' => (int) $order->commission_amount,
                    ],
                ]);
            }

            if ((int) $order->commission_amount > 0) {
                PlatformFinanceLedger::recordRevenue(
                    'landing_commission',
                    (int) $order->commission_amount,
                    (int) $order->organization_id,
                    'landing_page_orders',
                    (int) $order->id,
                    'Komisi penjualan landing page: ' . (string) $order->product_name
                );
            }

            return $order;
        });

        // Notifications happen after commit so a mail failure never rolls back the sale.
        if ($result instanceof LandingPageOrder && $newlySettled) {
            try {
                $this->sendSaleEmails($result);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $result;
    }

    /** Email the buyer (receipt + download link) and notify the seller's owners/admins. */
    private function sendSaleEmails(LandingPageOrder $order): void
    {
        $mailer = app(PlatformMailService::class);
        $fmt = fn (int $v) => 'Rp ' . number_format($v, 0, ',', '.');
        $paidAt = optional($order->paid_at)->format('d M Y H:i') ?? now()->format('d M Y H:i');

        // ── Buyer receipt ──
        if ($order->buyer_email) {
            $buyerDetails = [
                'Produk' => (string) $order->product_name,
                'Nominal' => $fmt((int) $order->amount),
                'No. Order' => (string) $order->reference_id,
                'Tanggal' => $paidAt,
            ];

            $buyerPayload = [
                'headline' => 'Pembayaran berhasil 🎉',
                'intro' => 'Terima kasih! Pembayaran kamu untuk "' . (string) $order->product_name . '" sudah kami terima.',
                'details' => $buyerDetails,
            ];

            if ($order->file_url) {
                $buyerPayload['cta_url'] = (string) $order->file_url;
                $buyerPayload['cta_label'] = 'Unduh Produk';
                $buyerPayload['closing'] = 'Simpan email ini sebagai bukti pembelian. Jika tombol tidak bekerja, salin tautan ini: ' . (string) $order->file_url;
            } else {
                $buyerPayload['closing'] = 'Penjual akan menghubungi kamu untuk pengiriman produk. Simpan email ini sebagai bukti pembelian.';
            }

            $mailer->sendTo((string) $order->buyer_email, new HellomCheckoutStatusMail(
                subjectLine: 'Pembayaran berhasil — ' . (string) $order->product_name,
                payload: $buyerPayload,
            ));
        }

        // ── Seller notification (owners/admins of the organization) ──
        $organization = Organization::query()->with('users')->find((int) $order->organization_id);
        if (!$organization instanceof Organization) {
            return;
        }

        $recipients = $organization->users
            ->filter(fn ($member) => in_array((string) ($member->pivot->role ?? ''), ['owner', 'admin', 'super_admin'], true))
            ->pluck('email')
            ->filter()
            ->unique()
            ->values();

        if ($recipients->isEmpty()) {
            return;
        }

        $sellerPayload = [
            'headline' => 'Ada penjualan baru 💰',
            'intro' => 'Produk "' . (string) $order->product_name . '" baru saja terjual di landing page kamu.',
            'details' => [
                'Produk' => (string) $order->product_name,
                'Pembeli' => (string) ($order->buyer_name ?? '-'),
                'Email pembeli' => (string) ($order->buyer_email ?? '-'),
                'Harga' => $fmt((int) $order->amount),
                'Komisi platform' => $fmt((int) $order->commission_amount),
                'Masuk saldo (bersih)' => $fmt((int) $order->net_amount),
                'Status saldo' => 'Pending — cair otomatis maksimal 1×24 jam pada hari kerja',
                'Tanggal' => $paidAt,
            ],
            'closing' => 'Cek Saldo Penjualan kamu di dashboard Hellom (menu Payments).',
        ];

        foreach ($recipients as $email) {
            $mailer->sendTo((string) $email, new HellomCheckoutStatusMail(
                subjectLine: 'Penjualan baru — ' . (string) $order->product_name,
                payload: $sellerPayload,
            ));
        }
    }

    /** Mark an order failed (non-success webhook). */
    public function markFailedByReference(string $referenceId): void
    {
        if ($referenceId === '') {
            return;
        }

        LandingPageOrder::query()
            ->where('reference_id', $referenceId)
            ->where('status', LandingPageOrder::STATUS_PENDING)
            ->update(['status' => LandingPageOrder::STATUS_FAILED]);
    }
}
