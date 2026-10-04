<?php

namespace App\Http\Controllers\Api\V1\Hellom;

use App\Mail\HellomAnnouncementMail;
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

        $delivery = $this->mailService->sendTo(
            strtolower((string) $validated['email']),
            new HellomAnnouncementMail(
                subjectLine: 'Tes email SMTP Hellom',
                heading: 'SMTP Hellom aktif',
                body: 'Email tes ini dikirim dari konfigurasi SMTP dinamis Hellom. Jika email ini masuk, maka invitation, reset password, welcome mail, promo, dan billing notification sudah siap dipakai.',
                ctaLabel: null,
                ctaUrl: null,
            )
        );

        return $this->ok([
            'delivery' => $delivery,
        ], $delivery['sent'] ? 'Test email sent' : 'Test email failed');
    }
}
