<?php

namespace Tests\Landing;

use App\Jobs\SendPlatformMail;
use App\Mail\HellomAnnouncementMail;
use App\Mail\HellomCheckoutStatusMail;
use App\Models\ApiToken;
use App\Models\EmailLog;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Hellom\MailDomainCheck;
use App\Services\Hellom\PlatformMailService;
use App\Support\Mail\HtmlToText;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Tests\Pos\PosTestCase;

/**
 * Professional email: every message has a text part, Auto-Submitted, Reply-To; info/tips mail
 * has one-click unsubscribe and stops after it (transactional mail keeps arriving); every attempt
 * is logged and a failed one can be resent; the domain check explains SPF/DKIM/DMARC/alignment.
 * Real rendering through the in-memory "array" mailer — platform SMTP is switched off here.
 */
class EmailDeliverabilityTest extends PosTestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        // Never real SMTP: platform mail off (test DB is a dev clone) → config mail.default = array.
        SystemSetting::set('hellom_mail_enabled', '0');
        SystemSetting::set('hellom_mail_reply_to_address', 'halo@hellomspace.test');
        SystemSetting::set('hellom_mail_reply_to_name', 'Tim Hellom');
        config(['mail.default' => 'array']);
        Mail::swap(new MailManager($this->app)); // real rendering instead of Mail::fake()
    }

    /** @return list<SentMessage> */
    private function sent(): array
    {
        return Mail::mailer('array')->getSymfonyTransport()->messages()->all();
    }

    private function email(int $i = -1): \Symfony\Component\Mime\Email
    {
        $all = $this->sent();

        return $all[$i < 0 ? count($all) + $i : $i]->getOriginalMessage();
    }

    private function user(): User
    {
        return User::query()->create(['name' => 'Budi', 'email' => Str::lower(Str::random(10)) . '@example.test', 'password' => bcrypt(Str::random(16)), 'role' => 'admin']);
    }

    private function receipt(): HellomCheckoutStatusMail
    {
        return new HellomCheckoutStatusMail('Pembayaran berhasil — Kelas Foto', [
            'headline' => 'Pembayaran berhasil', 'intro' => 'Terima kasih, pembayaran kamu sudah kami terima.',
            'details' => ['Produk' => 'Kelas Foto', 'Total' => 'Rp 75.000'], 'cta_url' => 'https://hellomspace.test/pesanan/ABC', 'cta_label' => 'Lihat Pesanan',
        ]);
    }

    public function test_log_mailer_cannot_report_a_delivered_email(): void
    {
        config(['mail.default' => 'log']);
        $user = $this->user();
        $result = app(PlatformMailService::class)->sendTo($user->email, $this->receipt());
        $this->assertFalse($result['sent']);
        $log = EmailLog::where('to_email', $user->email)->sole();
        $this->assertSame('failed', $log->status);
        $this->assertNotNull($log->payload);
    }

    public function test_matching_gmail_sender_does_not_require_custom_domain_dns(): void
    {
        $check = new MailDomainCheck(function () { $this->fail('Gmail domain is managed by Google'); });
        $result = $check->check('owner@gmail.com', 'smtp.gmail.com', 'owner@gmail.com');
        $this->assertTrue($result['ok']);
        $this->assertFalse($check->check('alias@gmail.com', 'smtp.gmail.com', 'owner@gmail.com')['ok']);
    }

    public function test_gmail_app_password_spaces_are_removed_for_existing_settings(): void
    {
        SystemSetting::set('hellom_mail_host', 'smtp.gmail.com');
        SystemSetting::set('hellom_mail_password_encrypted', \Illuminate\Support\Facades\Crypt::encryptString('abcd efgh ijkl mnop'));
        $this->assertSame('abcdefghijklmnop', app(PlatformMailService::class)->getSettings()['password']);
    }

    public function test_every_email_gets_text_part_reply_to_and_auto_submitted_and_is_logged(): void
    {
        $user = $this->user();
        $result = app(PlatformMailService::class)->sendTo($user->email, $this->receipt());

        $this->assertTrue($result['sent']);
        $email = $this->email();
        $this->assertStringContainsString('Pembayaran berhasil', (string) $email->getHtmlBody());
        $text = (string) $email->getTextBody();
        $this->assertStringContainsString('Terima kasih, pembayaran kamu sudah kami terima.', $text);
        $this->assertStringContainsString('Lihat Pesanan (https://hellomspace.test/pesanan/ABC)', $text);
        $this->assertStringContainsString('Total: Rp 75.000', $text);
        $this->assertStringNotContainsString('<', $text);
        $this->assertSame('auto-generated', $email->getHeaders()->getHeaderBody('Auto-Submitted'));
        $this->assertSame('halo@hellomspace.test', $email->getReplyTo()[0]->getAddress());
        $this->assertFalse($email->getHeaders()->has('List-Unsubscribe')); // receipts always arrive

        $log = EmailLog::query()->where('to_email', $user->email)->sole();
        $this->assertSame(['sent', 'transactional', 'Pembayaran berhasil — Kelas Foto', 'HellomCheckoutStatusMail'], [$log->status, $log->category, $log->subject, $log->mailable]);
        $this->assertNull($log->getRawOriginal('payload'));
    }

    public function test_info_email_has_one_click_unsubscribe_and_stops_after_it(): void
    {
        $user = $this->user();
        $mail = app(PlatformMailService::class);
        $announce = fn () => new HellomAnnouncementMail(subjectLine: 'Fitur baru Hellom', heading: 'Ada yang baru', body: 'Kalkulator HPP sekarang punya mode Pro.', ctaLabel: null, ctaUrl: null);

        $this->assertTrue($mail->sendTo($user->email, $announce())['sent']);
        $email = $this->email();
        $header = (string) $email->getHeaders()->getHeaderBody('List-Unsubscribe');
        $this->assertMatchesRegularExpression('#^<https?://[^>]+/public/email/unsubscribe/' . $user->id . '\?[^>]*signature=[^>]+>$#', $header);
        $this->assertSame('List-Unsubscribe=One-Click', $email->getHeaders()->getHeaderBody('List-Unsubscribe-Post'));
        $this->assertStringContainsString('Berhenti berlangganan', (string) $email->getHtmlBody());

        // Gmail's one-click POST (signed URL, no login).
        $url = trim($header, '<>');
        $this->post(substr($url, strpos($url, '/api/')), ['List-Unsubscribe' => 'One-Click'])->assertOk();
        $this->assertFalse($user->fresh()->email_preferences['marketing']);
        $this->post(substr($url, strpos($url, '/api/')) . 'x')->assertStatus(403); // tampered signature

        $count = count($this->sent());
        $skipped = $mail->sendTo($user->email, $announce());
        $this->assertTrue($skipped['skipped'] ?? false);
        $this->assertCount($count, $this->sent());
        $this->assertSame('skipped', EmailLog::query()->where('to_email', $user->email)->latest('id')->value('status'));

        // Transactional mail still arrives.
        $this->assertTrue($mail->sendTo($user->email, $this->receipt())['sent']);
        $this->assertCount($count + 1, $this->sent());
    }

    public function test_failed_email_is_logged_and_can_be_resent_by_the_super_admin(): void
    {
        $user = $this->user();
        Mail::extend('failing', fn () => new class extends AbstractTransport {
            protected function doSend(SentMessage $message): void
            {
                throw new \RuntimeException('Connection to smtp refused');
            }

            public function __toString(): string
            {
                return 'failing://';
            }
        });
        config(['mail.mailers.failing' => ['transport' => 'failing'], 'mail.default' => 'failing']);

        $result = app(PlatformMailService::class)->sendTo($user->email, $this->receipt());
        $this->assertFalse($result['sent']);
        $log = EmailLog::query()->where('to_email', $user->email)->sole();
        $this->assertSame('failed', $log->status);
        $this->assertStringContainsString('Connection to smtp refused', (string) $log->error);
        $this->assertNotNull($log->getRawOriginal('payload'));

        // sendOrFail throws so queued jobs retry.
        try {
            app(PlatformMailService::class)->sendOrFail($user->email, $this->receipt());
            $this->fail('sendOrFail should throw');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Email gagal terkirim', $e->getMessage());
        }

        // Mail server back → super admin resends from Riwayat.
        config(['mail.default' => 'array']);
        $admin = User::query()->create(['name' => 'Super', 'email' => Str::lower(Str::random(10)) . '@example.test', 'password' => bcrypt(Str::random(16)), 'role' => 'super_admin']);
        $plain = Str::random(40);
        ApiToken::query()->create(['user_id' => $admin->id, 'name' => 't', 'token_hash' => hash('sha256', $plain)]);
        $api = $this->withHeader('Authorization', "Bearer {$plain}");

        $list = $api->getJson('/api/v1/hellom/admin/mail-logs?status=failed&q=' . urlencode($user->email))->assertOk();
        $this->assertSame(2, $list->json('data.meta.total')); // the send + the sendOrFail attempt
        $this->assertContains($log->id, array_column($list->json('data.data'), 'id'));
        $this->assertTrue($list->json('data.data.0.can_resend'));
        $this->assertArrayNotHasKey('payload', $list->json('data.data.0'));

        $api->postJson("/api/v1/hellom/admin/mail-logs/{$log->id}/resend")->assertOk();
        $this->assertSame('sent', $log->fresh()->status);
        $this->assertNull($log->fresh()->getRawOriginal('payload'));
        $this->assertSame('Pembayaran berhasil — Kelas Foto', $this->email()->getSubject());
        $api->postJson("/api/v1/hellom/admin/mail-logs/{$log->id}/resend")->assertStatus(422); // already sent
    }

    public function test_platform_mail_job_fans_out_one_message_per_recipient(): void
    {
        (new SendPlatformMail(['a@example.test', 'b@example.test'], 'Penarikan dana berhasil', ['headline' => 'Berhasil', 'intro' => 'Dana sudah ditransfer.']))
            ->handle(app(PlatformMailService::class));

        $to = array_map(fn (SentMessage $m) => $m->getOriginalMessage()->getTo()[0]->getAddress(), $this->sent());
        sort($to);
        $this->assertSame(['a@example.test', 'b@example.test'], $to); // separate messages, no shared To
        foreach ($this->sent() as $message) {
            $this->assertCount(1, $message->getOriginalMessage()->getTo());
        }
    }

    public function test_domain_check_explains_spf_dkim_dmarc_and_gmail_misalignment(): void
    {
        $dns = [
            'TXT hellomspace.test' => ['v=spf1 include:spf.brevo.com ~all', 'google-site-verification=x'],
            'CNAME brevo1._domainkey.hellomspace.test' => ['b1.hellomspace-test.dkim.brevo.com'],
            'TXT _dmarc.hellomspace.test' => ['v=DMARC1; p=none; rua=mailto:dmarc@hellomspace.test'],
        ];
        $check = new MailDomainCheck(fn (string $name, string $type) => $dns["{$type} {$name}"] ?? []);

        $brevo = $check->check('noreply@hellomspace.test', 'smtp-relay.brevo.com', '9a1b@smtp-brevo.com');
        $this->assertTrue($brevo['ok']);
        $this->assertSame('brevo', $brevo['provider']);
        $this->assertSame(['alignment' => 'ok', 'spf' => 'ok', 'dkim' => 'ok', 'dmarc' => 'ok'], array_column($brevo['checks'], 'status', 'key'));

        // Today's setup: Gmail SMTP sending as @hellomspace → DMARC fails → spam.
        $gmail = $check->check('noreply@hellomspace.test', 'smtp.gmail.com', 'owner@gmail.com');
        $this->assertFalse($gmail['ok']);
        $statuses = array_column($gmail['checks'], 'status', 'key');
        $this->assertSame('fail', $statuses['alignment']);
        $this->assertSame('fail', $statuses['spf']); // SPF does not include Google
        $this->assertSame('fail', $statuses['dkim']);

        $bare = (new MailDomainCheck(fn () => []))->check('noreply@toko.test', 'smtp-relay.brevo.com');
        $fixes = array_column($bare['checks'], 'fix', 'key');
        $this->assertStringContainsString('include:spf.brevo.com', $fixes['spf']);
        $this->assertStringContainsString('v=DMARC1; p=none', $fixes['dmarc']);

        // No SMTP yet / unknown provider: never "siap".
        $noSmtp = $check->check('noreply@hellomspace.test', '');
        $this->assertFalse($noSmtp['ok']);
        $this->assertSame('fail', array_column($noSmtp['checks'], 'status', 'key')['alignment']);
        $this->assertFalse($check->check('noreply@hellomspace.test', 'mail.unknown-host.test')['ok']); // DKIM unverifiable = warn
    }

    public function test_html_to_text_keeps_links_and_drops_hidden_preheader(): void
    {
        $text = HtmlToText::convert('<html><head><style>p{}</style></head><body><div style="display:none;">pratinjau</div>'
            . '<h1>Halo &amp; selamat</h1><p>Klik <a href="https://x.test/a?b=1&amp;c=2">di sini</a>.<br>Baris dua</p>'
            . '<a href="mailto:halo@x.test">halo@x.test</a></body></html>');

        $this->assertSame("Halo & selamat\nKlik di sini (https://x.test/a?b=1&c=2).\nBaris dua\nhalo@x.test\n", $text);
    }
}
