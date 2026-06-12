<?php

namespace App\Services;

use App\Mail\NewTransactionNotifMail;
use App\Mail\NewUserNotifMail;
use App\Models\CheckoutIntent;
use App\Models\DigitalProduct;
use App\Models\ProductPurchase;
use App\Models\ConsumerNotification;
use App\Models\OwnerNotification;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Hellom\PlatformMailService;
use App\Services\Realtime\RealtimeClient;

class NotificationService
{
    public function __construct(
        private readonly RealtimeClient $realtimeClient,
    ) {
    }

    public function createNewUserNotif(User $user, string $product): OwnerNotification
    {
        $notification = OwnerNotification::create([
            'type' => 'new_user',
            'title' => 'Pendaftar Baru',
            'message' => "User {$user->name} ({$user->email}) mendaftar produk {$product}.",
            'data' => [
                'user_id' => $user->id,
                'user_name' => $user->name,
                'user_email' => $user->email,
                'product' => $product,
                'registered_at' => now()->toISOString(),
            ],
            'action_type' => 'view_user',
            'action_url' => "/admin/users/{$user->id}",
            'action_status' => null,
            'reference_id' => $user->id,
            'reference_type' => 'user',
            'notifiable_id' => $user->id,
            'notifiable_type' => User::class,
        ]);

        // Send email if mail service is ready
        $mailService = app(PlatformMailService::class);
        if ($mailService->isReady()) {
            $ownerEmail = config('app.owner_email', 'admin@hellom.id'); // fallback
            $mailService->sendTo($ownerEmail, new NewUserNotifMail($user, $product));
        }

        $this->emitCreated($notification);

        return $notification;
    }

    public function createSubscriptionRenewalNotif(Subscription $subscription, int $amount, string $renewalType): OwnerNotification
    {
        $organization = $subscription->organization;
        $app = $subscription->app;
        $plan = $subscription->plan;

        $notification = OwnerNotification::create([
            'type' => 'new_transaction',
            'title' => 'Subscription Diperpanjang Otomatis',
            'message' => "Subscription {$app?->name} untuk {$organization?->name} telah diperpanjang {$renewalType} sebesar Rp " . number_format($amount, 0, ',', '.') . ".",
            'data' => [
                'subscription_id' => $subscription->id,
                'organization_id' => $subscription->organization_id,
                'organization_name' => $organization?->name,
                'app_name' => $app?->name,
                'plan_name' => $plan?->name,
                'amount' => $amount,
                'renewal_type' => $renewalType,
                'renewed_at' => now()->toISOString(),
            ],
            'action_type' => 'open_access',
            'action_url' => "/admin/subscriptions/{$subscription->id}/activate",
            'action_status' => 'pending',
            'reference_id' => $subscription->id,
            'reference_type' => 'subscription',
            'notifiable_id' => $subscription->id,
            'notifiable_type' => Subscription::class,
        ]);

        $this->emitCreated($notification);

        return $notification;
    }

    public function createExpiryReminderNotif(Subscription $subscription, int $daysLeft): OwnerNotification
    {
        $user = $subscription->organization->owner ?? $subscription->organization->users()->first(); // asumsi owner atau first user
        $expiredAt = $subscription->ends_at;

        $notification = OwnerNotification::create([
            'type' => 'expiry_reminder',
            'title' => 'Masa Aktif Mendekati Tenggat',
            'message' => "Langganan " . ($subscription->app ? $subscription->app->name : 'App') . " untuk {$subscription->organization->name} akan berakhir dalam {$daysLeft} hari.",
            'data' => [
                'subscription_id' => $subscription->id,
                'organization_id' => $subscription->organization_id,
                'organization_name' => $subscription->organization->name,
                'app_name' => $subscription->app ? $subscription->app->name : 'Unknown App',
                'plan_name' => $subscription->plan ? $subscription->plan->name : 'Unknown Plan',
                'days_left' => $daysLeft,
                'expires_at' => $expiredAt?->toISOString(),
            ],
            'action_type' => 'view_user',
            'action_url' => "/admin/subscriptions/{$subscription->id}",
            'action_status' => null,
            'reference_id' => $subscription->id,
            'reference_type' => 'subscription',
            'notifiable_id' => $subscription->id,
            'notifiable_type' => Subscription::class,
        ]);

        $this->emitCreated($notification);

        return $notification;
    }

    public function createManualConfirmationNotif(CheckoutIntent $checkoutIntent): OwnerNotification
    {
        $organization = $checkoutIntent->organization;
        $user = $checkoutIntent->user;
        $app = $checkoutIntent->app;
        $plan = $checkoutIntent->plan;

        $notification = OwnerNotification::create([
            'type' => 'new_transaction',
            'title' => 'Konfirmasi Pembayaran Manual',
            'message' => "Pembayaran manual untuk {$app?->name} - {$plan?->name} oleh {$user?->name} ({$user?->email}) sebesar Rp " . number_format($checkoutIntent->amount, 0, ',', '.') . " menunggu konfirmasi.",
            'data' => [
                'checkout_intent_id' => $checkoutIntent->id,
                'subscription_id' => $checkoutIntent->subscription_id,
                'organization_id' => $checkoutIntent->organization_id,
                'organization_name' => $organization?->name,
                'user_id' => $checkoutIntent->user_id,
                'user_name' => $user?->name,
                'user_email' => $user?->email,
                'app_name' => $app?->name,
                'plan_name' => $plan?->name,
                'amount' => $checkoutIntent->amount,
                'manual_payment_method' => data_get($checkoutIntent->metadata, 'manual_payment_method'),
                'checkout_mode' => 'manual_confirmation',
            ],
            'action_type' => 'verify_payment',
            'action_url' => "/admin/transactions/{$checkoutIntent->id}",
            'action_status' => 'pending',
            'reference_id' => $checkoutIntent->id,
            'reference_type' => 'checkout_intent',
            'notifiable_id' => $checkoutIntent->id,
            'notifiable_type' => CheckoutIntent::class,
        ]);

        $this->emitCreated($notification);

        return $notification;
    }

    public function createGatewayPaymentSuccessNotif(CheckoutIntent $checkoutIntent, string $provider): OwnerNotification
    {
        $organization = $checkoutIntent->organization;
        $user = $checkoutIntent->user;
        $app = $checkoutIntent->app;
        $plan = $checkoutIntent->plan;

        $notification = OwnerNotification::create([
            'type' => 'new_transaction',
            'title' => 'Pembayaran Gateway Berhasil',
            'message' => "Pembayaran {$provider} untuk {$app?->name} - {$plan?->name} oleh {$user?->name} ({$user?->email}) sebesar Rp " . number_format($checkoutIntent->amount, 0, ',', '.') . " berhasil diterima.",
            'data' => [
                'checkout_intent_id' => $checkoutIntent->id,
                'subscription_id' => $checkoutIntent->subscription_id,
                'organization_id' => $checkoutIntent->organization_id,
                'organization_name' => $organization?->name,
                'user_id' => $checkoutIntent->user_id,
                'user_name' => $user?->name,
                'user_email' => $user?->email,
                'app_name' => $app?->name,
                'plan_name' => $plan?->name,
                'amount' => $checkoutIntent->amount,
                'gateway_provider' => $provider,
                'checkout_mode' => 'gateway_automatic',
                'payment_status' => 'paid',
            ],
            'action_type' => 'open_access',
            'action_url' => "/admin/subscriptions/{$checkoutIntent->subscription_id}/activate",
            'action_status' => 'pending',
            'reference_id' => $checkoutIntent->id,
            'reference_type' => 'checkout_intent',
            'notifiable_id' => $checkoutIntent->id,
            'notifiable_type' => CheckoutIntent::class,
        ]);

        $this->emitCreated($notification);

        return $notification;
    }

    public function notifyConsumerPaymentSuccess(User $user, mixed $transaction, string $productName): ConsumerNotification
    {
        $transactionId = (int) (data_get($transaction, 'id') ?? 0);
        $amount = $this->resolveAmount($transaction);
        $isDigitalProductPurchase = $transaction instanceof ProductPurchase;
        $productSlug = (string) data_get($transaction, 'product.slug', '');

        return ConsumerNotification::create([
            'user_id' => $user->id,
            'type' => 'transaction',
            'title' => $isDigitalProductPurchase ? 'Pembelian Produk Berhasil' : 'Pembayaran Berhasil',
            'body' => $isDigitalProductPurchase
                ? "Selamat, pembelian {$productName} berhasil. Silakan cek produk kamu dan pelajari cara menggunakannya."
                : 'Pembayaran Rp' . number_format($amount, 0, ',', '.') . " untuk {$productName} telah dikonfirmasi.",
            'data' => [
                'product_name' => $productName,
                'transaction_id' => $transactionId,
                'amount' => $amount,
                'product_slug' => $productSlug !== '' ? $productSlug : null,
            ],
            'action_type' => $isDigitalProductPurchase ? 'view_product_purchase' : 'view_transaction',
            'action_url' => $isDigitalProductPurchase
                ? ($productSlug !== '' ? "/dashboard/products/{$productSlug}/checkout" : '/dashboard/my-purchases')
                : "/dashboard/billing/{$transactionId}",
        ]);
    }

    public function notifyConsumerPaymentPending(User $user, mixed $transaction, string $productName): ConsumerNotification
    {
        $transactionId = (int) (data_get($transaction, 'id') ?? 0);
        $amount = $this->resolveAmount($transaction);
        $isDigitalProductPurchase = $transaction instanceof ProductPurchase;
        $productSlug = (string) data_get($transaction, 'product.slug', '');
        $isManual = (string) data_get($transaction, 'payment_gateway', '') === 'manual';

        return ConsumerNotification::create([
            'user_id' => $user->id,
            'type' => 'transaction',
            'title' => $isManual ? 'Menunggu Konfirmasi Manual' : 'Menunggu Konfirmasi Pembayaran',
            'body' => $isDigitalProductPurchase
                ? ($isManual
                    ? "Pembayaran manual untuk {$productName} sudah tercatat. Kirim konfirmasi ke owner lalu tunggu approval super admin."
                    : "Pembayaran {$productName} sedang diproses gateway. Akses produk akan dibuka otomatis setelah pembayaran sukses.")
                : 'Pembayaran Rp' . number_format($amount, 0, ',', '.') . " untuk {$productName} sedang diverifikasi.",
            'data' => [
                'product_name' => $productName,
                'transaction_id' => $transactionId,
                'amount' => $amount,
            ],
            'action_type' => $isDigitalProductPurchase ? 'view_product_purchase' : 'view_transaction',
            'action_url' => $isDigitalProductPurchase
                ? ($productSlug !== '' ? "/dashboard/products/{$productSlug}/checkout" : '/dashboard/my-purchases')
                : "/dashboard/billing/{$transactionId}",
        ]);
    }

    public function notifyConsumerPaymentFailed(User $user, mixed $transaction, string $productName): ConsumerNotification
    {
        $amount = $this->resolveAmount($transaction);

        return ConsumerNotification::create([
            'user_id' => $user->id,
            'type' => 'transaction',
            'title' => 'Pembayaran Gagal',
            'body' => 'Pembayaran Rp' . number_format($amount, 0, ',', '.') . " untuk {$productName} gagal. Silakan coba lagi.",
            'data' => [
                'product_name' => $productName,
                'transaction_id' => (int) (data_get($transaction, 'id') ?? 0),
                'amount' => $amount,
            ],
            'action_type' => 'view_billing',
            'action_url' => '/dashboard/billing',
        ]);
    }

    public function notifyConsumerRefundProcessed(User $user, mixed $refund): ConsumerNotification
    {
        $amount = $this->resolveAmount($refund);

        return ConsumerNotification::create([
            'user_id' => $user->id,
            'type' => 'refund',
            'title' => 'Refund Sedang Diproses',
            'body' => 'Permintaan refund Rp' . number_format($amount, 0, ',', '.') . ' sedang diproses. Estimasi 3-7 hari kerja.',
            'data' => [
                'refund_id' => (int) (data_get($refund, 'id') ?? 0),
                'transaction_id' => (int) (data_get($refund, 'transaction_id') ?? 0),
                'amount' => $amount,
            ],
            'action_type' => null,
            'action_url' => null,
        ]);
    }

    public function notifyConsumerRefundDone(User $user, mixed $refund): ConsumerNotification
    {
        $amount = $this->resolveAmount($refund);
        $transactionId = (int) (data_get($refund, 'transaction_id') ?? 0);

        return ConsumerNotification::create([
            'user_id' => $user->id,
            'type' => 'refund',
            'title' => 'Refund Berhasil Dikembalikan',
            'body' => 'Dana Rp' . number_format($amount, 0, ',', '.') . ' telah dikembalikan ke metode pembayaran kamu.',
            'data' => [
                'refund_id' => (int) (data_get($refund, 'id') ?? 0),
                'transaction_id' => $transactionId,
                'amount' => $amount,
            ],
            'action_type' => 'view_transaction',
            'action_url' => "/dashboard/billing/{$transactionId}",
        ]);
    }

    public function notifyConsumerAccessActivated(User $user, ?Subscription $subscription, string $productName): ConsumerNotification
    {
        return ConsumerNotification::create([
            'user_id' => $user->id,
            'type' => 'access',
            'title' => 'Akses Kamu Sudah Aktif!',
            'body' => $subscription instanceof Subscription
                ? "Selamat! Akses ke {$productName} sudah aktif. Mulai gunakan sekarang."
                : "Selamat! Produk {$productName} sudah aktif. Cek produk kamu lalu pelajari cara menggunakannya.",
            'data' => [
                'subscription_id' => $subscription?->id,
                'product_name' => $productName,
            ],
            'action_type' => $subscription instanceof Subscription ? null : 'view_product_purchase',
            'action_url' => $subscription instanceof Subscription ? null : '/dashboard/my-purchases',
        ]);
    }
    
        public function notifyOwnerNewPayment(User $user, ProductPurchase $purchase, DigitalProduct $product): OwnerNotification
        {
            $notification = OwnerNotification::create([
                'type' => 'new_transaction',
                'title' => 'Pembelian Produk Digital Baru',
                'message' => "{$user->name} ({$user->email}) membeli {$product->name} sebesar Rp " . number_format($purchase->amount_paid, 0, ',', '.') . ".",
                'data' => [
                    'purchase_id' => $purchase->id,
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'user_id' => $user->id,
                    'user_name' => $user->name,
                    'user_email' => $user->email,
                    'amount' => $purchase->amount_paid,
                    'payment_status' => $purchase->payment_status,
                    'payment_gateway' => $purchase->payment_gateway,
                ],
                'action_type' => 'view_payment',
                'action_url' => '/admin/products/purchases',
                'action_status' => $purchase->payment_gateway === 'manual' ? 'pending' : 'done',
                'reference_id' => $purchase->id,
                'reference_type' => 'digital_product_purchase',
                'notifiable_id' => $purchase->id,
                'notifiable_type' => ProductPurchase::class,
            ]);

            $this->emitCreated($notification);

            return $notification;
        }

    public function notifyConsumerExpiryWarning(User $user, Subscription $subscription, int $daysLeft, string $productName): ConsumerNotification
    {
        return ConsumerNotification::create([
            'user_id' => $user->id,
            'type' => 'expiry',
            'title' => 'Langganan Hampir Berakhir',
            'body' => "Langganan {$productName} akan berakhir {$daysLeft} hari lagi. Perpanjang sekarang.",
            'data' => [
                'subscription_id' => $subscription->id,
                'product_name' => $productName,
                'days_left' => $daysLeft,
            ],
            'action_type' => 'renew',
            'action_url' => '/dashboard/billing/renew',
        ]);
    }

    public function notifyConsumerMaintenance(User $user, string $scheduledAt, string $duration): ConsumerNotification
    {
        return ConsumerNotification::create([
            'user_id' => $user->id,
            'type' => 'maintenance',
            'title' => 'Jadwal Maintenance Sistem',
            'body' => "Sistem maintenance pada {$scheduledAt} selama ±{$duration} jam.",
            'data' => [
                'scheduled_at' => $scheduledAt,
                'duration' => $duration,
            ],
            'action_type' => null,
            'action_url' => null,
        ]);
    }

    public function notifyConsumerPromo(User $user, string $promoTitle, string $promoBody, ?string $actionUrl = null): ConsumerNotification
    {
        return ConsumerNotification::create([
            'user_id' => $user->id,
            'type' => 'promo',
            'title' => $promoTitle,
            'body' => $promoBody,
            'data' => [
                'promo_title' => $promoTitle,
            ],
            'action_type' => $actionUrl ? 'view_billing' : null,
            'action_url' => $actionUrl,
        ]);
    }

    private function emitCreated(OwnerNotification $notification): void
    {
        $payload = [
            'id' => (int) $notification->id,
            'type' => (string) $notification->type,
            'title' => (string) $notification->title,
            'message' => (string) $notification->message,
            'is_read' => (bool) $notification->is_read,
            'created_at' => $notification->created_at?->toISOString(),
        ];

        $this->realtimeClient->emit('admin.notification.created', $payload);
    }

    private function resolveAmount(mixed $record): int
    {
        return (int) (
            data_get($record, 'amount')
            ?? data_get($record, 'amount_paid')
            ?? data_get($record, 'total_amount')
            ?? data_get($record, 'nominal')
            ?? 0
        );
    }
}
