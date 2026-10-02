<?php

namespace Tests\Admin;

use App\Models\DigitalProduct;
use App\Models\ProductPurchase;
use Illuminate\Support\Str;

/** P1-9: manual confirmation counts once; only a paid purchase can be refunded. */
class ProductPurchaseAdminTest extends AdminTestCase
{
    private function purchase(string $status, string $gateway = 'manual'): ProductPurchase
    {
        $buyer = $this->makeUser('member');
        $product = DigitalProduct::query()->create(['slug' => 'tpl-' . Str::lower(Str::random(6)), 'name' => 'Template', 'category' => 'template', 'type' => 'paid', 'price' => 50000, 'currency' => 'IDR', 'is_published' => true]);

        return ProductPurchase::query()->create(['user_id' => $buyer->id, 'product_id' => $product->id, 'transaction_code' => 'PUR-' . Str::upper(Str::random(10)),
            'amount_paid' => 50000, 'payment_method' => 'bank_transfer', 'payment_status' => $status, 'payment_gateway' => $gateway]);
    }

    public function test_manual_confirmation_counts_the_purchase_once(): void
    {
        $purchase = $this->purchase('pending');
        $admin = $this->superAdmin();

        $this->api($admin, 'POST', "/admin/product-purchases/{$purchase->id}/approve")->assertOk()->assertJsonPath('message', 'Pembelian dikonfirmasi');
        $this->api($admin, 'POST', "/admin/product-purchases/{$purchase->id}/approve")->assertOk()->assertJsonPath('message', 'Pembelian ini sudah dikonfirmasi');

        $this->assertSame('paid', $purchase->fresh()->payment_status);
        $this->assertSame(1, (int) $purchase->product->fresh()->total_purchases);
    }

    public function test_only_paid_purchases_can_be_refunded(): void
    {
        $pending = $this->purchase('pending');
        $paid = $this->purchase('paid', 'ipaymu');
        $admin = $this->superAdmin();

        $this->api($admin, 'POST', "/admin/product-purchases/{$pending->id}/refund")->assertStatus(422)->assertJsonPath('error.code', 'PURCHASE_NOT_REFUNDABLE');
        $this->assertSame('pending', $pending->fresh()->payment_status);

        $this->api($admin, 'POST', "/admin/product-purchases/{$paid->id}/refund")->assertOk();
        $this->assertSame('refunded', $paid->fresh()->payment_status);
        $this->api($admin, 'POST', "/admin/product-purchases/{$paid->id}/refund")->assertStatus(422);
    }
}
