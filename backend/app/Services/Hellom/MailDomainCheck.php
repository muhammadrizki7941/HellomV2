<?php

namespace App\Services\Hellom;

/**
 * Pengaturan Email › Cek domain: is the sender domain set up so Gmail/Outlook trust it?
 * SPF (TXT v=spf1 includes the SMTP provider), DKIM (provider selector published), DMARC
 * (_dmarc TXT), and alignment (the SMTP account may send as the From domain — e.g. Gmail SMTP
 * sending "From: noreply@hellomspace.com" fails DMARC and lands in spam).
 * DNS lookups go through a resolver closure so tests can fake them.
 */
class MailDomainCheck
{
    /** Known providers: SMTP host pattern → SPF include + DKIM selectors to look for. */
    private const PROVIDERS = [
        'brevo' => ['label' => 'Brevo', 'host' => '/(brevo|sendinblue)\.com$/', 'spf' => ['spf.brevo.com', 'spf.sendinblue.com'], 'dkim' => ['brevo1', 'brevo2', 'mail']],
        'gmail' => ['label' => 'Google Workspace / Gmail', 'host' => '/(gmail|google|googlemail)\.com$/', 'spf' => ['_spf.google.com'], 'dkim' => ['google']],
        'zoho' => ['label' => 'Zoho Mail', 'host' => '/zoho\.(com|eu|in)$/', 'spf' => ['zoho.com', 'zoho.eu', 'zoho.in'], 'dkim' => ['zmail', 'zoho']],
        'mailgun' => ['label' => 'Mailgun', 'host' => '/mailgun\.org$/', 'spf' => ['mailgun.org'], 'dkim' => ['mx', 'k1', 'smtp', 'pic']],
        'ses' => ['label' => 'Amazon SES', 'host' => '/amazonaws\.com$/', 'spf' => ['amazonses.com'], 'dkim' => []],
        'outlook' => ['label' => 'Microsoft 365 / Outlook', 'host' => '/(office365|outlook|hotmail|live)\.com$/', 'spf' => ['spf.protection.outlook.com'], 'dkim' => ['selector1', 'selector2']],
    ];

    /** Free mailbox domains: a From address there can never be authenticated by another SMTP. */
    private const FREE_MAIL = ['gmail.com', 'googlemail.com', 'yahoo.com', 'yahoo.co.id', 'outlook.com', 'hotmail.com', 'live.com', 'icloud.com', 'ymail.com'];

    /** @var \Closure(string $name, string $type): list<string> */
    private \Closure $resolver;

    public function __construct(?\Closure $resolver = null)
    {
        $this->resolver = $resolver ?? static function (string $name, string $type): array {
            $records = @dns_get_record($name, $type === 'CNAME' ? DNS_CNAME : DNS_TXT);
            if (!is_array($records)) {
                return [];
            }

            return array_values(array_filter(array_map(
                fn (array $r) => $type === 'CNAME' ? (string) ($r['target'] ?? '') : (string) ($r['txt'] ?? implode('', (array) ($r['entries'] ?? []))),
                $records,
            )));
        };
    }

    /**
     * @return array{domain: string, provider: ?string, provider_label: ?string, ok: bool,
     *               checks: list<array{key: string, label: string, status: 'ok'|'warn'|'fail', detail: string, fix: ?string}>}
     */
    public function check(string $fromAddress, string $smtpHost, string $smtpUsername = ''): array
    {
        $domain = strtolower((string) substr(strrchr($fromAddress, '@') ?: '', 1));
        $host = strtolower(trim($smtpHost));
        $providerKey = null;
        foreach (self::PROVIDERS as $key => $provider) {
            if ($host !== '' && preg_match($provider['host'], $host)) {
                $providerKey = $key;
                break;
            }
        }
        $provider = $providerKey ? self::PROVIDERS[$providerKey] : null;
        $checks = [];

        if ($providerKey === 'gmail' && in_array($domain, ['gmail.com', 'googlemail.com'], true)) {
            $matches = strcasecmp(trim($fromAddress), trim($smtpUsername)) === 0;
            return ['domain' => $domain, 'provider' => $providerKey, 'provider_label' => $provider['label'], 'ok' => $matches, 'checks' => [[
                'key' => 'alignment', 'label' => 'Pengirim Gmail', 'status' => $matches ? 'ok' : 'warn',
                'detail' => $matches
                    ? 'Alamat pengirim sama dengan akun SMTP Gmail. SPF, DKIM, dan DMARC gmail.com dikelola Google. Ini pemeriksaan konfigurasi, bukan bukti email masuk inbox.'
                    : 'Alamat pengirim berbeda dari akun SMTP Gmail. Alias perlu diverifikasi; pemeriksaan ini belum dapat membuktikan izin alias.',
                'fix' => $matches ? null : 'Gunakan alamat Gmail yang sama dengan username SMTP, atau verifikasi alias melalui pengaturan Gmail.',
            ]]];
        }

        if ($domain === '') {
            return ['domain' => '', 'provider' => $providerKey, 'provider_label' => $provider['label'] ?? null, 'ok' => false, 'checks' => [[
                'key' => 'from', 'label' => 'Alamat pengirim', 'status' => 'fail', 'detail' => 'Alamat "From" belum diisi.', 'fix' => 'Isi alamat pengirim, mis. noreply@hellomspace.com.',
            ]]];
        }

        // Alignment / sender domain.
        if ($host === '') {
            $checks[] = ['key' => 'alignment', 'label' => 'Server SMTP', 'status' => 'fail',
                'detail' => 'SMTP host belum diisi, jadi belum ada email yang benar-benar terkirim.',
                'fix' => 'Isi SMTP Brevo: smtp-relay.brevo.com, port 587, TLS (lihat docs/EMAIL.md).'];
        } elseif (in_array($domain, self::FREE_MAIL, true)) {
            $checks[] = ['key' => 'alignment', 'label' => 'Domain pengirim', 'status' => 'warn',
                'detail' => "Email dikirim dari alamat gratis (@{$domain}). Email massal dari alamat gratis gampang masuk spam dan tampil kurang profesional.",
                'fix' => 'Pakai alamat domain sendiri, mis. noreply@hellomspace.com, lewat Brevo (lihat docs/EMAIL.md).'];
        } elseif ($providerKey === 'gmail' && !str_ends_with(strtolower($smtpUsername), '@' . $domain)) {
            $checks[] = ['key' => 'alignment', 'label' => 'Kecocokan SMTP & pengirim', 'status' => 'fail',
                'detail' => "SMTP Gmail ({$smtpUsername}) mengirim atas nama @{$domain}. Gmail menulis ulang/menandatangani dengan domain gmail.com, jadi DMARC @{$domain} gagal → spam.",
                'fix' => 'Ganti SMTP ke Brevo (smtp-relay.brevo.com) dengan domain yang sudah diverifikasi.'];
        } else {
            $checks[] = ['key' => 'alignment', 'label' => 'Kecocokan SMTP & pengirim', 'status' => 'ok',
                'detail' => $provider ? "Dikirim lewat {$provider['label']} atas nama @{$domain}." : "Dikirim lewat {$host} atas nama @{$domain}.", 'fix' => null];
        }

        if (in_array($domain, self::FREE_MAIL, true)) {
            return ['domain' => $domain, 'provider' => $providerKey, 'provider_label' => $provider['label'] ?? null, 'ok' => false, 'checks' => $checks];
        }

        // SPF.
        $spf = array_values(array_filter($this->lookup($domain, 'TXT'), fn ($r) => stripos($r, 'v=spf1') === 0));
        if ($spf === []) {
            $checks[] = ['key' => 'spf', 'label' => 'SPF', 'status' => 'fail', 'detail' => "Tidak ada record SPF di {$domain}.",
                'fix' => 'Tambah TXT di ' . $domain . ': v=spf1 ' . ($provider ? 'include:' . $provider['spf'][0] . ' ' : '') . '~all'];
        } elseif (count($spf) > 1) {
            $checks[] = ['key' => 'spf', 'label' => 'SPF', 'status' => 'fail', 'detail' => 'Ada ' . count($spf) . ' record SPF; harus satu saja (gabungkan semua include dalam satu record).', 'fix' => 'Gabungkan jadi satu TXT v=spf1 … ~all.'];
        } elseif ($provider && !$this->containsAny($spf[0], $provider['spf'])) {
            $checks[] = ['key' => 'spf', 'label' => 'SPF', 'status' => 'fail', 'detail' => "SPF ada tapi belum mengizinkan {$provider['label']}: {$spf[0]}",
                'fix' => 'Tambahkan include:' . $provider['spf'][0] . ' ke record SPF yang sama (sebelum ~all).'];
        } else {
            $checks[] = ['key' => 'spf', 'label' => 'SPF', 'status' => 'ok', 'detail' => $spf[0], 'fix' => null];
        }

        // DKIM (only when we know the provider's selectors).
        if ($provider && $provider['dkim'] !== []) {
            $found = [];
            foreach ($provider['dkim'] as $selector) {
                $name = "{$selector}._domainkey.{$domain}";
                if ($this->lookup($name, 'CNAME') !== [] || array_filter($this->lookup($name, 'TXT'), fn ($r) => stripos($r, 'p=') !== false) !== []) {
                    $found[] = $selector;
                }
            }
            $checks[] = $found !== []
                ? ['key' => 'dkim', 'label' => 'DKIM', 'status' => 'ok', 'detail' => 'Selector ditemukan: ' . implode(', ', $found), 'fix' => null]
                : ['key' => 'dkim', 'label' => 'DKIM', 'status' => 'fail', 'detail' => "Belum ada kunci DKIM {$provider['label']} di {$domain}.",
                    'fix' => "Salin record DKIM dari dashboard {$provider['label']} (Senders & Domains › Authenticate) ke DNS {$domain}."];
        } else {
            $checks[] = ['key' => 'dkim', 'label' => 'DKIM', 'status' => 'warn', 'detail' => 'Penyedia SMTP tidak dikenali, DKIM tidak bisa dicek otomatis.', 'fix' => 'Pastikan DKIM diaktifkan di penyedia SMTP kamu.'];
        }

        // DMARC.
        $dmarc = array_values(array_filter($this->lookup("_dmarc.{$domain}", 'TXT'), fn ($r) => stripos($r, 'v=DMARC1') === 0));
        if ($dmarc === []) {
            $checks[] = ['key' => 'dmarc', 'label' => 'DMARC', 'status' => 'fail', 'detail' => "Tidak ada record DMARC di _dmarc.{$domain}. Gmail & Yahoo mewajibkannya untuk pengirim massal.",
                'fix' => "Tambah TXT _dmarc.{$domain}: v=DMARC1; p=none; rua=mailto:dmarc@{$domain}; adkim=r; aspf=r"];
        } else {
            preg_match('/p=(\w+)/i', $dmarc[0], $m);
            $policy = strtolower($m[1] ?? 'none');
            $checks[] = ['key' => 'dmarc', 'label' => 'DMARC', 'status' => 'ok',
                'detail' => $dmarc[0] . ($policy === 'none' ? ' (mode pantau; naikkan ke p=quarantine setelah 2 minggu tanpa masalah)' : ''), 'fix' => null];
        }

        // "Siap" only when everything is verified green (a warning = not proven yet).
        $ok = array_diff(array_column($checks, 'status'), ['ok']) === [];

        return ['domain' => $domain, 'provider' => $providerKey, 'provider_label' => $provider['label'] ?? null, 'ok' => $ok, 'checks' => $checks];
    }

    /** @return list<string> */
    private function lookup(string $name, string $type): array
    {
        try {
            return ($this->resolver)($name, $type);
        } catch (\Throwable) {
            return [];
        }
    }

    /** @param list<string> $needles */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (stripos($haystack, 'include:' . $needle) !== false) {
                return true;
            }
        }

        return false;
    }
}
