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
 * is logged and a failed one can be resent; the domain check explains Resend SPF/DKIM/DMARC.
 * Real rendering through the in-memory "array" mailer — platform Resend is switched off here.
 */
class EmailDeliverabilityTest extends PosTestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        // Never call Resend: platform mail off (test DB is a dev clone) → config mail.default = array.
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
        return User::query()->create(['name' => 'Budi', 'email' => Str::lower(Str::random(10)).'@example.test', 'password' => bcrypt(Str::random(16)), 'role' => 'admin']);
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

    public function test_missing_resend_configuration_does_not_fall_back_to_smtp(): void
    {
        config(['mail.default' => 'smtp']);

        $result = app(PlatformMailService::class)->sendTo($this->user()->email, $this->receipt());

        $this->assertFalse($result['sent']);
        $this->assertSame('log', $result['mailer']);
        $this->assertStringContainsString('konfigurasi Resend belum siap', $result['error']);
    }

    public function test_saving_resend_settings_removes_stored_smtp_credentials(): void
    {
        SystemSetting::set('hellom_mail_password_encrypted', 'legacy-secret');

        app(PlatformMailService::class)->saveSettings([
            'enabled' => true,
            'from_address' => 'noreply@hellomspace.test',
        ]);

        $this->assertNull(SystemSetting::get('hellom_mail_password_encrypted'));
        $this->assertNull(SystemSetting::get('hellom_mail_host'));
    }

    public function test_platform_mail_uses_resend_when_configured_without_exposing_api_key(): void
    {
        $transport = new class extends AbstractTransport
        {
            public array $messages = [];

            protected function doSend(SentMessage $message): void
            {
                $this->messages[] = $message;
            }

            public function __toString(): string
            {
                return 'resend-fake://';
            }
        };
        Mail::extend('resend', fn () => $transport);
        config(['services.resend.key' => 're_test_fake']);
        SystemSetting::set('hellom_mail_enabled', '1');
        SystemSetting::set('hellom_mail_from_address', 'noreply@hellomspace.test');

        $result = app(PlatformMailService::class)->sendTo($this->user()->email, $this->receipt());

        $this->assertTrue($result['sent']);
        $this->assertSame('resend', $result['mailer']);
        $this->assertCount(1, $transport->messages);
        $settings = app(PlatformMailService::class)->publicSettingsSummary();
        $this->assertTrue($settings['resend_configured']);
        $this->assertArrayNotHasKey('api_key', $settings);
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
        $this->assertMatchesRegularExpression('#^<https?://[^>]+/public/email/unsubscribe/'.$user->id.'\?[^>]*signature=[^>]+>$#', $header);
        $this->assertSame('List-Unsubscribe=One-Click', $email->getHeaders()->getHeaderBody('List-Unsubscribe-Post'));
        $this->assertStringContainsString('Berhenti berlangganan', (string) $email->getHtmlBody());

        // Gmail's one-click POST (signed URL, no login).
        $url = trim($header, '<>');
        $this->post(substr($url, strpos($url, '/api/')), ['List-Unsubscribe' => 'One-Click'])->assertOk();
        $this->assertFalse($user->fresh()->email_preferences['marketing']);
        $this->post(substr($url, strpos($url, '/api/')).'x')->assertStatus(403); // tampered signature

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
        Mail::extend('resend', fn () => new class extends AbstractTransport
        {
            protected function doSend(SentMessage $message): void
            {
                throw new \RuntimeException('Resend API unavailable');
            }

            public function __toString(): string
            {
                return 'resend-failing://';
            }
        });
        config(['services.resend.key' => 're_test_fake']);
        SystemSetting::set('hellom_mail_enabled', '1');
        SystemSetting::set('hellom_mail_from_address', 'noreply@hellomspace.test');

        $result = app(PlatformMailService::class)->sendTo($user->email, $this->receipt());
        $this->assertFalse($result['sent']);
        $log = EmailLog::query()->where('to_email', $user->email)->sole();
        $this->assertSame('failed', $log->status);
        $this->assertStringContainsString('Resend API unavailable', (string) $log->error);
        $this->assertNotNull($log->getRawOriginal('payload'));

        // sendOrFail throws so queued jobs retry.
        try {
            app(PlatformMailService::class)->sendOrFail($user->email, $this->receipt());
            $this->fail('sendOrFail should throw');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Email gagal terkirim', $e->getMessage());
        }

        // Resend is available again → super admin resends from Riwayat.
        $successTransport = new class extends AbstractTransport
        {
            public array $messages = [];

            protected function doSend(SentMessage $message): void
            {
                $this->messages[] = $message;
            }

            public function __toString(): string
            {
                return 'resend-fake://';
            }
        };
        Mail::extend('resend', fn () => $successTransport);
        Mail::purge('resend');
        $admin = User::query()->create(['name' => 'Super', 'email' => Str::lower(Str::random(10)).'@example.test', 'password' => bcrypt(Str::random(16)), 'role' => 'super_admin']);
        $plain = Str::random(40);
        ApiToken::query()->create(['user_id' => $admin->id, 'name' => 't', 'token_hash' => hash('sha256', $plain)]);
        $api = $this->withHeader('Authorization', "Bearer {$plain}");

        $list = $api->getJson('/api/v1/hellom/admin/mail-logs?status=failed&q='.urlencode($user->email))->assertOk();
        $this->assertSame(2, $list->json('data.meta.total')); // the send + the sendOrFail attempt
        $this->assertContains($log->id, array_column($list->json('data.data'), 'id'));
        $this->assertTrue($list->json('data.data.0.can_resend'));
        $this->assertArrayNotHasKey('payload', $list->json('data.data.0'));

        $api->postJson("/api/v1/hellom/admin/mail-logs/{$log->id}/resend")->assertOk();
        $this->assertSame('sent', $log->fresh()->status);
        $this->assertNull($log->fresh()->getRawOriginal('payload'));
        $this->assertSame('Pembayaran berhasil — Kelas Foto', $successTransport->messages[0]->getOriginalMessage()->getSubject());
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

    public function test_domain_check_validates_resend_spf_dkim_and_dmarc(): void
    {
        $dns = [
            'TXT hellomspace.test' => ['v=spf1 include:amazonses.com ~all'],
            'TXT resend._domainkey.hellomspace.test' => ['v=DKIM1; p=sample'],
            'TXT _dmarc.hellomspace.test' => ['v=DMARC1; p=none; rua=mailto:dmarc@hellomspace.test'],
        ];
        $check = new MailDomainCheck(fn (string $name, string $type) => $dns["{$type} {$name}"] ?? []);

        $resend = $check->check('noreply@hellomspace.test');
        $this->assertTrue($resend['ok']);
        $this->assertSame('resend', $resend['provider']);
        $this->assertSame(['spf' => 'ok', 'dkim' => 'ok', 'dmarc' => 'ok'], array_column($resend['checks'], 'status', 'key'));

        $bare = (new MailDomainCheck(fn () => []))->check('noreply@toko.test');
        $fixes = array_column($bare['checks'], 'fix', 'key');
        $this->assertStringContainsString('include:amazonses.com', $fixes['spf']);
        $this->assertStringContainsString('v=DMARC1; p=none', $fixes['dmarc']);
    }

    public function test_html_to_text_keeps_links_and_drops_hidden_preheader(): void
    {
        $text = HtmlToText::convert('<html><head><style>p{}</style></head><body><div style="display:none;">pratinjau</div>'
            .'<h1>Halo &amp; selamat</h1><p>Klik <a href="https://x.test/a?b=1&amp;c=2">di sini</a>.<br>Baris dua</p>'
            .'<a href="mailto:halo@x.test">halo@x.test</a></body></html>');

        $this->assertSame("Halo & selamat\nKlik di sini (https://x.test/a?b=1&c=2).\nBaris dua\nhalo@x.test\n", $text);
    }
}
