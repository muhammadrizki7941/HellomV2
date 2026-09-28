<?php

namespace App\Http\Controllers\Api\V1\Hellom;

use App\Models\AuditLog;
use App\Models\LandingPageOrder;
use App\Models\Organization;
use App\Models\PaymentWebhookLog;
use App\Models\SellerBalance;
use App\Models\SellerLedgerEntry;
use App\Models\SellerWithdrawal;
use App\Services\SellerFinance\FinanceException;
use App\Services\SellerFinance\FinanceSettings;
use App\Services\SellerFinance\SellerLedger;
use App\Services\SellerFinance\WithdrawalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Super admin › Keuangan Penjual (routes behind the superAdmin middleware). */
class AdminSellerFinanceController extends BaseApiController
{
    public function __construct(
        private readonly FinanceSettings $settings,
        private readonly SellerLedger $ledger,
        private readonly WithdrawalService $withdrawals,
    ) {
    }

    public function summary(): JsonResponse
    {
        $paid = LandingPageOrder::query()->whereIn('status', [LandingPageOrder::STATUS_PAID, LandingPageOrder::STATUS_FULFILLED, LandingPageOrder::STATUS_REFUNDED]);
        $settings = $this->settings->all();
        $open = SellerWithdrawal::query()->whereIn('status', SellerWithdrawal::OPEN_STATUSES);

        return $this->ok([
            'money_in' => (int) (clone $paid)->sum('amount'),
            'paid_orders' => (int) (clone $paid)->count(),
            'platform_fee' => (int) (clone $paid)->sum('commission_amount'),
            'gateway_fee' => (int) (clone $paid)->sum('gateway_fee_amount'),
            'hellom_net' => (int) (clone $paid)->sum('commission_amount') - (int) (clone $paid)->sum('gateway_fee_amount'),
            // What Hellom owes sellers right now.
            'liabilities' => [
                'pending' => (int) SellerBalance::query()->sum('pending'),
                'available' => (int) SellerBalance::query()->sum('available'),
                'processing' => (int) SellerBalance::query()->sum('processing'),
            ],
            'withdrawn_total' => (int) SellerBalance::query()->sum('withdrawn'),
            'withdrawals_open' => (int) (clone $open)->count(),
            'withdrawals_near_sla' => (int) (clone $open)->where('created_at', '<=', now()->subHours((int) $settings['sla_warn_hours']))->count(),
            'withdrawals_over_sla' => (int) (clone $open)->where('created_at', '<=', now()->subHours((int) $settings['sla_hours']))->count(),
            'orders_pending' => (int) LandingPageOrder::query()->where('status', LandingPageOrder::STATUS_PENDING)->count(),
        ], 'Ringkasan keuangan penjual');
    }

    public function withdrawals(Request $request): JsonResponse
    {
        $settings = $this->settings->all();
        $status = (string) $request->query('status', 'open');
        $query = SellerWithdrawal::query()->with('organization:id,name,slug')->orderBy('id');
        if ($status === 'open') {
            $query->whereIn('status', SellerWithdrawal::OPEN_STATUSES);
        } elseif ($status !== 'all') {
            $query->where('status', $status)->reorder('id', 'desc');
        }
        $items = $query->paginate(30);
        $items->getCollection()->transform(function (SellerWithdrawal $w) use ($settings) {
            $age = (int) $w->created_at->diffInHours(now(), true);

            return SellerFinanceController::withdrawalPayload($w) + [
                'organization' => ['id' => $w->organization_id, 'name' => $w->organization?->name, 'slug' => $w->organization?->slug],
                'account_number' => $w->account_number,        // admin needs the full number to transfer
                'age_hours' => $age,
                'sla' => !$w->isOpen() ? 'done' : ($age >= (int) $settings['sla_hours'] ? 'over' : ($age >= (int) $settings['sla_warn_hours'] ? 'near' : 'ok')),
                'has_proof' => (bool) $w->proof_path,
                'auto_error' => data_get($w->metadata, 'auto_error'),
            ];
        });

        return $this->ok($items, 'Antrean penarikan');
    }

    public function approve(Request $request, int $withdrawalId): JsonResponse
    {
        return $this->act($request, $withdrawalId, 'approve', fn (SellerWithdrawal $w) => $this->withdrawals->approve($w, $request->user()));
    }

    /** FormData: proof (image/pdf, optional), provider_ref (optional). */
    public function markPaid(Request $request, int $withdrawalId): JsonResponse
    {
        $validated = $request->validate([
            'proof' => ['nullable', 'file', 'max:4096', 'mimes:jpg,jpeg,png,webp,pdf'],
            'provider_ref' => ['nullable', 'string', 'max:120'],
        ]);

        return $this->act($request, $withdrawalId, 'mark_paid',
            fn (SellerWithdrawal $w) => $this->withdrawals->markPaid($w, $request->user(), $request->file('proof'), $validated['provider_ref'] ?? null));
    }

    public function markFailed(Request $request, int $withdrawalId): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:250']]);

        return $this->act($request, $withdrawalId, 'mark_failed', fn (SellerWithdrawal $w) => $this->withdrawals->markFailed($w, $request->user(), $validated['reason']));
    }

    public function proof(int $withdrawalId): StreamedResponse|JsonResponse
    {
        $w = SellerWithdrawal::query()->find($withdrawalId);
        if (!$w || !$w->proof_path || !Storage::disk('local')->exists($w->proof_path)) {
            return $this->fail('Bukti transfer tidak ditemukan', ['code' => 'PROOF_NOT_FOUND'], 404);
        }

        return Storage::disk('local')->download($w->proof_path, 'bukti-' . $w->reference . '.' . pathinfo($w->proof_path, PATHINFO_EXTENSION));
    }

    public function webhooks(Request $request): JsonResponse
    {
        $query = PaymentWebhookLog::query()->orderByDesc('id');
        foreach (['provider', 'outcome', 'reference'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, (string) $request->query($field));
            }
        }
        $items = $query->paginate(30);
        $items->getCollection()->transform(fn (PaymentWebhookLog $log) => [
            'id' => $log->id,
            'provider' => $log->provider,
            'event_id' => $log->event_id,
            'reference' => $log->reference,
            'signature_valid' => $log->signature_valid,
            'outcome' => $log->outcome,
            'error' => $log->error,
            'ip' => $log->ip,
            'received_at' => optional($log->received_at)->toIso8601String(),
            'payload' => Str::limit((string) $log->payload, 4000),
        ]);

        return $this->ok($items, 'Riwayat webhook');
    }

    /** Sellers whose cached balance differs from the ledger, plus orders that need a look. */
    public function reconciliation(): JsonResponse
    {
        $mismatches = [];
        foreach (SellerBalance::query()->pluck('organization_id') as $orgId) {
            $result = $this->ledger->reconcile((int) $orgId);
            if (!$result['ok']) {
                $mismatches[] = $result + ['organization' => Organization::query()->whereKey($orgId)->value('name')];
            }
        }

        $problemOrders = LandingPageOrder::query()
            ->whereNotNull('metadata->payment_problems')
            ->orderByDesc('id')->limit(50)
            ->get(['id', 'reference_id', 'organization_id', 'status', 'amount', 'metadata', 'created_at'])
            ->map(fn (LandingPageOrder $o) => ['reference' => $o->reference_id, 'status' => $o->status, 'amount' => (int) $o->amount, 'problems' => data_get($o->metadata, 'payment_problems'), 'created_at' => optional($o->created_at)->toIso8601String()]);

        $stalePending = LandingPageOrder::query()
            ->where('status', LandingPageOrder::STATUS_PENDING)
            ->where('created_at', '<=', now()->subHour())
            ->count();

        return $this->ok([
            'checked_sellers' => SellerBalance::query()->count(),
            'mismatches' => $mismatches,
            'problem_orders' => $problemOrders,
            'stale_pending_orders' => $stalePending,
            'checked_at' => now()->toIso8601String(),
        ], 'Laporan rekonsiliasi');
    }

    public function settings(): JsonResponse
    {
        return $this->ok($this->settings->all(), 'Pengaturan keuangan penjual');
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'platform_fee_percent' => ['sometimes', 'numeric', 'min:0', 'max:50'],
            'platform_fee_flat' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'min_margin_flat' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'gateway_fees' => ['sometimes', 'array'],
            'gateway_fees.*.percent' => ['numeric', 'min:0', 'max:20'],
            'gateway_fees.*.flat' => ['integer', 'min:0', 'max:1000000'],
            'hold_days' => ['sometimes', 'integer', 'min:0', 'max:60'],
            'new_seller_hold_days' => ['sometimes', 'integer', 'min:0', 'max:60'],
            'new_seller_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'min_withdrawal' => ['sometimes', 'integer', 'min:10000', 'max:100000000'],
            'withdrawal_fee_flat' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'withdrawal_mode' => ['sometimes', 'in:manual,auto'],
            'order_expiry_hours' => ['sometimes', 'integer', 'min:1', 'max:168'],
            'sla_hours' => ['sometimes', 'integer', 'min:1', 'max:168'],
            'sla_warn_hours' => ['sometimes', 'integer', 'min:1', 'max:168'],
            'bank_change_hold_hours' => ['sometimes', 'integer', 'min:0', 'max:168'],
        ]);
        $before = $this->settings->all();
        $after = $this->settings->update($validated);
        AuditLog::record('seller_finance.settings_updated', $request->user()?->id, null, 'system_setting', null, $before, $after, null, $request->ip(), Str::limit((string) $request->userAgent(), 255, ''));

        return $this->ok($after, 'Pengaturan disimpan');
    }

    /** Hold / release a seller's balance, or set a longer hold for new sales. */
    public function updateSeller(Request $request, int $organizationId): JsonResponse
    {
        $validated = $request->validate([
            'is_frozen' => ['sometimes', 'boolean'],
            'frozen_reason' => ['nullable', 'string', 'max:255'],
            'hold_days_override' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:60'],
        ]);
        if (!Organization::query()->whereKey($organizationId)->exists()) {
            return $this->fail('Organisasi tidak ditemukan', ['code' => 'ORG_NOT_FOUND'], 404);
        }
        $balance = DB::transaction(function () use ($organizationId, $validated) {
            $balance = $this->ledger->lock($organizationId);
            $balance->forceFill(array_intersect_key($validated, array_flip(['is_frozen', 'frozen_reason', 'hold_days_override'])))->save();

            return $balance;
        });
        AuditLog::record('seller_finance.seller_updated', $request->user()?->id, $organizationId, 'seller_balance', $organizationId, null, $validated, null, $request->ip());

        return $this->ok($balance, 'Pengaturan saldo penjual disimpan');
    }

    /** Manual correction (audit-logged ledger row). */
    public function adjustment(Request $request, int $organizationId): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'integer', 'not_in:0', 'min:-100000000', 'max:100000000'],
            'bucket' => ['required', 'in:pending,available'],
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ]);
        if (!Organization::query()->whereKey($organizationId)->exists()) {
            return $this->fail('Organisasi tidak ditemukan', ['code' => 'ORG_NOT_FOUND'], 404);
        }
        try {
            $entry = DB::transaction(function () use ($organizationId, $validated, $request) {
                $balance = $this->ledger->lock($organizationId);
                $current = $validated['bucket'] === 'pending' ? (int) $balance->pending : (int) $balance->available;
                if ($current + (int) $validated['amount'] < 0) {
                    throw new FinanceException('Penyesuaian membuat saldo minus.', 'NEGATIVE_BALANCE');
                }

                return $this->ledger->post($balance, SellerLedgerEntry::TYPE_ADJUSTMENT, $validated['bucket'], (int) $validated['amount'], [
                    'description' => $validated['reason'],
                    'created_by_user_id' => $request->user()?->id,
                ]);
            });
        } catch (FinanceException $e) {
            return $this->fail($e->getMessage(), ['code' => $e->errorCode], $e->status);
        }
        AuditLog::record('seller_finance.adjustment', $request->user()?->id, $organizationId, 'seller_balance_ledger', $entry?->id, null, $validated, null, $request->ip());

        return $this->ok($entry, 'Penyesuaian dicatat');
    }

    /** Excel export: type = withdrawals | ledger | orders, optional from/to (Y-m-d). */
    public function export(Request $request): BinaryFileResponse|JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'in:withdrawals,ledger,orders'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);
        $from = isset($validated['from']) ? now()->parse($validated['from'])->startOfDay() : now()->subDays(30)->startOfDay();
        $to = isset($validated['to']) ? now()->parse($validated['to'])->endOfDay() : now()->endOfDay();

        $dir = storage_path('app/exports');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $filename = sprintf('keuangan-penjual-%s-%s-%s.xlsx', $validated['type'], $from->format('Ymd'), $to->format('Ymd'));
        $path = $dir . DIRECTORY_SEPARATOR . Str::random(8) . '-' . $filename;

        $writer = new Writer();
        $writer->openToFile($path);
        $names = Organization::query()->pluck('name', 'id');

        if ($validated['type'] === 'withdrawals') {
            $writer->addRow(Row::fromValues(['Referensi', 'Penjual', 'Status', 'Nominal', 'Biaya', 'Diterima', 'Bank/E-wallet', 'No. Rekening', 'Atas nama', 'Mode', 'Diajukan', 'Dibayar', 'Alasan gagal']));
            SellerWithdrawal::query()->whereBetween('created_at', [$from, $to])->orderBy('id')->chunk(500, function ($rows) use ($writer, $names) {
                foreach ($rows as $w) {
                    $writer->addRow(Row::fromValues([$w->reference, $names[$w->organization_id] ?? $w->organization_id, SellerWithdrawal::LABELS[$w->status] ?? $w->status,
                        (int) $w->amount, (int) $w->fee_amount, (int) $w->net_amount, $w->bank_name ?: $w->bank_code, $w->account_number, $w->account_name, $w->mode,
                        optional($w->created_at)->format('Y-m-d H:i'), optional($w->paid_at)->format('Y-m-d H:i'), $w->failure_reason]));
                }
            });
        } elseif ($validated['type'] === 'ledger') {
            $writer->addRow(Row::fromValues(['ID', 'Penjual', 'Tipe', 'Saldo', 'Jumlah', 'Tertahan setelah', 'Tersedia setelah', 'Order', 'Penarikan', 'Keterangan', 'Waktu']));
            SellerLedgerEntry::query()->whereBetween('created_at', [$from, $to])->orderBy('id')->chunk(1000, function ($rows) use ($writer, $names) {
                foreach ($rows as $r) {
                    $writer->addRow(Row::fromValues([$r->id, $names[$r->organization_id] ?? $r->organization_id, SellerLedgerEntry::LABELS[$r->type] ?? $r->type, $r->bucket,
                        (int) $r->amount, (int) $r->pending_after, (int) $r->available_after, $r->order_id, $r->withdrawal_id, $r->description, optional($r->created_at)->format('Y-m-d H:i')]));
                }
            });
        } else {
            $writer->addRow(Row::fromValues(['Referensi', 'Penjual', 'Produk', 'Status', 'Harga', 'Biaya layanan', 'Biaya gateway', 'Bersih penjual', 'Metode', 'Gateway', 'Dibuat', 'Dibayar']));
            LandingPageOrder::query()->whereBetween('created_at', [$from, $to])->orderBy('id')->chunk(500, function ($rows) use ($writer, $names) {
                foreach ($rows as $o) {
                    $writer->addRow(Row::fromValues([$o->reference_id, $names[$o->organization_id] ?? $o->organization_id, $o->product_name, LandingPageOrder::LABELS[$o->status] ?? $o->status,
                        (int) $o->amount, (int) $o->commission_amount, (int) $o->gateway_fee_amount, (int) $o->net_amount, $o->payment_method, $o->provider,
                        optional($o->created_at)->format('Y-m-d H:i'), optional($o->paid_at)->format('Y-m-d H:i')]));
                }
            });
        }
        $writer->close();

        return response()->download($path, $filename)->deleteFileAfterSend(true);
    }

    private function act(Request $request, int $withdrawalId, string $action, callable $callback): JsonResponse
    {
        $withdrawal = SellerWithdrawal::query()->find($withdrawalId);
        if (!$withdrawal) {
            return $this->fail('Penarikan tidak ditemukan', ['code' => 'WITHDRAWAL_NOT_FOUND'], 404);
        }
        try {
            $updated = $callback($withdrawal);
        } catch (FinanceException $e) {
            return $this->fail($e->getMessage(), ['code' => $e->errorCode], $e->status);
        }
        AuditLog::record('seller_finance.withdrawal_' . $action, $request->user()?->id, (int) $withdrawal->organization_id, 'seller_withdrawal', $withdrawal->id,
            ['status' => $withdrawal->status], ['status' => $updated->status], null, $request->ip());

        return $this->ok(['withdrawal' => SellerFinanceController::withdrawalPayload($updated)], 'Penarikan diperbarui');
    }
}
