<?php

namespace App\Services\Notifications;

use App\Mail\HellomCheckoutStatusMail;
use App\Mail\HellomWelcomeMail;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Hellom\PlatformMailService;
use App\Support\FrontendUrl;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/**
 * The standard SaaS account emails, all through PlatformMailService (queued, logged):
 *
 *   welcome()               on sign-up: first steps + verify button (transactional)
 *   verificationReminder()  24 h after sign-up, still unverified, once (users:lifecycle-emails)
 *   passwordChanged()       after a change or reset — "bukan kamu?" (security, transactional)
 *   dayTwoTips()            day 2, shop page not published yet, once (info & tips → unsubscribe)
 *   subscriptionReminder()  7 / 3 / 1 days before a paid plan ends (shop owners + bell)
 *   subscriptionExpired()   when a plan has ended (shop owners + bell)
 *
 * One-time emails are remembered in users.lifecycle_emails.
 */
class LifecycleEmails
{
    public function __construct(
        private readonly PlatformMailService $mail,
        private readonly Notifier $notifier,
    ) {
    }

    /** Signed link (24 h) → marks the email verified (EmailVerificationController::verify). */
    public static function verificationUrl(User $user): string
    {
        return URL::temporarySignedRoute('api.v1.hellom.public.email.verify', now()->addDay(), [
            'id' => $user->id,
            'hash' => sha1(strtolower((string) $user->email)),
        ]);
    }

    public function welcome(User $user, ?string $organizationName = null): void
    {
        $this->mail->queue((string) $user->email, new HellomWelcomeMail(
            name: (string) $user->name,
            organizationName: $organizationName,
            verifyUrl: $user->email_verified_at === null ? self::verificationUrl($user) : null,
        ));
    }

    public function verificationReminder(User $user): void
    {
        $this->mail->queue((string) $user->email, new HellomCheckoutStatusMail('Satu langkah lagi: verifikasi email Hellom kamu', [
            'headline' => 'Verifikasi email kamu',
            'intro' => 'Halo ' . $user->name . ', email akun Hellom kamu belum diverifikasi. Verifikasi diperlukan untuk menarik saldo penjualan, '
                . 'menerima notifikasi penting, dan memulihkan akun kalau lupa kata sandi.',
            'cta_url' => self::verificationUrl($user),
            'cta_label' => 'Verifikasi Email',
            'closing' => 'Tautan berlaku 24 jam. Bukan kamu yang mendaftar? Abaikan email ini.',
        ]));
        $this->remember($user, 'verify_reminder');
    }

    public function passwordChanged(User $user, string $via, ?string $ip = null): void
    {
        $this->mail->queue((string) $user->email, new HellomCheckoutStatusMail('Kata sandi akun Hellom kamu diganti', [
            'headline' => 'Kata sandi kamu baru saja diganti',
            'intro' => 'Kata sandi akun Hellom (' . $user->email . ') ' . match ($via) {
                'reset' => 'diatur ulang lewat tautan lupa kata sandi',
                'setup' => 'dibuat lewat tautan "Buat kata sandi sendiri" di email akses produk — kata sandi bawaan dari email sudah tidak berlaku',
                default => 'diganti dari pengaturan akun',
            } . '.',
            'details' => array_filter([
                'Waktu' => now()->timezone('Asia/Jakarta')->translatedFormat('d M Y, H:i') . ' WIB',
                'Alamat IP' => $ip,
            ]),
            'cta_url' => FrontendUrl::to('/forgot-password'),
            'cta_label' => 'Bukan saya — amankan akun',
            'closing' => 'Kalau ini memang kamu, abaikan email ini. Kalau bukan, segera atur ulang kata sandi lewat tombol di atas dan hubungi kami.',
        ]));
    }

    public function dayTwoTips(User $user): void
    {
        $tips = new HellomCheckoutStatusMail('3 langkah pertama di Hellom', [
            'headline' => 'Mulai jualan dalam 10 menit',
            'intro' => 'Halo ' . $user->name . ', ini tiga langkah yang paling sering dipakai pemilik usaha di minggu pertama:',
            'details' => [
                '1. Hellom Page' => 'Pilih template, isi link & produk, lalu terbitkan halaman toko kamu.',
                '2. Kalkulator HPP' => 'Hitung modal per menu dan dapatkan saran harga jual (gratis).',
                '3. POS Kasir' => 'Catat pesanan, meja, dan kas harian dari HP atau tablet.',
            ],
            'cta_url' => FrontendUrl::to('/dashboard/apps/landing-builder'),
            'cta_label' => 'Buat Hellom Page',
            'closing' => 'Butuh bantuan? Balas email ini — tim kami membaca setiap balasan.',
        ]);
        $tips->mailCategory = PlatformMailService::CATEGORY_MARKETING;
        $this->mail->queue((string) $user->email, $tips);
        $this->remember($user, 'tips_day2');
    }

    public function subscriptionReminder(Subscription $subscription, int $daysLeft): void
    {
        $subscription->loadMissing(['organization', 'app', 'plan']);
        $app = (string) ($subscription->app?->name ?? 'Aplikasi');
        $endsAt = $subscription->ends_at?->timezone('Asia/Jakarta')->translatedFormat('d M Y');
        $autoRenew = (string) $subscription->billing_cycle === 'monthly' && (bool) data_get($subscription->metadata, 'wallet_auto_renew', true);
        $when = $daysLeft === 1 ? 'besok' : "{$daysLeft} hari lagi";

        $this->toShop($subscription, "Langganan {$app} berakhir {$when}", [
            'headline' => "Langganan {$app} berakhir {$when}",
            'intro' => "Paket {$subscription->plan?->name} untuk {$subscription->organization?->name} aktif sampai {$endsAt}."
                . ($autoRenew ? ' Paket ini diperpanjang otomatis dari saldo wallet — pastikan saldonya cukup.' : ' Perpanjang sebelum tanggal itu supaya usaha kamu tidak terhenti.'),
            'cta_url' => FrontendUrl::to('/dashboard/billing'),
            'cta_label' => $autoRenew ? 'Cek saldo & tagihan' : 'Perpanjang sekarang',
            'closing' => 'Data kamu tetap aman walau langganan berakhir.',
        ], 'expiry', "Langganan {$app} berakhir {$when}", "Aktif sampai {$endsAt}." . ($autoRenew ? ' Diperpanjang otomatis dari saldo wallet.' : ' Perpanjang supaya tidak terhenti.'));
    }

    public function subscriptionExpired(Subscription $subscription): void
    {
        $subscription->loadMissing(['organization', 'app', 'plan']);
        $app = (string) ($subscription->app?->name ?? 'Aplikasi');

        $this->toShop($subscription, "Langganan {$app} sudah berakhir", [
            'headline' => "Langganan {$app} sudah berakhir",
            'intro' => "Paket {$subscription->plan?->name} untuk {$subscription->organization?->name} sudah berakhir, jadi fitur berbayarnya terkunci untuk sementara.",
            'cta_url' => FrontendUrl::to('/dashboard/billing'),
            'cta_label' => 'Aktifkan lagi',
            'closing' => 'Semua data kamu tetap tersimpan dan langsung bisa dipakai lagi setelah langganan aktif.',
        ], 'expiry', "Langganan {$app} sudah berakhir", 'Fitur berbayar terkunci. Aktifkan lagi kapan saja — data kamu tetap aman.');
    }

    /** Email + bell to every owner/admin of the subscription's shop. */
    private function toShop(Subscription $subscription, string $subject, array $payload, string $type, string $title, string $body): void
    {
        $orgId = (int) $subscription->organization_id;
        $emails = DB::table('organization_user')->join('users', 'users.id', '=', 'organization_user.user_id')
            ->where('organization_user.organization_id', $orgId)->whereIn('organization_user.role', ['owner', 'admin'])
            ->pluck('users.email')->filter()->unique()->values()->all();
        foreach ($emails as $email) {
            $this->mail->queue((string) $email, new HellomCheckoutStatusMail($subject, $payload));
        }
        $this->notifier->organization($orgId, $type, $title, $body, '/dashboard/billing', ['subscription_id' => (int) $subscription->id]);
    }

    public static function wasSent(User $user, string $key): bool
    {
        return is_array($user->lifecycle_emails) && isset($user->lifecycle_emails[$key]);
    }

    private function remember(User $user, string $key): void
    {
        $sent = is_array($user->lifecycle_emails) ? $user->lifecycle_emails : [];
        $sent[$key] = now()->toISOString();
        $user->forceFill(['lifecycle_emails' => $sent])->saveQuietly();
    }
}
