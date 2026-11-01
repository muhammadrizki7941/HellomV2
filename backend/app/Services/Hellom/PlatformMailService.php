<?php

namespace App\Services\Hellom;

use App\Models\EmailLog;
use App\Models\HellomBrandSetting;
use App\Models\SystemSetting;
use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use Throwable;

class PlatformMailService
{
    public const OWNER_EMAIL_SETTING = 'hellom_owner_notification_email';

    /** Receipts, resets, verification, withdrawals: always delivered. */
    public const CATEGORY_TRANSACTIONAL = 'transactional';

    /** Announcements, tips: honour one-click unsubscribe (users.email_preferences.marketing). */
    public const CATEGORY_MARKETING = 'marketing';

    public function getSettings(): array
    {
        $brand = HellomBrandSetting::getSettings();
        $enabled = $this->getBool('hellom_mail_enabled', false);
        $fromAddress = (string) SystemSetting::get('hellom_mail_from_address', $brand->support_email ?: 'hello@example.com');
        $fromName = (string) SystemSetting::get('hellom_mail_from_name', $brand->app_name ?: $brand->business_name ?: 'Hellom');

        return [
            'enabled' => $enabled,
            'resend_configured' => trim((string) config('services.resend.key', '')) !== '',
            'from_address' => $fromAddress,
            'from_name' => $fromName,
            'reply_to_address' => (string) SystemSetting::get('hellom_mail_reply_to_address', $fromAddress),
            'reply_to_name' => (string) SystemSetting::get('hellom_mail_reply_to_name', $fromName),
            'branding_app_name' => $brand->app_name ?: $brand->business_name ?: 'Hellom',
            'branding_support_email' => $brand->support_email,
        ];
    }

    public function publicSettingsSummary(): array
    {
        $settings = $this->getSettings();

        return [
            'enabled' => $settings['enabled'],
            'provider' => 'resend',
            'resend_configured' => $settings['resend_configured'],
            'from_address' => $settings['from_address'],
            'from_name' => $settings['from_name'],
            'reply_to_address' => $settings['reply_to_address'],
            'reply_to_name' => $settings['reply_to_name'],
            'is_ready' => $this->isReady(),
            // The owner's own address(es): every super admin notification is copied there.
            'owner_email' => (string) SystemSetting::get(self::OWNER_EMAIL_SETTING, ''),
            'owner_email_effective' => $this->ownerEmails(),
        ];
    }

    public function saveSettings(array $settings): array
    {
        $settings = $this->normalizeSettings($settings);

        SystemSetting::set('hellom_mail_enabled', ! empty($settings['enabled']) ? '1' : '0');
        SystemSetting::set('hellom_mail_from_address', trim((string) ($settings['from_address'] ?? '')));
        SystemSetting::set('hellom_mail_from_name', trim((string) ($settings['from_name'] ?? '')));
        SystemSetting::set('hellom_mail_reply_to_address', trim((string) ($settings['reply_to_address'] ?? '')));
        SystemSetting::set('hellom_mail_reply_to_name', trim((string) ($settings['reply_to_name'] ?? '')));
        SystemSetting::query()->whereIn('key', [
            'hellom_mail_host',
            'hellom_mail_port',
            'hellom_mail_username',
            'hellom_mail_encryption',
            'hellom_mail_password_encrypted',
        ])->delete();
        if (array_key_exists('owner_email', $settings)) {
            SystemSetting::set(self::OWNER_EMAIL_SETTING, implode(', ', self::parseEmails((string) $settings['owner_email'])));
        }

        return $this->publicSettingsSummary();
    }

    public function normalizeSettings(array $settings): array
    {
        $normalized = $settings;
        foreach (['from_address', 'from_name', 'reply_to_address', 'reply_to_name', 'owner_email'] as $key) {
            if (array_key_exists($key, $settings)) {
                $normalized[$key] = trim((string) $settings[$key]);
            }
        }

        return $normalized;
    }

    /**
     * Where super admin notifications go (withdrawal requests, new users, payments, SLA warnings):
     * the owner's address(es) from Pengaturan › Email, else every super admin account's email.
     *
     * @return list<string>
     */
    public function ownerEmails(): array
    {
        $configured = self::parseEmails((string) SystemSetting::get(self::OWNER_EMAIL_SETTING, ''));
        if ($configured !== []) {
            return $configured;
        }

        return \App\Models\User::query()->where('role', 'super_admin')->pluck('email')
            ->map(fn ($email) => strtolower(trim((string) $email)))->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL))->unique()->values()->all();
    }

    /** "a@x.id, b@y.id" → valid, unique, lower-case addresses (max 5). @return list<string> */
    public static function parseEmails(string $value): array
    {
        $parts = preg_split('/[\s,;]+/', strtolower(trim($value))) ?: [];

        return array_slice(array_values(array_unique(array_filter($parts, fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL) !== false))), 0, 5);
    }

    public function isReady(): bool
    {
        $settings = $this->getSettings();

        if (! $settings['enabled']) {
            return false;
        }

        return $settings['resend_configured']
            && $settings['from_address'] !== '';
    }

    /**
     * Send now. Every email gets the same professional finish (see finish()): plain-text part,
     * Reply-To, Auto-Submitted, List-Unsubscribe for non-transactional mail. Each attempt is
     * written to email_logs. Never throws — use sendOrFail() inside jobs so retries happen.
     *
     * @return array{sent: bool, mailer: string, error: ?string, skipped?: bool}
     */
    public function sendTo(string|array $to, Mailable $mailable): array
    {
        $recipients = array_values(array_unique(array_filter(array_map(fn ($email) => trim((string) $email), (array) $to))));
        $category = self::categoryOf($mailable);
        $meta = ['subject' => ''];

        $unsubscribeUrl = null;
        if ($category === self::CATEGORY_MARKETING) {
            $recipients = array_values(array_filter($recipients, fn (string $email) => ! $this->hasUnsubscribed($email)));
            if ($recipients === []) {
                $this->log((array) $to, $mailable, $category, EmailLog::STATUS_SKIPPED, $meta, 'Penerima sudah berhenti berlangganan email info & tips.');

                return ['sent' => false, 'skipped' => true, 'mailer' => 'none', 'error' => 'unsubscribed'];
            }
            $unsubscribeUrl = $this->unsubscribeUrl($recipients[0]);
        }
        $this->finish($mailable, $category, $unsubscribeUrl, $meta);

        $mailer = 'log';
        try {
            $mailer = $this->configureMailer();
            $transport = (string) config("mail.mailers.{$mailer}.transport", $mailer);
            if ($transport === 'log' || ($transport === 'array' && ! app()->environment('testing'))) {
                throw new \RuntimeException('Email belum dikirim: konfigurasi Resend belum siap. Atur RESEND_API_KEY dan aktifkan email di Pengaturan Email.');
            }
            $sent = Mail::mailer($mailer)->to($recipients)->send($mailable);
            $meta['message_id'] = $sent?->getMessageId();
            $this->log($recipients, $mailable, $category, EmailLog::STATUS_SENT, $meta);

            return [
                'sent' => true,
                'mailer' => $mailer,
                'error' => null,
            ];
        } catch (Throwable $exception) {
            $this->log($recipients, $mailable, $category, EmailLog::STATUS_FAILED, $meta, $exception->getMessage(), true);

            return [
                'sent' => false,
                'mailer' => $mailer,
                'error' => $exception->getMessage(),
            ];
        }
    }

    /** For queued jobs: a failed send throws, so the queue retries (tries/backoff) and failed() runs. */
    public function sendOrFail(string|array $to, Mailable $mailable): array
    {
        $result = $this->sendTo($to, $mailable);
        if (! $result['sent'] && empty($result['skipped'])) {
            throw new \RuntimeException('Email gagal terkirim: '.($result['error'] ?? 'unknown'));
        }

        return $result;
    }

    /** Send in the background (after the current DB transaction commits), with retries. */
    public function queue(string|array $to, Mailable $mailable): void
    {
        $recipients = array_values(array_filter((array) $to));
        if ($recipients === []) {
            return;
        }
        \App\Jobs\SendMailable::dispatch($recipients, $mailable)->afterCommit();
    }

    /** "Kirim ulang" from Pengaturan Email › Riwayat (failed sends keep their mailable). */
    public function resend(EmailLog $log): array
    {
        $mailable = $log->payload ? @unserialize((string) $log->payload) : false;
        if (! $mailable instanceof Mailable) {
            return ['sent' => false, 'mailer' => 'none', 'error' => 'Isi email ini tidak tersimpan, tidak bisa dikirim ulang.'];
        }

        $result = $this->sendTo(self::parseEmails($log->to_email) ?: [$log->to_email], $mailable);
        if ($result['sent']) {
            $log->forceFill(['status' => EmailLog::STATUS_SENT, 'error' => null, 'payload' => null, 'attempts' => $log->attempts + 1])->save();
        }

        return $result;
    }

    public static function categoryOf(Mailable $mailable): string
    {
        $category = property_exists($mailable, 'mailCategory') ? (string) $mailable->mailCategory : '';

        return $category === self::CATEGORY_MARKETING ? self::CATEGORY_MARKETING : self::CATEGORY_TRANSACTIONAL;
    }

    public function hasUnsubscribed(string $email): bool
    {
        $preferences = \App\Models\User::query()->where('email', strtolower($email))->value('email_preferences');
        $preferences = is_string($preferences) ? json_decode($preferences, true) : $preferences;

        return is_array($preferences) && ($preferences['marketing'] ?? true) === false;
    }

    /** Signed one-click link (RFC 8058) — only info/tips emails; transactional mail always arrives. */
    public function unsubscribeUrl(string $email): ?string
    {
        $userId = \App\Models\User::query()->where('email', strtolower($email))->value('id');
        if (! $userId) {
            return null;
        }

        return \Illuminate\Support\Facades\URL::signedRoute('api.v1.hellom.public.email.unsubscribe', ['user' => $userId]);
    }

    /** @param array{subject: string, message_id?: ?string} $meta */
    private function finish(Mailable $mailable, string $category, ?string $unsubscribeUrl, array &$meta): void
    {
        if (! $mailable instanceof \Illuminate\Mail\Mailable) {
            return;
        }
        $settings = $this->getSettings();
        $mailable->with('unsubscribeUrl', $unsubscribeUrl);

        $mailable->withSymfonyMessage(function (\Symfony\Component\Mime\Email $email) use ($settings, $category, $unsubscribeUrl, &$meta) {
            $meta['subject'] = (string) $email->getSubject();
            $headers = $email->getHeaders();
            if ($headers->has('X-Hellom-Category')) {
                return; // already finished (mailable sent twice, e.g. resend)
            }
            $headers->addTextHeader('X-Hellom-Category', $category);

            // multipart/alternative: HTML-only mail scores as spam.
            $html = $email->getHtmlBody();
            if ($email->getTextBody() === null && is_string($html) && $html !== '') {
                $email->text(\App\Support\Mail\HtmlToText::convert($html));
            }

            // Automated mail: no out-of-office storms back to us.
            $headers->addTextHeader('Auto-Submitted', 'auto-generated');
            $headers->addTextHeader('X-Auto-Response-Suppress', 'OOF, AutoReply');

            // Replies go to a mailbox someone reads (Pengaturan Email › Reply-To).
            $replyTo = trim((string) ($settings['reply_to_address'] ?? ''));
            if ($email->getReplyTo() === [] && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
                $email->replyTo(new \Symfony\Component\Mime\Address($replyTo, (string) ($settings['reply_to_name'] ?? '')));
            }

            if ($category === self::CATEGORY_MARKETING && $unsubscribeUrl) {
                $headers->addTextHeader('List-Unsubscribe', '<'.$unsubscribeUrl.'>');
                $headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            }
        });
    }

    /** @param list<string>|array<int, string> $to */
    private function log(array $to, Mailable $mailable, string $category, string $status, array $meta, ?string $error = null, bool $keepPayload = false): void
    {
        try {
            $subject = (string) ($meta['subject'] ?? '');
            if ($subject === '' && method_exists($mailable, 'envelope')) {
                $subject = (string) ($mailable->envelope()->subject ?? '');
            }
            $payload = null;
            if ($keepPayload) {
                try {
                    // Without our finish() closure (not serializable); resend() adds it again.
                    $copy = clone $mailable;
                    if ($copy instanceof \Illuminate\Mail\Mailable) {
                        $copy->callbacks = [];
                    }
                    $payload = serialize($copy);
                } catch (Throwable) {
                    $payload = null; // closures etc. — no resend for this one
                }
            }
            EmailLog::query()->create([
                'to_email' => mb_substr(implode(', ', $to), 0, 255),
                'subject' => mb_substr($subject, 0, 255),
                'mailable' => mb_substr(class_basename($mailable), 0, 120),
                'category' => $category,
                'status' => $status,
                'error' => $error ? mb_substr($error, 0, 2000) : null,
                'message_id' => isset($meta['message_id']) ? mb_substr((string) $meta['message_id'], 0, 255) : null,
                'payload' => $payload,
            ]);
        } catch (Throwable $e) {
            report($e); // logging must never break sending
        }
    }

    private function configureMailer(): string
    {
        if (! $this->isReady()) {
            return app()->environment('testing') && config('mail.default') === 'array'
                ? 'array'
                : 'log';
        }

        $settings = $this->getSettings();

        config([
            'mail.default' => 'resend',
            'mail.from.address' => $settings['from_address'],
            'mail.from.name' => $settings['from_name'],
        ]);
        Mail::purge('resend');

        return 'resend';
    }

    private function getBool(string $key, bool $default): bool
    {
        $value = SystemSetting::get($key, $default ? '1' : '0');

        return in_array((string) $value, ['1', 'true', 'yes', 'on'], true);
    }
}
