<?php

namespace App\Services\DigitalProducts;

use App\Mail\DigitalProductAccessMail;
use App\Models\LoginLink;
use App\Models\ProductPurchase;
use App\Services\Hellom\PlatformMailService;
use App\Support\FrontendUrl;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Emails a guest buyer their access once the purchase is paid: a single-use sign-in
 * link that opens the product in the dashboard, and a password when the account was
 * created by the guest checkout. Existing accounts never get their password changed.
 */
class ProductAccessMailer
{
    public function __construct(
        private readonly PlatformMailService $mail,
    ) {
    }

    /**
     * Called when a purchase becomes paid. Sends at most once per purchase
     * (webhook retries and double confirmations are ignored).
     */
    public function sendForPaidPurchase(ProductPurchase $purchase): bool
    {
        if (!$purchase->isGuestCheckout() || $purchase->payment_status !== 'paid') {
            return false;
        }

        $claimed = ProductPurchase::query()
            ->whereKey($purchase->id)
            ->whereNull('access_email_sent_at')
            ->update(['access_email_sent_at' => now()]);

        if ($claimed !== 1) {
            return false;
        }

        return $this->deliver($purchase, releaseClaimOnFailure: true);
    }

    /**
     * Buyer asked for the email again from the checkout status page.
     */
    public function resend(ProductPurchase $purchase): bool
    {
        if (!$purchase->isGuestCheckout() || !$purchase->hasAccess()) {
            return false;
        }

        return $this->deliver($purchase, releaseClaimOnFailure: false);
    }

    private function deliver(ProductPurchase $purchase, bool $releaseClaimOnFailure): bool
    {
        $purchase->loadMissing(['user', 'product']);
        $user = $purchase->user;
        $product = $purchase->product;
        if (!$user || !$product) {
            return false;
        }

        $password = null;
        if ($user->pending_guest_credentials) {
            $password = Str::password(12, symbols: false);
            $user->forceFill([
                'password' => Hash::make($password),
                'pending_guest_credentials' => false,
            ])->save();
        }

        [$token] = LoginLink::issue($user, "/dashboard/products/{$product->slug}");
        $result = $this->mail->sendTo((string) $user->email, new DigitalProductAccessMail(
            productName: (string) $product->name,
            transactionCode: (string) $purchase->transaction_code,
            amount: (int) $purchase->amount_paid,
            email: (string) $user->email,
            accessUrl: FrontendUrl::to('/auth/magic?token=' . $token),
            linkValidDays: LoginLink::TTL_DAYS,
            password: $password,
            loginUrl: FrontendUrl::to('/login'),
            accessPeriod: $purchase->expires_at
                ? 'Sampai ' . $purchase->expires_at->translatedFormat('d M Y')
                : 'Selamanya (sekali beli)',
        ));

        if ($result['sent'] ?? false) {
            return true;
        }

        Log::warning('Digital product access email failed', [
            'purchase_id' => $purchase->id,
            'error' => $result['error'] ?? null,
        ]);

        // The buyer never saw this password; issue a fresh one on the next attempt.
        if ($password !== null) {
            $user->forceFill(['pending_guest_credentials' => true])->save();
        }
        if ($releaseClaimOnFailure) {
            ProductPurchase::query()->whereKey($purchase->id)->update(['access_email_sent_at' => null]);
        }

        return false;
    }
}
