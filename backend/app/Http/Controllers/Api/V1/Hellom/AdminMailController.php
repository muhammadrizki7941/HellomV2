<?php

namespace App\Http\Controllers\Api\V1\Hellom;

use App\Mail\HellomAnnouncementMail;
use App\Mail\HellomBillingNotificationMail;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\User;
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
        ]);

        $validated = $this->mailService->normalizeSettings($validated);

        if (!empty($validated['enabled']) && $validated['host'] !== '' && !$this->mailService->isValidSmtpHost($validated['host'])) {
            throw ValidationException::withMessages([
                'host' => 'SMTP host harus berupa hostname server mail, misalnya smtp.gmail.com, bukan alamat email.',
            ]);
        }

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

    public function sendPromo(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'heading' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:5000'],
            'cta_label' => ['nullable', 'string', 'max:120'],
            'cta_url' => ['nullable', 'url', 'max:500'],
            'emails' => ['nullable', 'array', 'max:500'],
            'emails.*' => ['email', 'max:255'],
            'only_active_buyers' => ['nullable', 'boolean'],
        ]);

        $query = User::query();
        if (!empty($validated['only_active_buyers'])) {
            $query->whereHas('organizations.entitlements', fn ($builder) => $builder->where('status', 'active'));
        }

        if (!empty($validated['emails'])) {
            $emails = collect($validated['emails'])->map(fn ($email) => strtolower((string) $email))->values();
            $query->whereIn('email', $emails);
        }

        $recipients = $query->whereNotNull('email')->pluck('email')->map(fn ($email) => strtolower((string) $email))->unique()->values();
        $results = [];

        foreach ($recipients as $email) {
            $results[] = [
                'email' => $email,
                'delivery' => $this->mailService->sendTo(
                    $email,
                    new HellomAnnouncementMail(
                        subjectLine: (string) $validated['subject'],
                        heading: (string) $validated['heading'],
                        body: (string) $validated['message'],
                        ctaLabel: $validated['cta_label'] ?? null,
                        ctaUrl: $validated['cta_url'] ?? null,
                    )
                ),
            ];
        }

        return $this->ok([
            'sent_count' => collect($results)->where('delivery.sent', true)->count(),
            'failed_count' => collect($results)->where('delivery.sent', false)->count(),
            'results' => $results,
        ], 'Promo broadcast completed');
    }

    public function sendBillingReminder(Request $request, int $subscriptionId): JsonResponse
    {
        $subscription = Subscription::query()->with(['organization.users', 'app', 'plan'])->find($subscriptionId);
        if (!$subscription instanceof Subscription) {
            return $this->fail('Subscription not found', ['code' => 'SUBSCRIPTION_NOT_FOUND'], 404);
        }

        $recipients = $subscription->organization
            ? $subscription->organization->users
                ->filter(fn ($member) => in_array((string) ($member->pivot->role ?? ''), ['owner', 'admin', 'super_admin'], true))
                ->pluck('email')
                ->filter()
                ->map(fn ($email) => strtolower((string) $email))
                ->unique()
                ->values()
            : collect();

        $results = [];
        foreach ($recipients as $email) {
            $results[] = [
                'email' => $email,
                'delivery' => $this->mailService->sendTo(
                    $email,
                    new HellomBillingNotificationMail(
                        organizationName: (string) ($subscription->organization?->name ?? 'Hellom'),
                        appName: (string) ($subscription->app?->name ?? 'Aplikasi'),
                        planName: (string) ($subscription->plan?->name ?? 'Plan'),
                        statusLabel: 'Pengingat perpanjangan',
                        amount: (int) $subscription->amount,
                        startsAt: $subscription->starts_at,
                        endsAt: $subscription->ends_at,
                    )
                ),
            ];
        }

        return $this->ok([
            'subscription_id' => $subscriptionId,
            'results' => $results,
        ], 'Billing reminder processed');
    }
}
