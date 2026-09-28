<?php

namespace App\Services\Hellom;

use App\Mail\HellomCheckoutStatusMail;
use App\Models\LandingBlock;
use App\Models\LandingOrderItem;
use App\Models\LandingPageOrder;
use App\Models\Organization;
use App\Models\OrganizationLandingPage;
use App\Services\SellerFinance\FeeCalculator;
use App\Services\SellerFinance\FinanceSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Landing-page product sales: creates the pending order (price from the database, with a
 * product snapshot) and sends the sale emails. Payment confirmation, fees and the seller
 * balance live in App\Services\SellerFinance\LandingPaymentService (Fase 2).
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

    /** Validate the block/price server-side and create a pending order + item snapshot. */
    public function createPendingOrder(OrganizationLandingPage $page, LandingBlock $block, array $buyer): LandingPageOrder
    {
        $content = is_array($block->content) ? $block->content : [];
        $kind = (string) $block->block_type === 'pdf' ? 'pdf' : 'product';

        $amount = $this->parsePrice($content['price'] ?? 0);
        $productName = Str::limit((string) ($content['name'] ?? $content['title'] ?? 'Produk'), 200, '');
        $fileUrl = $kind === 'pdf' ? (string) ($content['fileUrl'] ?? '') : (string) ($content['fileUrl'] ?? $content['downloadUrl'] ?? '');

        // Estimate only (payment method unknown yet); the real split is booked at payment.
        $estimate = app(FeeCalculator::class)->split($amount, null);
        $expiryHours = (int) app(FinanceSettings::class)->get('order_expiry_hours');

        return DB::transaction(function () use ($page, $block, $buyer, $kind, $amount, $productName, $fileUrl, $estimate, $expiryHours, $content): LandingPageOrder {
            $order = LandingPageOrder::query()->create([
                'organization_id' => (int) $page->organization_id,
                'landing_page_id' => (int) $page->id,
                'block_id' => (string) $block->id,
                'product_kind' => $kind,
                'product_name' => $productName,
                'amount' => $amount,
                'commission_amount' => $estimate['platform_fee'],
                'net_amount' => $estimate['seller_net'],
                'buyer_name' => $buyer['name'] ?? null,
                'buyer_email' => $buyer['email'] ?? null,
                'buyer_phone' => $buyer['phone'] ?? null,
                'status' => LandingPageOrder::STATUS_PENDING,
                'reference_id' => 'lps_' . Str::upper(Str::random(18)),
                'file_url' => $fileUrl !== '' ? $fileUrl : null,
                'expires_at' => now()->addHours(max(1, $expiryHours)),
                'metadata' => ['fee_estimate' => $estimate],
            ]);

            LandingOrderItem::query()->create([
                'order_id' => $order->id,
                'block_id' => (string) $block->id,
                'product_kind' => $kind,
                'product_name' => $productName,
                'unit_price' => $amount,
                'qty' => 1,
                'line_total' => $amount,
                // Public fields only: the delivery link is never copied into the snapshot.
                'snapshot' => array_diff_key($content, array_flip(LandingBlock::SECRET_CONTENT_KEYS)),
            ]);

            return $order;
        });
    }

    /** Email the buyer (receipt + download link) and notify the seller's owners/admins. */
    public function sendSaleEmails(LandingPageOrder $order): void
    {
        $mailer = app(PlatformMailService::class);
        $fmt = fn (int $v) => 'Rp ' . number_format($v, 0, ',', '.');
        $paidAt = optional($order->paid_at)->format('d M Y H:i') ?? now()->format('d M Y H:i');

        // ── Buyer receipt ──
        if ($order->buyer_email) {
            $buyerPayload = [
                'headline' => 'Pembayaran berhasil 🎉',
                'intro' => 'Terima kasih! Pembayaran kamu untuk "' . (string) $order->product_name . '" sudah kami terima.',
                'details' => [
                    'Produk' => (string) $order->product_name,
                    'Nominal' => $fmt((int) $order->amount),
                    'No. Order' => (string) $order->reference_id,
                    'Tanggal' => $paidAt,
                ],
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

        $available = $order->settlement_eta === null || $order->settlement_eta->isPast();
        $sellerPayload = [
            'headline' => 'Ada penjualan baru 💰',
            'intro' => 'Produk "' . (string) $order->product_name . '" baru saja terjual di landing page kamu.',
            'details' => [
                'Produk' => (string) $order->product_name,
                'Pembeli' => (string) ($order->buyer_name ?? '-'),
                'Email pembeli' => (string) ($order->buyer_email ?? '-'),
                'Harga' => $fmt((int) $order->amount),
                'Biaya layanan Hellom' => $fmt((int) $order->commission_amount),
                'Masuk saldo (bersih)' => $fmt((int) $order->net_amount),
                'Status saldo' => $available
                    ? 'Tersedia — sudah bisa ditarik'
                    : 'Tertahan sampai ' . $order->settlement_eta->format('d M Y H:i'),
                'Tanggal' => $paidAt,
            ],
            'closing' => 'Cek Saldo Penjualan di dashboard Hellom (Landing Page Builder › Saldo).',
        ];

        foreach ($recipients as $email) {
            $mailer->sendTo((string) $email, new HellomCheckoutStatusMail(
                subjectLine: 'Penjualan baru — ' . (string) $order->product_name,
                payload: $sellerPayload,
            ));
        }
    }
}
