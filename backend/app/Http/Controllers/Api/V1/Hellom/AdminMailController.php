<?php

namespace App\Http\Controllers\Api\V1\Hellom;

use App\Mail\HellomAnnouncementMail;
use App\Models\EmailLog;
use App\Services\Hellom\MailDomainCheck;
use App\Services\Hellom\PlatformMailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AdminMailController extends BaseApiController
{
    public function __construct(
        private readonly PlatformMailService $mailService,
    ) {
    }

    public function showSettings(): JsonResponse
    {
        return $this->ok([
            'mail' => $this->mailService->publicSettingsSummary(),
        ], 'Mail settings loaded');
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'host' => ['nullable', 'string', 'max:255'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'encryption' => ['nullable', 'string', 'in:tls,ssl'],
            'from_address' => ['nullable', 'email', 'max:255'],
            'from_name' => ['nullable', 'string', 'max:255'],
            'reply_to_address' => ['nullable', 'email', 'max:255'],
            'reply_to_name' => ['nullable', 'string', 'max:255'],
            'owner_email' => ['nullable', 'string', 'max:500'],
        ]);
        $ownerInput = trim((string) ($validated['owner_email'] ?? ''));
        $invalid = array_filter(preg_split('/[\s,;]+/', $ownerInput) ?: [], fn ($email) => $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false);
        if ($invalid !== []) {
            throw ValidationException::withMessages(['owner_email' => 'Alamat email owner tidak valid: ' . implode(', ', $invalid) . '. Pisahkan beberapa email dengan koma.']);
        }

        $ownerEmail = $validated['owner_email'] ?? null;
        $hasOwnerEmail = array_key_exists('owner_email', $validated);
        $validated = $this->mailService->normalizeSettings($validated);
        if ($hasOwnerEmail) {
            $validated['owner_email'] = (string) $ownerEmail;
        }

        if (!empty($validated['enabled']) && $validated['host'] !== '' && !$this->mailService->isValidSmtpHost($validated['host'])) {
            throw ValidationException::withMessages([
                'host' => 'SMTP host harus berupa hostname server mail, misalnya smtp.gmail.com, bukan alamat email.',
            ]);
        }

        $this->adminAudit($request, 'admin.mail_settings.updated', 'mail', null, [
            'enabled' => (bool) ($validated['enabled'] ?? false),
            'host' => $validated['host'] ?? null,
            'port' => $validated['port'] ?? null,
            'from_address' => $validated['from_address'] ?? null,
            'owner_email_changed' => array_key_exists('owner_email', $validated),
            'password_changed' => trim((string) ($validated['password'] ?? '')) !== '',
        ]);

        return $this->ok([
            'mail' => $this->mailService->saveSettings($validated),
        ], 'Mail settings saved');
    }

    public function sendTest(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $test = new HellomAnnouncementMail(
            subjectLine: 'Tes email SMTP Hellom',
            heading: 'SMTP Hellom aktif',
            body: 'Email tes ini dikirim dari konfigurasi SMTP dinamis Hellom. Jika email ini masuk, maka invitation, reset password, welcome mail, promo, dan billing notification sudah siap dipakai.',
            ctaLabel: null,
            ctaUrl: null,
        );
        // The test must always arrive (no unsubscribe check), like receipts and resets.
        $test->mailCategory = PlatformMailService::CATEGORY_TRANSACTIONAL;
        $delivery = $this->mailService->sendTo(strtolower((string) $validated['email']), $test);

        return $this->ok([
            'delivery' => $delivery,
        ], $delivery['sent'] ? 'Test email sent' : 'Test email failed');
    }

    /** Pengaturan Email › Cek domain: SPF / DKIM / DMARC / alignment of the sender domain. */
    public function dnsCheck(MailDomainCheck $check): JsonResponse
    {
        $settings = $this->mailService->getSettings();

        return $this->ok($check->check((string) $settings['from_address'], (string) $settings['host'], (string) $settings['username']), 'Domain checked');
    }

    /** Pengaturan Email › Riwayat: last 90 days of email attempts. */
    public function logs(Request $request): JsonResponse
    {
        $query = EmailLog::query()->orderByDesc('id');
        $status = (string) $request->query('status', '');
        if (in_array($status, [EmailLog::STATUS_SENT, EmailLog::STATUS_FAILED, EmailLog::STATUS_SKIPPED], true)) {
            $query->where('status', $status);
        }
        $search = trim((string) $request->query('q', ''));
        if ($search !== '') {
            $like = '%' . addcslashes(mb_substr($search, 0, 100), '%_\\') . '%';
            $query->where(fn ($q) => $q->where('to_email', 'like', $like)->orWhere('subject', 'like', $like));
        }
        $page = $query->paginate(max(1, min((int) $request->query('per_page', 20), 100)));

        $since = now()->subDay();

        return $this->ok([
            'data' => collect($page->items())->map(fn (EmailLog $log) => [
                'id' => $log->id,
                'to_email' => $log->to_email,
                'subject' => $log->subject,
                'mailable' => $log->mailable,
                'category' => $log->category,
                'status' => $log->status,
                'error' => $log->error,
                'attempts' => $log->attempts,
                'can_resend' => $log->status === EmailLog::STATUS_FAILED && $log->getRawOriginal('payload') !== null,
                'created_at' => $log->created_at?->toISOString(),
            ])->all(),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
            'summary' => [
                'sent_24h' => EmailLog::query()->where('status', EmailLog::STATUS_SENT)->where('created_at', '>=', $since)->count(),
                'failed_24h' => EmailLog::query()->where('status', EmailLog::STATUS_FAILED)->where('created_at', '>=', $since)->count(),
            ],
        ], 'Email logs loaded');
    }

    public function resend(Request $request, int $id): JsonResponse
    {
        $log = EmailLog::query()->findOrFail($id);
        if ($log->status !== EmailLog::STATUS_FAILED) {
            return $this->fail('Hanya email yang gagal yang bisa dikirim ulang.', ['code' => 'NOT_FAILED'], 422);
        }

        $result = $this->mailService->resend($log);
        $this->adminAudit($request, 'admin.mail.resend', 'email_log', $log->id, ['sent' => $result['sent'], 'subject' => $log->subject]);

        return $result['sent']
            ? $this->ok(['delivery' => $result], 'Email terkirim ulang')
            : $this->fail($result['error'] ?: 'Email masih gagal terkirim', ['code' => 'RESEND_FAILED', 'delivery' => $result], 422);
    }
}
