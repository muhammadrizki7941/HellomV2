<?php

namespace App\Services\SellerFinance;

use App\Jobs\SendPlatformMail;
use App\Models\Organization;
use App\Models\OrganizationPayoutProfile;
use App\Models\SellerLedgerEntry;
use App\Models\SellerWithdrawal;
use App\Models\User;
use App\Services\Finance\JournalRecorder;
use App\Services\Payments\DisbursementRequest;
use App\Services\Payments\GatewayRegistry;
use App\Support\FrontendUrl;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Withdrawals from a seller's sales balance.
 *
 *   requested → processing → paid
 *   requested | processing → failed      (money returned to TERSEDIA via withdrawal_reversal)
 *   requested → cancelled                (by the seller, money returned)
 *
 * The amount leaves TERSEDIA the moment the request is made (ledger row under a row
 * lock), so two requests at the same time can never overdraw. Modes (super admin):
 * manual = admin transfers and uploads proof; auto = disbursement API of the gateway.
 */
final class WithdrawalService
{
    public function __construct(
        private readonly SellerLedger $ledger,
        private readonly FinanceSettings $settings,
        private readonly GatewayRegistry $gateways,
    ) {
    }

    /**
     * Where the money goes and whether withdrawing is possible now.
     *
     * @return array<string, mixed>
     */
    public function payoutAccount(int $organizationId, ?User $user = null): array
    {
        $profile = OrganizationPayoutProfile::query()->where('organization_id', $organizationId)->first();
        $blocked = $this->blockReason($profile, $user);

        return [
            'status' => $profile ? (string) $profile->status : OrganizationPayoutProfile::STATUS_UNVERIFIED,
            'verified' => $profile?->isVerified() ?? false,
            'email_verified' => $user === null || $user->email_verified_at !== null,
            'destination_type' => $profile?->destination_type ?? 'bank',
            'bank_code' => $profile?->bank_code,
            'bank_name' => $profile?->bank_name,
            'account_number_masked' => $profile ? $this->mask((string) $profile->account_number) : null,
            'account_name' => $profile?->account_name,
            'hold_until' => $this->bankChangeHoldUntil($profile)?->toIso8601String(),
            'can_withdraw' => $blocked === null,
            'blocked_reason' => $blocked,
        ];
    }

    public function request(Organization $organization, User $user, int $amount, ?string $notes = null): SellerWithdrawal
    {
        $profile = OrganizationPayoutProfile::query()->where('organization_id', $organization->id)->first();
        if ($reason = $this->blockReason($profile, $user)) {
            throw new FinanceException($reason, 'WITHDRAWAL_BLOCKED');
        }
        $settings = $this->settings->all();
        $minimum = (int) $settings['min_withdrawal'];
        if ($amount < $minimum) {
            throw new FinanceException('Minimal penarikan ' . $this->rupiah($minimum) . '.', 'WITHDRAWAL_BELOW_MINIMUM');
        }
        $fee = (int) $settings['withdrawal_fee_flat'];
        if ($amount - $fee <= 0) {
            throw new FinanceException('Nominal terlalu kecil setelah biaya transfer ' . $this->rupiah($fee) . '.', 'WITHDRAWAL_BELOW_FEE');
        }

        $withdrawal = DB::transaction(function () use ($organization, $user, $amount, $notes, $profile, $fee, $settings): SellerWithdrawal {
            $balance = $this->ledger->lock((int) $organization->id);
            if ($balance->is_frozen) {
                throw new FinanceException('Saldo sedang ditahan oleh tim Hellom. Hubungi dukungan untuk info lebih lanjut.', 'BALANCE_FROZEN');
            }
            if ((int) $balance->available < $amount) {
                throw new FinanceException('Saldo tersedia tidak cukup (' . $this->rupiah((int) $balance->available) . ').', 'INSUFFICIENT_BALANCE');
            }

            $withdrawal = SellerWithdrawal::query()->create([
                'organization_id' => $organization->id,
                'requested_by_user_id' => $user->id,
                'status' => SellerWithdrawal::STATUS_REQUESTED,
                'amount' => $amount,
                'fee_amount' => $fee,
                'net_amount' => $amount - $fee,
                'destination_type' => (string) ($profile->destination_type ?: 'bank'),
                'bank_code' => (string) $profile->bank_code,
                'bank_name' => $profile->bank_name,
                'account_number' => (string) $profile->account_number,
                'account_name' => (string) $profile->account_name,
                'mode' => (string) $settings['withdrawal_mode'],
                'reference' => 'swd_' . Str::upper(Str::random(16)),
                'notes' => $notes,
            ]);

            $this->ledger->post($balance, SellerLedgerEntry::TYPE_WITHDRAWAL, SellerLedgerEntry::BUCKET_AVAILABLE, -$amount, [
                'withdrawal_id' => $withdrawal->id,
                'idempotency_key' => "withdrawal:{$withdrawal->id}",
                'description' => 'Penarikan ke ' . ($profile->bank_name ?: $profile->bank_code) . ' ' . $this->mask((string) $profile->account_number),
                'created_by_user_id' => $user->id,
            ]);
            $balance->processing += $amount;
            $balance->save();

            return $withdrawal;
        }, 3);

        $this->notifySeller($withdrawal, 'Penarikan dana diajukan', 'Permintaan penarikan kamu sudah kami terima dan akan diproses paling lambat 1×24 jam.');

        if ($withdrawal->mode === 'auto') {
            $this->tryAutoDisburse($withdrawal);
        }

        return $withdrawal->fresh();
    }

    public function cancel(SellerWithdrawal $withdrawal, User $user): SellerWithdrawal
    {
        return $this->close($withdrawal, SellerWithdrawal::STATUS_CANCELLED, [SellerWithdrawal::STATUS_REQUESTED], $user, 'Dibatalkan oleh penjual');
    }

    /** Admin takes it (manual transfer), or it is sent through the gateway in auto mode. */
    public function approve(SellerWithdrawal $withdrawal, User $admin): SellerWithdrawal
    {
        $updated = DB::transaction(function () use ($withdrawal, $admin): SellerWithdrawal {
            $locked = SellerWithdrawal::query()->lockForUpdate()->findOrFail($withdrawal->id);
            if ($locked->status !== SellerWithdrawal::STATUS_REQUESTED) {
                throw new FinanceException('Penarikan ini sudah diproses.', 'WITHDRAWAL_NOT_REQUESTED');
            }
            $locked->forceFill(['status' => SellerWithdrawal::STATUS_PROCESSING, 'processing_at' => now(), 'reviewed_by_user_id' => $admin->id])->save();

            return $locked;
        }, 3);
        $this->notifySeller($updated, 'Penarikan dana sedang diproses', 'Tim Hellom sedang mentransfer dana ke rekening kamu.');

        if ($updated->mode === 'auto') {
            $this->tryAutoDisburse($updated);
        }

        return $updated->fresh();
    }

    public function markPaid(SellerWithdrawal $withdrawal, ?User $admin, ?UploadedFile $proof = null, ?string $providerRef = null): SellerWithdrawal
    {
        $updated = DB::transaction(function () use ($withdrawal, $admin, $proof, $providerRef): SellerWithdrawal {
            $locked = SellerWithdrawal::query()->lockForUpdate()->findOrFail($withdrawal->id);
            if (!$locked->isOpen()) {
                throw new FinanceException('Penarikan ini sudah selesai.', 'WITHDRAWAL_CLOSED');
            }
            $balance = $this->ledger->lock((int) $locked->organization_id);
            $balance->processing = max(0, (int) $balance->processing - (int) $locked->amount);
            $balance->withdrawn += (int) $locked->amount;
            $balance->save();

            $locked->forceFill([
                'status' => SellerWithdrawal::STATUS_PAID,
                'paid_at' => now(),
                'processing_at' => $locked->processing_at ?? now(),
                'reviewed_by_user_id' => $admin?->id ?? $locked->reviewed_by_user_id,
                'provider_ref' => $providerRef ?: $locked->provider_ref,
                'proof_path' => $proof ? $proof->store('seller-withdrawals/' . $locked->organization_id, 'local') : $locked->proof_path,
            ])->save();
            JournalRecorder::safely(fn (JournalRecorder $journal) => $journal->withdrawalPaid($locked));

            return $locked;
        }, 3);
        $this->notifySeller($updated, 'Penarikan dana berhasil 🎉', 'Dana sudah ditransfer ke rekening kamu. Waktu masuk tergantung bank/e-wallet tujuan.');

        return $updated;
    }

    public function markFailed(SellerWithdrawal $withdrawal, ?User $admin, string $reason): SellerWithdrawal
    {
        return $this->close($withdrawal, SellerWithdrawal::STATUS_FAILED, SellerWithdrawal::OPEN_STATUSES, $admin, $reason);
    }

    /** Warn super admins about withdrawals close to the 1×24 hour promise. */
    public function slaCheck(): int
    {
        $settings = $this->settings->all();
        $late = SellerWithdrawal::query()
            ->whereIn('status', SellerWithdrawal::OPEN_STATUSES)
            ->whereNull('sla_warned_at')
            ->where('created_at', '<=', now()->subHours((int) $settings['sla_warn_hours']))
            ->with('organization:id,name')
            ->get();
        if ($late->isEmpty()) {
            return 0;
        }

        $admins = User::query()->where('role', 'super_admin')->pluck('email')->filter()->values()->all();
        $details = [];
        foreach ($late as $withdrawal) {
            $details[$withdrawal->reference] = ($withdrawal->organization?->name ?? '#' . $withdrawal->organization_id) . ' · ' . $this->rupiah((int) $withdrawal->amount)
                . ' · diajukan ' . $withdrawal->created_at->diffForHumans();
            $withdrawal->forceFill(['sla_warned_at' => now()])->save();
        }
        if ($admins !== []) {
            SendPlatformMail::dispatch($admins, 'Peringatan: penarikan mendekati batas 1×24 jam', [
                'headline' => count($late) . ' penarikan belum selesai',
                'intro' => 'Penarikan berikut sudah lebih dari ' . $settings['sla_warn_hours'] . ' jam. Batas janji ke penjual: ' . $settings['sla_hours'] . ' jam.',
                'details' => $details,
                'cta_url' => FrontendUrl::to('/admin/keuangan-penjual'),
                'cta_label' => 'Buka antrean penarikan',
            ]);
        }

        return $late->count();
    }

    /** Called when payout details change on an already-verified profile. */
    public function notifyBankChange(OrganizationPayoutProfile $profile): void
    {
        $hours = (int) $this->settings->get('bank_change_hold_hours');
        SendPlatformMail::dispatch($this->ownerEmails((int) $profile->organization_id), 'Rekening penarikan kamu diubah', [
            'headline' => 'Rekening penarikan diubah',
            'intro' => 'Data rekening tujuan penarikan di akun Hellom kamu baru saja diubah. Demi keamanan, penarikan berikutnya ditahan ' . $hours . ' jam setelah verifikasi.',
            'details' => ['Rekening baru' => ($profile->bank_name ?: $profile->bank_code) . ' ' . $this->mask((string) $profile->account_number), 'Atas nama' => (string) $profile->account_name],
            'closing' => 'Bukan kamu yang mengubah? Segera ganti password dan hubungi tim Hellom.',
        ]);
    }

    private function close(SellerWithdrawal $withdrawal, string $status, array $from, ?User $actor, string $reason): SellerWithdrawal
    {
        $updated = DB::transaction(function () use ($withdrawal, $status, $from, $actor, $reason): SellerWithdrawal {
            $locked = SellerWithdrawal::query()->lockForUpdate()->findOrFail($withdrawal->id);
            if (!in_array($locked->status, $from, true)) {
                throw new FinanceException('Status penarikan tidak bisa diubah lagi.', 'WITHDRAWAL_CLOSED');
            }
            $balance = $this->ledger->lock((int) $locked->organization_id);
            $this->ledger->post($balance, SellerLedgerEntry::TYPE_WITHDRAWAL_REVERSAL, SellerLedgerEntry::BUCKET_AVAILABLE, (int) $locked->amount, [
                'withdrawal_id' => $locked->id,
                'idempotency_key' => "withdrawal_reversal:{$locked->id}",
                'description' => $reason,
                'created_by_user_id' => $actor?->id,
            ]);
            $balance->processing = max(0, (int) $balance->processing - (int) $locked->amount);
            $balance->save();

            $locked->forceFill([
                'status' => $status,
                $status === SellerWithdrawal::STATUS_CANCELLED ? 'cancelled_at' : 'failed_at' => now(),
                'failure_reason' => Str::limit($reason, 250, ''),
                'reviewed_by_user_id' => $status === SellerWithdrawal::STATUS_FAILED ? ($actor?->id ?? $locked->reviewed_by_user_id) : $locked->reviewed_by_user_id,
            ])->save();

            return $locked;
        }, 3);

        if ($status === SellerWithdrawal::STATUS_FAILED) {
            $this->notifySeller($updated, 'Penarikan dana gagal', 'Penarikan belum berhasil: ' . $reason . '. Dana sudah kembali ke saldo tersedia kamu.');
        }

        return $updated;
    }

    private function tryAutoDisburse(SellerWithdrawal $withdrawal): void
    {
        $gateway = $this->gateways->disburser();
        if ($gateway === null) {
            return; // no gateway with payouts: stays in the manual queue
        }
        try {
            if ($withdrawal->status === SellerWithdrawal::STATUS_REQUESTED) {
                $withdrawal->forceFill(['status' => SellerWithdrawal::STATUS_PROCESSING, 'processing_at' => now()])->save();
            }
            $result = $gateway->disburse(new DisbursementRequest(
                reference: (string) $withdrawal->reference,
                amount: (int) $withdrawal->net_amount,
                bankCode: (string) $withdrawal->bank_code,
                accountNumber: (string) $withdrawal->account_number,
                accountName: (string) $withdrawal->account_name,
                description: 'Penarikan saldo Hellom ' . $withdrawal->reference,
            ));
            $withdrawal->forceFill(['provider' => $gateway->name(), 'provider_ref' => $result->providerRef])->save();
        } catch (\Throwable $e) {
            report($e);
            $meta = is_array($withdrawal->metadata) ? $withdrawal->metadata : [];
            $meta['auto_error'] = $e->getMessage();
            $withdrawal->forceFill(['metadata' => $meta])->save(); // stays open for manual handling
        }
    }

    private function blockReason(?OrganizationPayoutProfile $profile, ?User $user = null): ?string
    {
        if (!$profile || !$profile->isVerified()) {
            return 'Lengkapi dan tunggu verifikasi data diri (KTP) & rekening sebelum menarik dana.';
        }
        if ($user !== null && $user->email_verified_at === null) {
            return 'Verifikasi email kamu dulu sebelum menarik dana.';
        }
        if (!$this->namesMatch((string) $profile->full_name, (string) $profile->account_name)) {
            return 'Nama pemilik rekening harus sama dengan nama di KTP yang terverifikasi.';
        }
        if ($until = $this->bankChangeHoldUntil($profile)) {
            return 'Rekening baru saja diganti. Penarikan bisa dilakukan lagi mulai ' . $until->timezone('Asia/Jakarta')->format('d M Y H:i') . ' WIB.';
        }

        return null;
    }

    private function bankChangeHoldUntil(?OrganizationPayoutProfile $profile): ?\Carbon\CarbonInterface
    {
        if (!$profile?->bank_changed_at) {
            return null;
        }
        $until = $profile->bank_changed_at->copy()->addHours((int) $this->settings->get('bank_change_hold_hours'));

        return $until->isFuture() ? $until : null;
    }

    /** Bank names are often truncated: one normalised name must contain the other. */
    private function namesMatch(string $kycName, string $accountName): bool
    {
        $norm = fn (string $v) => trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^A-Z ]/', '', strtoupper($v))));
        $a = $norm($kycName);
        $b = $norm($accountName);

        return $a !== '' && $b !== '' && (str_contains($a, $b) || str_contains($b, $a));
    }

    private function notifySeller(SellerWithdrawal $withdrawal, string $headline, string $intro): void
    {
        SendPlatformMail::dispatch($this->ownerEmails((int) $withdrawal->organization_id), $headline . ' — ' . $withdrawal->reference, [
            'headline' => $headline,
            'intro' => $intro,
            'details' => [
                'No. penarikan' => (string) $withdrawal->reference,
                'Nominal' => $this->rupiah((int) $withdrawal->amount),
                'Biaya transfer' => $this->rupiah((int) $withdrawal->fee_amount),
                'Diterima' => $this->rupiah((int) $withdrawal->net_amount),
                'Tujuan' => ($withdrawal->bank_name ?: $withdrawal->bank_code) . ' ' . $this->mask((string) $withdrawal->account_number) . ' a.n. ' . $withdrawal->account_name,
                'Status' => SellerWithdrawal::LABELS[$withdrawal->status] ?? $withdrawal->status,
            ],
            'cta_url' => FrontendUrl::to('/dashboard/apps/landing-builder?tab=saldo'),
            'cta_label' => 'Lihat Saldo Penjualan',
        ]);
    }

    /** @return list<string> */
    private function ownerEmails(int $organizationId): array
    {
        $organization = Organization::query()->with('users')->find($organizationId);

        return $organization ? $organization->users
            ->filter(fn ($member) => in_array((string) ($member->pivot->role ?? ''), ['owner', 'admin'], true))
            ->pluck('email')->filter()->unique()->values()->all() : [];
    }

    private function mask(string $number): string
    {
        $digits = preg_replace('/\s+/', '', $number);

        return strlen((string) $digits) <= 4 ? (string) $digits : str_repeat('•', 4) . substr((string) $digits, -4);
    }

    private function rupiah(int $value): string
    {
        return 'Rp ' . number_format($value, 0, ',', '.');
    }
}
