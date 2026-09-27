<?php

namespace App\Services\Billing;

use App\Mail\HellomBillingNotificationMail;
use App\Mail\HellomCheckoutStatusMail;
use App\Models\CheckoutIntent;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Hellom\ManualPaymentSettingsService;
use App\Services\Hellom\PlatformMailService;

/**
 * Billing emails: checkout started / approved / rejected and subscription activation,
 * sent to the organization's owners/admins and to the buyer.
 */
class CheckoutNotifier
{
    public function __construct(
        private readonly PlatformMailService $platformMailService,
        private readonly ManualPaymentSettingsService $manualPaymentSettings,
    ) {
    }

    public function sendSubscriptionBillingNotification(int $subscriptionId, string $statusLabel): void
    {
        if ($subscriptionId <= 0) {
            return;
        }

        $subscription = Subscription::query()->with(['organization.users', 'app', 'plan'])->find($subscriptionId);
        if (!$subscription instanceof Subscription || !$subscription->organization) {
            return;
        }

        $recipients = $subscription->organization->users
            ->filter(fn ($member) => in_array((string) ($member->pivot->role ?? ''), ['owner', 'admin', 'super_admin'], true))
            ->pluck('email')
            ->filter()
            ->map(fn ($email) => strtolower((string) $email))
            ->unique()
            ->values();

        foreach ($recipients as $email) {
            $this->platformMailService->sendTo($email, new HellomBillingNotificationMail(
                organizationName: (string) $subscription->organization->name,
                appName: (string) ($subscription->app?->name ?? 'Aplikasi'),
                planName: (string) ($subscription->plan?->name ?? 'Plan'),
                statusLabel: $statusLabel,
                amount: (int) $subscription->amount,
                startsAt: $subscription->starts_at,
                endsAt: $subscription->ends_at,
            ));
        }
    }

    public function sendCheckoutStartedNotifications(CheckoutIntent $intent, ?Invoice $invoice, ?string $paymentUrl): void
    {
        $subscription = $intent->subscription?->loadMissing(['organization.users', 'app', 'plan']);
        if (!$subscription instanceof Subscription || !$subscription->organization) {
            return;
        }

        $manualMethods = $this->resolveSelectedManualMethods((string) data_get($intent->metadata, 'manual_payment_method', ''));
        $details = $this->buildCheckoutEmailDetails($intent, $invoice, $paymentUrl);

        foreach ($this->ownerBillingRecipients($subscription) as $email) {
            $this->platformMailService->sendTo($email, new HellomCheckoutStatusMail(
                subjectLine: 'Pembayaran aplikasi baru menunggu tindak lanjut',
                payload: [
                    'headline' => 'Ada checkout aplikasi baru di Hellom',
                    'intro' => 'Seorang pembeli baru saja memulai pembayaran aplikasi. Silakan cek detail di dashboard untuk memantau atau mengonfirmasi pembayaran.',
                    'details' => $details,
                    'closing' => 'Anda bisa membuka akses aplikasi secara manual setelah pembayaran terverifikasi.',
                ]
            ));
        }

        if ($intent->user?->email) {
            $this->platformMailService->sendTo((string) $intent->user->email, new HellomCheckoutStatusMail(
                subjectLine: 'Instruksi pembayaran aplikasi Hellom',
                payload: [
                    'headline' => 'Checkout aplikasi Anda sudah dibuat',
                    'intro' => $paymentUrl
                        ? 'Silakan lanjutkan pembayaran menggunakan link gateway yang sudah disiapkan.'
                        : 'Silakan lakukan pembayaran menggunakan metode manual yang dipilih, lalu tunggu konfirmasi owner.',
                    'details' => $details,
                    'manual_methods' => $manualMethods,
                    'closing' => $paymentUrl ? 'Link pembayaran tersedia di detail di atas.' : 'Setelah owner memverifikasi pembayaran, akses aplikasi akan dibuka.',
                ]
            ));
        }
    }

    public function sendCheckoutDecisionNotifications(CheckoutIntent $intent, bool $approved): void
    {
        $subscription = $intent->subscription?->loadMissing(['organization.users', 'app', 'plan']);
        if (!$subscription instanceof Subscription || !$subscription->organization) {
            return;
        }

        $details = $this->buildCheckoutEmailDetails($intent, null, null);
        $subject = $approved ? 'Pembayaran aplikasi berhasil dikonfirmasi' : 'Pembayaran aplikasi ditolak';
        $headline = $approved ? 'Pembayaran aplikasi sudah dikonfirmasi' : 'Checkout aplikasi belum bisa diproses';
        $intro = $approved
            ? 'Pembayaran manual sudah dikonfirmasi. Akses aplikasi telah dibuka sesuai plan yang dipilih.'
            : 'Owner menolak checkout manual ini. Silakan hubungi owner atau buat pembayaran baru.';

        foreach ($this->ownerBillingRecipients($subscription) as $email) {
            $this->platformMailService->sendTo($email, new HellomCheckoutStatusMail(
                subjectLine: $subject,
                payload: [
                    'headline' => $headline,
                    'intro' => $intro,
                    'details' => $details,
                ]
            ));
        }

        if ($intent->user?->email) {
            $this->platformMailService->sendTo((string) $intent->user->email, new HellomCheckoutStatusMail(
                subjectLine: $subject,
                payload: [
                    'headline' => $headline,
                    'intro' => $intro,
                    'details' => $details,
                ]
            ));
        }
    }

    /**
     * @return array<int,string>
     */
    private function ownerBillingRecipients(Subscription $subscription): array
    {
        return $subscription->organization->users
            ->filter(fn ($member) => in_array((string) ($member->pivot->role ?? ''), ['owner', 'admin', 'super_admin'], true))
            ->pluck('email')
            ->filter()
            ->map(fn ($email) => strtolower((string) $email))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<string,string>
     */
    private function buildCheckoutEmailDetails(CheckoutIntent $intent, ?Invoice $invoice, ?string $paymentUrl): array
    {
        return [
            'Organisasi' => (string) ($intent->organization?->name ?? $intent->subscription?->organization?->name ?? '-'),
            'Aplikasi' => (string) ($intent->app?->name ?? '-'),
            'Plan' => (string) ($intent->plan?->name ?? '-'),
            'Billing cycle' => (string) data_get($intent->metadata, 'billing_cycle', $intent->subscription?->billing_cycle ?? '-'),
            'Nominal' => 'Rp ' . number_format((int) $intent->amount, 0, ',', '.'),
            'Status checkout' => (string) $intent->status,
            'Invoice' => (string) ($invoice?->invoice_number ?? ''),
            'Metode manual' => (string) data_get($intent->metadata, 'manual_payment_method', ''),
            'Payment URL' => (string) ($paymentUrl ?? ''),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function resolveSelectedManualMethods(string $selectedMethod): array
    {
        $options = $this->manualPaymentSettings->publicOptions();
        $methods = collect((array) ($options['methods'] ?? []));

        if ($selectedMethod === '') {
            return $methods->all();
        }

        return $methods
            ->filter(fn (array $method) => (string) ($method['key'] ?? '') === $selectedMethod)
            ->values()
            ->all();
    }
}
