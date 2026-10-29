<?php

namespace App\Jobs;

use App\Models\ProductPurchase;
use App\Services\DigitalProducts\ProductAccessMailer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class SendProductReceipt implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;
    public array $backoff = [60, 300];

    public function __construct(public int $purchaseId) {}

    public function handle(ProductAccessMailer $mailer): void
    {
        $purchase = ProductPurchase::find($this->purchaseId);
        if (!$purchase || $purchase->payment_status !== 'paid') {
            return;
        }
        if (!$mailer->sendForPaidPurchase($purchase) && !$purchase->fresh()?->access_email_sent_at) {
            throw new \RuntimeException('Email pembelian belum terkirim; akan dicoba ulang.');
        }
    }
}
