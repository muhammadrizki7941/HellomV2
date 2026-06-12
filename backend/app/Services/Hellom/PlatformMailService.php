<?php

namespace App\Services\Hellom;

use App\Models\HellomBrandSetting;
use App\Models\SystemSetting;
use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Throwable;

class PlatformMailService
{
    public function getSettings(): array
    {
        $brand = HellomBrandSetting::getSettings();
        $enabled = $this->getBool('hellom_mail_enabled', false);
        $port = (int) SystemSetting::get('hellom_mail_port', 587);
        $username = trim((string) SystemSetting::get('hellom_mail_username', ''));
        $host = $this->normalizeHost((string) SystemSetting::get('hellom_mail_host', ''), $username);
        $fromAddress = (string) SystemSetting::get('hellom_mail_from_address', $brand->support_email ?: 'hello@example.com');
        $fromName = (string) SystemSetting::get('hellom_mail_from_name', $brand->app_name ?: $brand->business_name ?: 'Hellom');

        return [
            'enabled' => $enabled,
            'host' => $host,
            'port' => $port > 0 ? $port : 587,
            'username' => $username,
            'password' => $this->getDecryptedPassword(),
            'password_masked' => $this->maskedSecret($this->getDecryptedPassword()),
            'encryption' => (string) SystemSetting::get('hellom_mail_encryption', 'tls'),
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
            'host' => $settings['host'],
            'port' => $settings['port'],
            'username' => $settings['username'],
            'password_masked' => $settings['password_masked'],
            'encryption' => $settings['encryption'],
            'from_address' => $settings['from_address'],
            'from_name' => $settings['from_name'],
            'reply_to_address' => $settings['reply_to_address'],
            'reply_to_name' => $settings['reply_to_name'],
            'is_ready' => $this->isReady(),
        ];
    }

    public function saveSettings(array $settings): array
    {
        $settings = $this->normalizeSettings($settings);

        SystemSetting::set('hellom_mail_enabled', !empty($settings['enabled']) ? '1' : '0');
        SystemSetting::set('hellom_mail_host', trim((string) ($settings['host'] ?? '')));
        SystemSetting::set('hellom_mail_port', (string) ((int) ($settings['port'] ?? 587)));
        SystemSetting::set('hellom_mail_username', trim((string) ($settings['username'] ?? '')));
        SystemSetting::set('hellom_mail_encryption', trim((string) ($settings['encryption'] ?? 'tls')));
        SystemSetting::set('hellom_mail_from_address', trim((string) ($settings['from_address'] ?? '')));
        SystemSetting::set('hellom_mail_from_name', trim((string) ($settings['from_name'] ?? '')));
        SystemSetting::set('hellom_mail_reply_to_address', trim((string) ($settings['reply_to_address'] ?? '')));
        SystemSetting::set('hellom_mail_reply_to_name', trim((string) ($settings['reply_to_name'] ?? '')));

        $password = trim((string) ($settings['password'] ?? ''));
        if ($password !== '') {
            SystemSetting::set('hellom_mail_password_encrypted', Crypt::encryptString($password));
        }

        return $this->publicSettingsSummary();
    }

    public function normalizeSettings(array $settings): array
    {
        $normalized = $settings;
        $normalized['username'] = trim((string) Arr::get($settings, 'username', ''));
        $normalized['host'] = $this->normalizeHost((string) Arr::get($settings, 'host', ''), $normalized['username']);
        $normalized['port'] = $this->normalizePort(Arr::get($settings, 'port', 587));

        return $normalized;
    }

    public function isValidSmtpHost(string $host): bool
    {
        $host = trim($host);
        if ($host === '' || str_contains($host, '@')) {
            return false;
        }

        return (bool) preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))+$/i', $host);
    }

    public function isReady(): bool
    {
        $settings = $this->getSettings();

        if (!$settings['enabled']) {
            return false;
        }

        return $settings['host'] !== ''
            && $settings['port'] > 0
            && $settings['username'] !== ''
            && $settings['password'] !== ''
            && $settings['from_address'] !== '';
    }

    public function sendTo(string|array $to, Mailable $mailable): array
    {
        try {
            $mailer = $this->configureMailer();
            Mail::mailer($mailer)->to($to)->send($mailable);

            return [
                'sent' => true,
                'mailer' => $mailer,
                'error' => null,
            ];
        } catch (Throwable $exception) {
            return [
                'sent' => false,
                'mailer' => $this->isReady() ? 'smtp' : (string) config('mail.default', 'log'),
                'error' => $exception->getMessage(),
            ];
        }
    }

    private function configureMailer(): string
    {
        if (!$this->isReady()) {
            return (string) config('mail.default', 'log');
        }

        $settings = $this->getSettings();

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.transport' => 'smtp',
            'mail.mailers.smtp.host' => $settings['host'],
            'mail.mailers.smtp.port' => $settings['port'],
            'mail.mailers.smtp.encryption' => $settings['encryption'] !== '' ? $settings['encryption'] : null,
            'mail.mailers.smtp.username' => $settings['username'],
            'mail.mailers.smtp.password' => $settings['password'],
            'mail.from.address' => $settings['from_address'],
            'mail.from.name' => $settings['from_name'],
        ]);

        return 'smtp';
    }

    private function getDecryptedPassword(): string
    {
        $encrypted = (string) SystemSetting::get('hellom_mail_password_encrypted', '');
        if ($encrypted === '') {
            return '';
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (Throwable) {
            return '';
        }
    }

    private function maskedSecret(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        if (strlen($value) <= 6) {
            return str_repeat('*', strlen($value));
        }

        return substr($value, 0, 2) . str_repeat('*', max(0, strlen($value) - 4)) . substr($value, -2);
    }

    private function getBool(string $key, bool $default): bool
    {
        $value = SystemSetting::get($key, $default ? '1' : '0');

        return in_array((string) $value, ['1', 'true', 'yes', 'on'], true);
    }

    private function normalizeHost(string $host, string $username = ''): string
    {
        $host = trim(strtolower($host));
        if ($host === '') {
            return '';
        }

        if (str_contains($host, '://')) {
            $parsedHost = parse_url($host, PHP_URL_HOST);
            if (is_string($parsedHost) && $parsedHost !== '') {
                $host = $parsedHost;
            }
        }

        $host = trim($host, "/ \t\n\r\0\x0B");

        if (filter_var($host, FILTER_VALIDATE_EMAIL)) {
            return $this->inferSmtpHostFromEmail($host) ?? $host;
        }

        if (preg_match('/^(?<hostname>[^:\/]+):(?<port>\d{1,5})$/', $host, $matches) === 1) {
            $host = strtolower(trim((string) $matches['hostname']));
        }

        if ($host === '' && $username !== '' && filter_var($username, FILTER_VALIDATE_EMAIL)) {
            return $this->inferSmtpHostFromEmail($username) ?? '';
        }

        return $host;
    }

    private function inferSmtpHostFromEmail(string $email): ?string
    {
        $domain = strtolower((string) substr(strrchr($email, '@') ?: '', 1));

        return match ($domain) {
            'gmail.com', 'googlemail.com' => 'smtp.gmail.com',
            'outlook.com', 'hotmail.com', 'live.com', 'msn.com' => 'smtp-mail.outlook.com',
            'yahoo.com', 'yahoo.co.id', 'ymail.com' => 'smtp.mail.yahoo.com',
            'icloud.com', 'me.com', 'mac.com' => 'smtp.mail.me.com',
            'aol.com' => 'smtp.aol.com',
            'zoho.com' => 'smtp.zoho.com',
            default => null,
        };
    }

    private function normalizePort(mixed $port): int
    {
        $value = (int) $port;

        return $value > 0 ? $value : 587;
    }
}
