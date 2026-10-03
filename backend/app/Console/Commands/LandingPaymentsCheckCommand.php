<?php

namespace App\Console\Commands;

use App\Models\LandingPageOrder;
use App\Models\LandingProduct;
use App\Services\Hellom\IpaymuSettingsService;
use App\Services\Hellom\PaymentGatewaySettingsService;
use App\Services\Landing\PaymentStarter;
use App\Services\Payments\GatewayRegistry;
use Illuminate\Console\Command;

/**
 * Read-only check of why Hellom Page buyers cannot pay: gateway readiness, which
 * products are sellable, and the latest orders with the gateway's own error message.
 * Prints no buyer data and no credentials.
 */
class LandingPaymentsCheckCommand extends Command
{
    protected $signature = 'landing:payments-check {--orders=15 : how many recent orders to show}';

    protected $description = 'Diagnose Hellom Page checkout: gateway, sellable products, recent orders and their payment errors';

    public function handle(GatewayRegistry $gateways, PaymentStarter $starter): int
    {
        $runtime = app(PaymentGatewaySettingsService::class)->getRuntimeConfig();
        $gateway = $gateways->active();
        $this->info('Gateway');
        $rows = [
            ['Gateway aktif', $gateway->name()],
            ['Siap (kredensial lengkap)', $gateway->isReady() ? 'ya' : 'TIDAK'],
            ['Opsi bayar di checkout', implode(', ', $starter->options()) ?: '(kosong → pembeli melihat "Pembayaran sedang tidak tersedia")'],
            ['Mode checkout langganan', (string) ($runtime['checkout_mode'] ?? '-')],
        ];
        if ($gateway->name() === 'ipaymu') {
            $config = app(IpaymuSettingsService::class)->getConfig();
            $rows[] = ['iPaymu mode', $config['is_production'] ? 'production' : 'SANDBOX'];
            $rows[] = ['Callback token', ($config['callback_token'] ?? '') !== '' ? 'ada' : 'TIDAK ADA (notifikasi ditolak)'];
            $rows[] = ['Metode aktif', implode(', ', app(IpaymuSettingsService::class)->enabledPaymentMethods())];
        }
        $this->table(['Cek', 'Hasil'], $rows);

        $this->info('Produk Hellom Page');
        $products = LandingProduct::query()->get();
        $reasons = ['bisa dibeli' => 0, 'disembunyikan penjual' => 0, 'dinonaktifkan Hellom' => 0, 'link/file belum diisi' => 0, 'stok habis' => 0];
        foreach ($products as $product) {
            $reason = match (true) {
                $product->admin_disabled_at !== null => 'dinonaktifkan Hellom',
                !$product->is_active => 'disembunyikan penjual',
                !$product->isDeliverable() => 'link/file belum diisi',
                $product->stock !== null && $product->stock < 1 => 'stok habis',
                default => 'bisa dibeli',
            };
            $reasons[$reason]++;
        }
        $this->table(['Status', 'Jumlah'], collect($reasons)->map(fn ($count, $label) => [$label, $count])->values()->all());

        $this->info('Pesanan terakhir');
        $orders = LandingPageOrder::query()->orderByDesc('id')->limit(max(1, (int) $this->option('orders')))->get();
        $this->table(
            ['Ref', 'Status', 'Gateway', 'Nominal', 'Dibuat', 'Alasan gagal dari gateway'],
            $orders->map(fn (LandingPageOrder $order) => [
                $order->reference_id,
                $order->status,
                $order->provider ?: '-',
                number_format((int) $order->amount, 0, ',', '.'),
                optional($order->created_at)->format('d M H:i'),
                (string) data_get($order->metadata, 'payment_error', ''),
            ])->all()
        );
        $this->line('Pesanan "failed" tanpa alasan = dibuat sebelum versi ini; lihat storage/logs/laravel.log (cari "iPaymu").');

        return self::SUCCESS;
    }
}
