<?php

namespace App\Services\Hellom;

use App\Mail\HellomCheckoutStatusMail;
use App\Models\LandingBlock;
use App\Models\LandingOrderItem;
use App\Models\LandingPageOrder;
use App\Models\LandingProduct;
use App\Models\Organization;
use App\Models\OrganizationLandingPage;
use App\Services\SellerFinance\FeeCalculator;
use App\Services\SellerFinance\FinanceSettings;
use App\Support\FrontendUrl;
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

    /**
     * Email the buyer (invoice + Hellom access link, never the raw file/Drive link) and
     * notify the seller's owners/admins. Digital orders count as delivered once sent.
     */
    public function sendSaleEmails(LandingPageOrder $order, bool $buyerOnly = false): void
    {
        $mailer = app(PlatformMailService::class);
        $fmt = fn (int $v) => 'Rp ' . number_format($v, 0, ',', '.');
        $paidAt = optional($order->paid_at)->timezone('Asia/Jakarta')->format('d M Y H:i') ?? now()->timezone('Asia/Jakarta')->format('d M Y H:i');
        $organization = Organization::query()->with('users')->find((int) $order->organization_id);
        $kind = (string) $order->product_kind;
        $isDigital = in_array($kind, [LandingProduct::TYPE_DRIVE, LandingProduct::TYPE_FILE, LandingProduct::TYPE_LINK, 'pdf', 'product'], true)
            && ($order->product_id !== null || $order->file_url);

        // ── Buyer invoice ──
        if ($order->buyer_email) {
            $details = ['No. pesanan' => (string) $order->reference_id, 'Tanggal' => $paidAt, 'Penjual' => (string) ($organization?->name ?? '-')];
            $details['Produk'] = (string) $order->product_name . ((int) $order->quantity > 1 ? ' × ' . (int) $order->quantity : '');
            if ($order->subtotal_amount !== null && ((int) $order->discount_amount > 0 || (int) $order->shipping_amount > 0)) {
                $details['Subtotal'] = $fmt((int) $order->subtotal_amount);
                if ((int) $order->discount_amount > 0) {
                    $details['Diskon' . ($order->coupon_code ? ' (' . $order->coupon_code . ')' : '')] = '−' . $fmt((int) $order->discount_amount);
                }
                if ((int) $order->shipping_amount > 0) {
                    $details['Ongkir'] = $fmt((int) $order->shipping_amount);
                }
            }
            $details['Total dibayar'] = $fmt((int) $order->amount);

            $buyerPayload = [
                'headline' => 'Pembayaran berhasil 🎉',
                'intro' => 'Terima kasih, ' . ((string) $order->buyer_name ?: 'kak') . '! Pembayaran kamu untuk "' . (string) $order->product_name . '" sudah kami terima.',
                'details' => $details,
            ];
            if ($isDigital && $order->accessUrl()) {
                $buyerPayload['cta_url'] = $order->accessUrl();
                $buyerPayload['cta_label'] = 'Buka Produk';
                $buyerPayload['closing'] = 'Tombol di atas membuka halaman akses produk kamu. Simpan email ini — halaman akses selalu berisi link terbaru dari penjual. '
                    . 'Kalau tombol tidak bisa diklik, salin tautan ini: ' . $order->accessUrl();
            } elseif ($kind === LandingProduct::TYPE_PHYSICAL) {
                $buyerPayload['cta_url'] = $order->accessUrl();
                $buyerPayload['cta_label'] = 'Lihat Status Pesanan';
                $buyerPayload['closing'] = 'Penjual akan mengemas dan mengirim pesanan ke alamat kamu. Nomor resi akan muncul di halaman status pesanan.';
            } else {
                $buyerPayload['cta_url'] = $order->accessUrl();
                $buyerPayload['cta_label'] = 'Lihat Pesanan';
                $buyerPayload['closing'] = 'Penjual akan menghubungi kamu untuk langkah selanjutnya. Simpan email ini sebagai bukti pembelian.';
            }

            $mailer->sendTo((string) $order->buyer_email, new HellomCheckoutStatusMail(
                subjectLine: 'Pembayaran berhasil — ' . (string) $order->product_name,
                payload: $buyerPayload,
            ));
        }

        if ($order->status === LandingPageOrder::STATUS_PAID && $isDigital) {
            $order->forceFill(['status' => LandingPageOrder::STATUS_FULFILLED, 'fulfilled_at' => now()]);
        }
        $order->forceFill(['emails_sent_at' => now()])->save();

        if ($buyerOnly) {
            return;
        }

        // ── Seller notification (owners/admins of the organization) ──
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
        $details = [
            'No. pesanan' => (string) $order->reference_id,
            'Produk' => (string) $order->product_name . ((int) $order->quantity > 1 ? ' × ' . (int) $order->quantity : ''),
            'Pembeli' => (string) ($order->buyer_name ?? '-'),
            'Email pembeli' => (string) ($order->buyer_email ?? '-'),
        ];
        if ($order->buyer_phone) {
            $details['WhatsApp pembeli'] = (string) $order->buyer_phone;
        }
        if (is_array($order->shipping_address)) {
            $a = $order->shipping_address;
            $details['Kirim ke'] = trim(($a['recipient_name'] ?? '') . ' (' . ($a['phone'] ?? '') . '), ' . ($a['address'] ?? '') . ', ' . ($a['city'] ?? '') . ' ' . ($a['postal_code'] ?? ''));
        }
        foreach ((array) $order->custom_fields as $field) {
            $details[(string) ($field['label'] ?? 'Catatan')] = Str::limit((string) ($field['value'] ?? ''), 300);
        }
        $details += [
            'Dibayar pembeli' => $fmt((int) $order->amount),
            'Biaya layanan Hellom' => $fmt((int) $order->commission_amount),
            'Masuk saldo (bersih)' => $fmt((int) $order->net_amount),
            'Status saldo' => $available
                ? 'Tersedia — sudah bisa ditarik'
                : 'Tertahan sampai ' . $order->settlement_eta->timezone('Asia/Jakarta')->format('d M Y H:i'),
            'Tanggal' => $paidAt,
        ];
        $needsAction = in_array((string) $order->product_kind, [LandingProduct::TYPE_PHYSICAL, LandingProduct::TYPE_SERVICE], true);
        $sellerPayload = [
            'headline' => 'Ada penjualan baru 💰',
            'intro' => 'Produk "' . (string) $order->product_name . '" baru saja terjual di halaman Hellom kamu.'
                . ($needsAction ? ' Pesanan ini perlu kamu proses (kirim barang / hubungi pembeli).' : ' Akses produk sudah otomatis dikirim ke pembeli.'),
            'details' => $details,
            'cta_url' => FrontendUrl::to('/dashboard/apps/landing-builder?tab=pesanan'),
            'cta_label' => 'Buka Pesanan',
        ];

        foreach ($recipients as $email) {
            $mailer->sendTo((string) $email, new HellomCheckoutStatusMail(
                subjectLine: 'Penjualan baru — ' . (string) $order->product_name,
                payload: $sellerPayload,
            ));
        }
    }
}
