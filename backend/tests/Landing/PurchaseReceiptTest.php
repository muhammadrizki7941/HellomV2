<?php

namespace Tests\Landing;

use App\Jobs\SendProductReceipt;
use App\Mail\HellomCheckoutStatusMail;
use App\Models\DigitalProduct;
use App\Models\ProductPurchase;
use App\Models\User;
use App\Services\Hellom\PlatformMailService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\Pos\PosTestCase;
use Tests\Support\NoopPlatformMailService;

class PurchaseReceiptTest extends PosTestCase
{
    use DatabaseTransactions;

    public function test_failed_delivery_can_be_retried_without_losing_the_receipt(): void
    {
        $mail = new class extends NoopPlatformMailService {
            public bool $failSend = true;
            public function sendTo(string|array $to, \Illuminate\Contracts\Mail\Mailable $mailable): array
            {
                return $this->failSend ? ['sent' => false, 'mailer' => 'smtp', 'error' => 'Temporary failure'] : parent::sendTo($to, $mailable);
            }
        };
        $this->app->instance(PlatformMailService::class, $mail);
        $user = User::create(['name' => 'Buyer', 'email' => Str::random(12).'@example.test', 'password' => bcrypt('secret'), 'role' => 'member']);
        $product = DigitalProduct::create(['slug' => 'retry-'.Str::random(8), 'name' => 'Panduan', 'category' => 'ebook', 'type' => 'paid', 'price' => 48000, 'currency' => 'IDR', 'is_published' => true]);
        $purchase = ProductPurchase::withoutEvents(fn () => ProductPurchase::create(['user_id' => $user->id, 'product_id' => $product->id, 'transaction_code' => 'PUR-'.Str::random(10), 'amount_paid' => 48000, 'payment_status' => 'paid', 'payment_gateway' => 'ipaymu']));
        $job = new SendProductReceipt($purchase->id);
        $mailer = app(\App\Services\DigitalProducts\ProductAccessMailer::class);
        try {
            $job->handle($mailer);
            $this->fail('Queue should retry failed mail');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('dicoba ulang', $exception->getMessage());
        }
        $this->assertNull($purchase->fresh()->access_email_sent_at);
        $mail->failSend = false;
        $job->handle($mailer);
        $job->handle($mailer);
        $this->assertCount(1, $mail->sent);
        $this->assertNotNull($purchase->fresh()->access_email_sent_at);
    }

    public function test_logged_in_buyer_gets_one_receipt_only_after_payment_and_keeps_password(): void
    {
        $mail = new NoopPlatformMailService();
        $this->app->instance(PlatformMailService::class, $mail);
        $user = User::create(['name' => 'Pembeli', 'email' => Str::random(12).'@example.test', 'password' => bcrypt('secret'), 'role' => 'member']);
        $password = $user->password;
        $product = DigitalProduct::create(['slug' => 'receipt-'.Str::random(8), 'name' => 'Panduan Usaha', 'category' => 'ebook', 'type' => 'paid', 'price' => 48000, 'currency' => 'IDR', 'is_published' => true]);
        $purchase = ProductPurchase::create(['user_id' => $user->id, 'product_id' => $product->id, 'transaction_code' => 'PUR-'.Str::random(10), 'amount_paid' => 48000, 'payment_status' => 'pending', 'payment_gateway' => 'ipaymu']);
        $this->assertCount(0, $mail->sent);
        $purchase->forceFill(['payment_status' => 'paid', 'paid_at' => now()])->save();
        (new SendProductReceipt($purchase->id))->handle(app(\App\Services\DigitalProducts\ProductAccessMailer::class));
        $purchase->forceFill(['download_count' => 1])->save();
        $this->assertCount(1, $mail->sent);
        $receipt = $mail->sent[0]['mailable'];
        $this->assertInstanceOf(HellomCheckoutStatusMail::class, $receipt);
        $html = $receipt->render();
        $this->assertStringContainsString('Rp 48.000', $html);
        $this->assertStringContainsString($purchase->transaction_code, $html);
        $this->assertStringContainsString('/dashboard/products/'.$product->slug, $html);
        $this->assertStringNotContainsString('/auth/magic', $html);
        $this->assertSame($password, $user->fresh()->password);
        $this->assertNotNull($purchase->fresh()->access_email_sent_at);
    }
}
