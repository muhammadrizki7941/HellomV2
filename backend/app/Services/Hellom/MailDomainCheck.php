<?php

namespace App\Services\Hellom;

/** Pengaturan Email › Cek record SPF, DKIM, dan DMARC untuk domain pengirim Resend. */
class MailDomainCheck
{
    /** @var \Closure(string, string): list<string> */
    private \Closure $resolver;

    public function __construct(?\Closure $resolver = null)
    {
        $this->resolver = $resolver ?? static function (string $name, string $type): array {
            $records = @dns_get_record($name, $type === 'CNAME' ? DNS_CNAME : DNS_TXT);
            if (! is_array($records)) {
                return [];
            }

            return array_values(array_filter(array_map(
                fn (array $record) => $type === 'CNAME'
                    ? (string) ($record['target'] ?? '')
                    : (string) ($record['txt'] ?? implode('', (array) ($record['entries'] ?? []))),
                $records,
            )));
        };
    }

    /**
     * @return array{domain: string, provider: string, provider_label: string, ok: bool,
     *               checks: list<array{key: string, label: string, status: 'ok'|'warn'|'fail', detail: string, fix: ?string}>}
     */
    public function check(string $fromAddress): array
    {
        $domain = strtolower((string) substr(strrchr($fromAddress, '@') ?: '', 1));
        if ($domain === '') {
            return $this->result('', [[
                'key' => 'from',
                'label' => 'Alamat pengirim',
                'status' => 'fail',
                'detail' => 'Alamat From belum diisi.',
                'fix' => 'Isi alamat pengirim dari domain yang sudah ditambahkan ke Resend.',
            ]]);
        }

        $checks = [];
        $spf = array_values(array_filter(
            $this->lookup($domain, 'TXT'),
            fn (string $record) => stripos($record, 'v=spf1') === 0,
        ));
        if ($spf === []) {
            $checks[] = [
                'key' => 'spf',
                'label' => 'SPF',
                'status' => 'fail',
                'detail' => "Record SPF tidak ditemukan di {$domain}.",
                'fix' => "Tambahkan/gabungkan record Resend ke TXT {$domain}: v=spf1 include:amazonses.com ~all",
            ];
        } elseif (count($spf) > 1) {
            $checks[] = [
                'key' => 'spf',
                'label' => 'SPF',
                'status' => 'fail',
                'detail' => 'Ditemukan lebih dari satu record SPF; domain hanya boleh memiliki satu.',
                'fix' => 'Gabungkan semua mekanisme SPF menjadi satu record TXT.',
            ];
        } elseif (stripos($spf[0], 'include:amazonses.com') === false) {
            $checks[] = [
                'key' => 'spf',
                'label' => 'SPF',
                'status' => 'fail',
                'detail' => "Record SPF belum mengizinkan pengiriman Resend: {$spf[0]}",
                'fix' => 'Gabungkan include:amazonses.com ke record SPF yang sama sesuai instruksi DNS Resend.',
            ];
        } else {
            $checks[] = ['key' => 'spf', 'label' => 'SPF', 'status' => 'ok', 'detail' => $spf[0], 'fix' => null];
        }

        $dkimName = "resend._domainkey.{$domain}";
        $dkimTxt = array_filter($this->lookup($dkimName, 'TXT'), fn (string $record) => stripos($record, 'p=') !== false);
        $dkimCname = $this->lookup($dkimName, 'CNAME');
        $checks[] = $dkimTxt !== [] || $dkimCname !== []
            ? ['key' => 'dkim', 'label' => 'DKIM', 'status' => 'ok', 'detail' => "Record DKIM Resend ditemukan di {$dkimName}.", 'fix' => null]
            : ['key' => 'dkim', 'label' => 'DKIM', 'status' => 'fail', 'detail' => "Record DKIM Resend belum ditemukan di {$dkimName}.", 'fix' => 'Salin record DKIM persis dari dashboard Resend ke DNS domain.'];

        $dmarc = array_values(array_filter(
            $this->lookup("_dmarc.{$domain}", 'TXT'),
            fn (string $record) => stripos($record, 'v=DMARC1') === 0,
        ));
        $checks[] = $dmarc !== []
            ? ['key' => 'dmarc', 'label' => 'DMARC', 'status' => 'ok', 'detail' => $dmarc[0], 'fix' => null]
            : ['key' => 'dmarc', 'label' => 'DMARC', 'status' => 'fail', 'detail' => "Record DMARC tidak ditemukan di _dmarc.{$domain}.", 'fix' => "Tambahkan TXT _dmarc.{$domain}: v=DMARC1; p=none; rua=mailto:dmarc@{$domain}"];

        return $this->result($domain, $checks);
    }

    /** @param list<array{key: string, label: string, status: 'ok'|'warn'|'fail', detail: string, fix: ?string}> $checks */
    private function result(string $domain, array $checks): array
    {
        return [
            'domain' => $domain,
            'provider' => 'resend',
            'provider_label' => 'Resend',
            'ok' => array_diff(array_column($checks, 'status'), ['ok']) === [],
            'checks' => $checks,
        ];
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
}
