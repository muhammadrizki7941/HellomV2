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
            $this->platformMailService->queue($email, new HellomBillingNotificationMail(
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

    public function sendCheckoutStartedNotifications(CheckoutIntent $intent, ?Invoice $invoice, ?string $paymentUrl, ?string $paymentStatusUrl = null): void
    {
        $subscription = $intent->subscription?->loadMissing(['organization.users', 'app', 'plan']);
        if (!$subscription instanceof Subscription || !$subscription->organization) {
            return;
        }

        $manualMethods = $this->resolveSelectedManualMethods((string) data_get($intent->metadata, 'manual_payment_method', ''));
        $gatewayInstructions = data_get($intent->metadata, 'ipaymu.payment_instructions');
        $gatewayInstructions = is_array($gatewayInstructions) ? $gatewayInstructions : [];
        $details = $this->buildCheckoutEmailDetails($intent, $invoice);
        $statusUrl = \App\Support\FrontendUrl::to(
            '/dashboard/payments?checkout=' . rawurlencode((string) $intent->app?->slug)
        );
        $buyerStatusUrl = $paymentStatusUrl ?: $statusUrl;

        foreach ($this->ownerBillingRecipients($subscription) as $email) {
            $this->platformMailService->queue($email, new HellomCheckoutStatusMail(
                subjectLine: 'Pembayaran aplikasi baru menunggu tindak lanjut',
                payload: [
                    'headline' => 'Ada checkout aplikasi baru di Hellom',
                    'intro' => 'Seorang pembeli baru saja memulai pembayaran aplikasi. Silakan cek detail di dashboard untuk memantau atau mengonfirmasi pembayaran.',
                    'details' => $details,
                    'cta_url' => $statusUrl,
                    'cta_label' => 'Cek status pembayaran',
                    'status_url' => $statusUrl,
                    'status_label' => 'Cek status pembayaran',
                    'closing' => 'Anda bisa membuka akses aplikasi secara manual setelah pembayaran terverifikasi.',
                ]
            ));
        }

        if ($intent->user?->email) {
            $this->platformMailService->queue((string) $intent->user->email, new HellomCheckoutStatusMail(
                subjectLine: 'Instruksi pembayaran aplikasi Hellom',
                payload: [
                    'headline' => 'Checkout aplikasi Anda sudah dibuat',
                    'intro' => $paymentUrl
                        ? 'Silakan lanjutkan pembayaran melalui gateway menggunakan tombol di bawah. Pembayaran harus diselesaikan sebelum batas waktu.'
                        : 'Ikuti instruksi metode pembayaran di bawah ini. Pembayaran harus diselesaikan sebelum batas waktu, lalu statusnya bisa dipantau melalui tautan berikut.',
                    'details' => $details,
                    'manual_methods' => $manualMethods,
                    'gateway_instructions' => $gatewayInstructions,
                    'cta_url' => $paymentUrl ?: $buyerStatusUrl,
                    'cta_label' => $paymentUrl ? 'Bayar sekarang' : 'Cek status pembayaran',
                    'status_url' => $buyerStatusUrl,
                    'status_label' => 'Cek status pembayaran',
                    'closing' => $paymentUrl
                        ? 'Gunakan tombol “Bayar sekarang” untuk membuka halaman pembayaran. Setelah membayar, tombol “Cek status pembayaran” akan membuka halaman status Hellom.'
                        : 'Status pembayaran dan konfirmasi dapat dipantau melalui tautan status Hellom. Setelah pembayaran terverifikasi, akses aplikasi akan dibuka.',
                ]
            ));
        }
    }

    public function sendCheckoutDecisionNotifications(CheckoutIntent $intent, bool $approved): void
    {
        if ($approved) {
            $this->sendPaymentReceipt($intent);
            return;
        }
        $subscription = $intent->subscription?->loadMissing(['organization.users', 'app', 'plan']);
        if (!$subscription instanceof Subscription || !$subscription->organization) {
            return;
        }

        $details = $this->buildCheckoutEmailDetails($intent, null);
        $subject = $approved ? 'Pembayaran aplikasi berhasil dikonfirmasi' : 'Pembayaran aplikasi ditolak';
        $headline = $approved ? 'Pembayaran aplikasi sudah dikonfirmasi' : 'Checkout aplikasi belum bisa diproses';
        $intro = $approved
            ? 'Pembayaran manual sudah dikonfirmasi. Akses aplikasi telah dibuka sesuai plan yang dipilih.'
            : 'Owner menolak checkout manual ini. Silakan hubungi owner atau buat pembayaran baru.';

        foreach ($this->ownerBillingRecipients($subscription) as $email) {
            $this->platformMailService->queue($email, new HellomCheckoutStatusMail(
                subjectLine: $subject,
                payload: [
                    'headline' => $headline,
                    'intro' => $intro,
                    'details' => $details,
                ]
            ));
        }

        if ($intent->user?->email) {
            $this->platformMailService->queue((string) $intent->user->email, new HellomCheckoutStatusMail(
                subjectLine: $subject,
                payload: [
                    'headline' => $headline,
                    'intro' => $intro,
                    'details' => $details,
                ]
            ));
        }
    }

    /** One receipt per distinct buyer/organization recipient, also used by reconciliation. */
    public function sendPaymentReceipt(CheckoutIntent $intent): void
    {
        $intent->loadMissing(['subscription.organization.users', 'app', 'plan', 'user']);
        $subscription = $intent->subscription;
        $recipients = $subscription?->organization ? $this->ownerBillingRecipients($subscription) : [];
        $recipients[] = (string) $intent->user?->email;
        $recipients = array_unique(array_filter(array_map(fn ($email) => strtolower(trim($email)), $recipients)));
        foreach ($recipients as $email) {
            $this->platformMailService->queue($email, new HellomCheckoutStatusMail(
                'Pembayaran berhasil — ' . ($intent->app?->name ?? 'Aplikasi Hellom'),
                [
                    'headline' => 'Terima kasih, aplikasi Anda sudah aktif',
                    'intro' => 'Pembayaran telah diterima. Silakan buka dashboard untuk mulai menggunakan paket yang Anda pilih.',
                    'details' => [
                        'Aplikasi' => (string) ($intent->app?->name ?? 'Aplikasi'),
                        'Paket' => (string) ($intent->plan?->name ?? '-'),
                        'Nomor transaksi' => (string) $intent->intent_token,
                        'Total pembayaran' => 'Rp ' . number_format((int) $intent->amount, 0, ',', '.'),
                        'Masa akses' => $subscription?->ends_at?->timezone('Asia/Jakarta')->format('d M Y') ?? 'Tanpa batas waktu',
                    ],
                    'cta_url' => \App\Support\FrontendUrl::to('/dashboard'),
                    'cta_label' => 'Buka aplikasi saya',
                    'closing' => 'Mulai dengan melengkapi profil usaha dan pengaturan aplikasi. Untuk POS Kasir, tambahkan produk dan periksa harga sebelum mencatat transaksi pertama. Simpan email ini sebagai bukti pembayaran. Butuh bantuan? Balas email ini.',
                ],
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
    private function buildCheckoutEmailDetails(CheckoutIntent $intent, ?Invoice $invoice): array
    {
        $statusLabel = match ((string) $intent->status) {
            'confirmed', 'paid' => 'Pembayaran berhasil',
            'gateway_pending', 'pending_payment' => 'Menunggu pembayaran',
            'manual_review' => 'Menunggu konfirmasi pembayaran',
            'expired' => 'Kedaluwarsa',
            'cancelled' => 'Dibatalkan',
            default => (string) $intent->status,
        };
        $details = [
            'Organisasi' => (string) ($intent->organization?->name ?? $intent->subscription?->organization?->name ?? '-'),
            'Aplikasi' => (string) ($intent->app?->name ?? '-'),
            'Plan' => (string) ($intent->plan?->name ?? '-'),
            'Billing cycle' => (string) data_get($intent->metadata, 'billing_cycle', $intent->subscription?->billing_cycle ?? '-'),
            'Nominal' => 'Rp ' . number_format((int) $intent->amount, 0, ',', '.'),
            'Status checkout' => $statusLabel,
            'Invoice' => (string) ($invoice?->invoice_number ?? ''),
            'Referensi pembayaran' => (string) $intent->intent_token,
        ];

        $method = data_get($intent->metadata, 'ipaymu.payment_instructions.channel_label')
            ?: data_get($intent->metadata, 'manual_payment_method');
        if ($method) {
            $details['Metode pembayaran'] = (string) $method;
        }
        if ($intent->expires_at) {
            $details['Batas pembayaran'] = $intent->expires_at->timezone('Asia/Jakarta')->format('d M Y, H:i') . ' WIB';
        }

        return $details;
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
