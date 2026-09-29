<?php

namespace App\Http\Controllers\Api\V1\Hellom;

use App\Http\Controllers\Api\V1\Hellom\Concerns\ResolvesSellerOrganization;
use App\Jobs\SendLandingSaleEmails;
use App\Jobs\SendPlatformMail;
use App\Models\LandingPageOrder;
use App\Models\LandingProduct;
use App\Models\LandingRefund;
use App\Models\Organization;
use App\Models\SellerBalance;
use App\Services\Landing\OrderAccessService;
use App\Services\Landing\RefundService;
use App\Services\SellerFinance\FinanceException;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Seller dashboard of Hellom Page: sales summary, orders, buyers. Owner/admin of the
 * current organization; not behind the app subscription (like the sales balance).
 */
class SellerOrderController extends BaseApiController
{
    use ResolvesSellerOrganization;

    private const PAID = [LandingPageOrder::STATUS_PAID, LandingPageOrder::STATUS_FULFILLED];

    public function summary(Request $request): JsonResponse
    {
        [$organization, $error] = $this->sellerOrganization($request);
        if ($error) {
            return $error;
        }
        $tz = 'Asia/Jakarta';
        $today = CarbonImmutable::now($tz)->startOfDay();
        $month = $today->startOfMonth();
        $paid = fn () => LandingPageOrder::query()->where('organization_id', $organization->id)->whereIn('status', self::PAID);

        $days = 30;
        $from = $today->subDays($days - 1);
        $rows = $paid()->where('paid_at', '>=', $from->utc())
            ->get(['paid_at', 'amount', 'net_amount'])
            ->groupBy(fn ($o) => $o->paid_at->timezone($tz)->format('Y-m-d'));
        $chart = [];
        for ($i = 0; $i < $days; $i++) {
            $day = $from->addDays($i)->format('Y-m-d');
            $chart[] = ['date' => $day, 'orders' => isset($rows[$day]) ? $rows[$day]->count() : 0, 'revenue' => isset($rows[$day]) ? (int) $rows[$day]->sum('amount') : 0];
        }

        $recent = LandingPageOrder::query()->where('organization_id', $organization->id)
            ->whereIn('status', array_merge(self::PAID, [LandingPageOrder::STATUS_REFUNDED]))
            ->orderByDesc('paid_at')->limit(5)->get()
            ->map(fn (LandingPageOrder $o) => $this->listPayload($o));

        return $this->ok([
            'today' => ['orders' => $paid()->where('paid_at', '>=', $today->utc())->count(), 'revenue' => (int) $paid()->where('paid_at', '>=', $today->utc())->sum('amount')],
            'month' => ['orders' => $paid()->where('paid_at', '>=', $month->utc())->count(), 'revenue' => (int) $paid()->where('paid_at', '>=', $month->utc())->sum('amount'),
                'net' => (int) $paid()->where('paid_at', '>=', $month->utc())->sum('net_amount')],
            'balance_available' => (int) (SellerBalance::query()->whereKey($organization->id)->value('available') ?? 0),
            'to_process' => LandingPageOrder::query()->where('organization_id', $organization->id)->where('status', LandingPageOrder::STATUS_PAID)
                ->whereIn('product_kind', [LandingProduct::TYPE_PHYSICAL, LandingProduct::TYPE_SERVICE])->count(),
            'products' => LandingProduct::query()->where('organization_id', $organization->id)->count(),
            'chart' => $chart,
            'recent' => $recent,
        ], 'Ringkasan penjualan');
    }

    public function index(Request $request): JsonResponse
    {
        [$organization, $error] = $this->sellerOrganization($request);
        if ($error) {
            return $error;
        }
        $validated = $request->validate([
            'status' => ['nullable', 'in:all,paid,to_process,pending,fulfilled,refunded,expired,failed'],
            'product_id' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $orders = $this->filtered($organization, $validated)->orderByDesc('id')->paginate(20);
        $orders->getCollection()->transform(fn (LandingPageOrder $o) => $this->listPayload($o));

        return $this->ok($orders, 'Pesanan');
    }

    public function show(Request $request, int $orderId): JsonResponse
    {
        [$order, $error] = $this->findOrder($request, $orderId);
        if ($error) {
            return $error;
        }
        $order->load(['refunds', 'product']);

        return $this->ok($this->detailPayload($order), 'Detail pesanan');
    }

    public function resend(Request $request, int $orderId, OrderAccessService $access): JsonResponse
    {
        [$order, $error] = $this->findOrder($request, $orderId);
        if ($error) {
            return $error;
        }
        if (!$order->isPaid()) {
            return $this->fail('Hanya pesanan lunas yang bisa dikirim ulang', ['code' => 'ORDER_NOT_PAID'], 422);
        }
        if (!$access->allowResend($order)) {
            return $this->fail('Email baru saja dikirim. Coba lagi beberapa menit lagi.', ['code' => 'RESEND_TOO_SOON'], 429);
        }
        SendLandingSaleEmails::dispatch((int) $order->id, true);

        return $this->ok(['sent' => true], 'Email akses dikirim ulang ke pembeli');
    }

    /** Physical: shipped with courier + tracking number. Service: done. Moves paid → fulfilled. */
    public function fulfill(Request $request, int $orderId): JsonResponse
    {
        [$order, $error] = $this->findOrder($request, $orderId);
        if ($error) {
            return $error;
        }
        $isPhysical = (string) $order->product_kind === LandingProduct::TYPE_PHYSICAL;
        $validated = $request->validate([
            'courier' => [$isPhysical ? 'required' : 'nullable', 'string', 'max:60'],
            'tracking_number' => [$isPhysical ? 'required' : 'nullable', 'string', 'max:80'],
        ], [], ['courier' => 'kurir', 'tracking_number' => 'nomor resi']);

        $updated = DB::transaction(function () use ($order, $validated, $isPhysical): ?LandingPageOrder {
            $locked = LandingPageOrder::query()->lockForUpdate()->find($order->id);
            if (!$locked || !$locked->isPaid()) {
                return null;
            }
            $locked->forceFill([
                'status' => LandingPageOrder::STATUS_FULFILLED,
                'fulfilled_at' => $locked->fulfilled_at ?? now(),
                'shipping_courier' => $isPhysical ? $validated['courier'] : $locked->shipping_courier,
                'tracking_number' => $isPhysical ? $validated['tracking_number'] : $locked->tracking_number,
                'shipped_at' => $isPhysical ? ($locked->shipped_at ?? now()) : $locked->shipped_at,
            ])->save();

            return $locked;
        }, 3);
        if (!$updated) {
            return $this->fail('Pesanan ini belum lunas atau sudah direfund', ['code' => 'ORDER_NOT_PAID'], 422);
        }

        if ($updated->buyer_email) {
            SendPlatformMail::dispatch([(string) $updated->buyer_email], ($isPhysical ? 'Pesanan dikirim — ' : 'Pesanan selesai — ') . $updated->product_name, [
                'headline' => $isPhysical ? 'Pesanan kamu sudah dikirim 🚚' : 'Pesanan kamu sudah selesai',
                'intro' => $isPhysical ? 'Penjual sudah mengirim pesanan kamu.' : 'Penjual menandai pesanan kamu selesai.',
                'details' => array_filter([
                    'No. pesanan' => (string) $updated->reference_id,
                    'Produk' => (string) $updated->product_name,
                    'Kurir' => $isPhysical ? (string) $updated->shipping_courier : null,
                    'No. resi' => $isPhysical ? (string) $updated->tracking_number : null,
                ]),
                'cta_url' => $updated->accessUrl(),
                'cta_label' => 'Lihat Pesanan',
            ]);
        }

        return $this->ok($this->detailPayload($updated->load(['refunds', 'product'])), $isPhysical ? 'Pesanan ditandai terkirim' : 'Pesanan ditandai selesai');
    }

    public function refund(Request $request, int $orderId, RefundService $refunds): JsonResponse
    {
        [$order, $error] = $this->findOrder($request, $orderId);
        if ($error) {
            return $error;
        }
        $validated = $request->validate([
            'amount' => ['required', 'integer', 'min:1', 'max:100000000'],
            'reason' => ['required', 'string', 'min:5', 'max:490'],
            'destination_type' => ['required', 'in:bank,ewallet'],
            'bank_code' => ['required', 'string', 'max:30'],
            'bank_name' => ['nullable', 'string', 'max:80'],
            'account_number' => ['required', 'string', 'max:50', 'regex:/^[0-9\s-]{5,50}$/'],
            'account_name' => ['required', 'string', 'max:120'],
        ], [], ['amount' => 'nominal refund', 'reason' => 'alasan', 'account_number' => 'nomor rekening', 'account_name' => 'nama pemilik rekening']);

        try {
            $refunds->request($order, $request->user(), $validated);
        } catch (FinanceException $e) {
            return $this->fail($e->getMessage(), ['code' => $e->errorCode], $e->status);
        }

        return $this->ok($this->detailPayload($order->fresh(['refunds', 'product'])), 'Refund diajukan. Saldo kamu sudah dipotong dan tim Hellom akan mentransfer ke pembeli.', 201);
    }

    public function buyers(Request $request): JsonResponse
    {
        [$organization, $error] = $this->sellerOrganization($request);
        if ($error) {
            return $error;
        }
        $validated = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1']]);
        $buyers = $this->buyersQuery($organization, $validated['q'] ?? null)->paginate(20);
        $buyers->getCollection()->transform(fn ($b) => [
            'email' => $b->buyer_email,
            'name' => $b->buyer_name,
            'phone' => $b->buyer_phone,
            'orders' => (int) $b->orders,
            'total_spent' => (int) $b->total_spent,
            'last_order_at' => $b->last_order_at ? CarbonImmutable::parse($b->last_order_at)->toIso8601String() : null,
        ]);

        return $this->ok($buyers, 'Pembeli');
    }

    public function exportBuyers(Request $request): BinaryFileResponse|JsonResponse
    {
        [$organization, $error] = $this->sellerOrganization($request);
        if ($error) {
            return $error;
        }
        [$path, $writer] = $this->xlsx();
        $writer->addRow(Row::fromValues(['Nama', 'Email', 'WhatsApp', 'Jumlah pesanan', 'Total belanja', 'Pesanan terakhir']));
        $this->buyersQuery($organization, null)->chunk(500, function ($rows) use ($writer) {
            foreach ($rows as $b) {
                $writer->addRow(Row::fromValues([$b->buyer_name, $b->buyer_email, $b->buyer_phone, (int) $b->orders, (int) $b->total_spent, (string) $b->last_order_at]));
            }
        });
        $writer->close();

        return response()->download($path, 'pembeli-' . Str::slug((string) $organization->name) . '-' . now()->format('Ymd') . '.xlsx')->deleteFileAfterSend(true);
    }

    public function exportOrders(Request $request): BinaryFileResponse|JsonResponse
    {
        [$organization, $error] = $this->sellerOrganization($request);
        if ($error) {
            return $error;
        }
        $validated = $request->validate([
            'status' => ['nullable', 'in:all,paid,to_process,pending,fulfilled,refunded,expired,failed'],
            'product_id' => ['nullable', 'integer'], 'q' => ['nullable', 'string', 'max:100'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'],
        ]);
        [$path, $writer] = $this->xlsx();
        $writer->addRow(Row::fromValues(['No. pesanan', 'Tanggal', 'Status', 'Produk', 'Jumlah', 'Subtotal', 'Diskon', 'Kupon', 'Ongkir', 'Dibayar',
            'Biaya layanan', 'Bersih', 'Pembeli', 'Email', 'WhatsApp', 'Alamat kirim', 'Kurir', 'Resi', 'Isian pembeli']));
        $this->filtered($organization, $validated)->orderBy('id')->chunk(500, function ($rows) use ($writer) {
            foreach ($rows as $o) {
                $a = is_array($o->shipping_address) ? $o->shipping_address : null;
                $writer->addRow(Row::fromValues([
                    $o->reference_id, optional($o->created_at)->timezone('Asia/Jakarta')->format('Y-m-d H:i'), LandingPageOrder::LABELS[$o->status] ?? $o->status,
                    $o->product_name, (int) $o->quantity, (int) ($o->subtotal_amount ?? $o->amount), (int) $o->discount_amount, (string) $o->coupon_code, (int) $o->shipping_amount,
                    (int) $o->amount, (int) $o->commission_amount, (int) $o->net_amount, $o->buyer_name, $o->buyer_email, $o->buyer_phone,
                    $a ? trim(($a['recipient_name'] ?? '') . ', ' . ($a['address'] ?? '') . ', ' . ($a['city'] ?? '') . ' ' . ($a['postal_code'] ?? '')) : '',
                    $o->shipping_courier, $o->tracking_number,
                    collect((array) $o->custom_fields)->map(fn ($f) => ($f['label'] ?? '') . ': ' . ($f['value'] ?? ''))->implode(' | '),
                ]));
            }
        });
        $writer->close();

        return response()->download($path, 'pesanan-' . Str::slug((string) $organization->name) . '-' . now()->format('Ymd') . '.xlsx')->deleteFileAfterSend(true);
    }

    private function filtered(Organization $organization, array $filters): Builder
    {
        $query = LandingPageOrder::query()->where('organization_id', $organization->id);
        match ($filters['status'] ?? 'all') {
            'paid' => $query->whereIn('status', self::PAID),
            'to_process' => $query->where('status', LandingPageOrder::STATUS_PAID)->whereIn('product_kind', [LandingProduct::TYPE_PHYSICAL, LandingProduct::TYPE_SERVICE]),
            'all' => null,
            default => $query->where('status', $filters['status']),
        };
        if (!empty($filters['product_id'])) {
            $query->where('product_id', (int) $filters['product_id']);
        }
        if (!empty($filters['q'])) {
            $q = '%' . str_replace(['%', '_'], ['\%', '\_'], trim($filters['q'])) . '%';
            $query->where(fn ($w) => $w->where('reference_id', 'like', $q)->orWhere('buyer_name', 'like', $q)
                ->orWhere('buyer_email', 'like', $q)->orWhere('buyer_phone', 'like', $q)->orWhere('product_name', 'like', $q));
        }
        if (!empty($filters['from'])) {
            $query->where('created_at', '>=', CarbonImmutable::parse($filters['from'], 'Asia/Jakarta')->startOfDay()->utc());
        }
        if (!empty($filters['to'])) {
            $query->where('created_at', '<=', CarbonImmutable::parse($filters['to'], 'Asia/Jakarta')->endOfDay()->utc());
        }

        return $query;
    }

    private function buyersQuery(Organization $organization, ?string $search): Builder
    {
        $query = LandingPageOrder::query()
            ->where('organization_id', $organization->id)
            ->whereIn('status', array_merge(self::PAID, [LandingPageOrder::STATUS_REFUNDED]))
            ->whereNotNull('buyer_email')
            ->selectRaw('buyer_email, MAX(buyer_name) AS buyer_name, MAX(buyer_phone) AS buyer_phone, COUNT(*) AS orders, SUM(amount) AS total_spent, MAX(paid_at) AS last_order_at')
            ->groupBy('buyer_email')
            ->orderByDesc('last_order_at');
        if ($search) {
            $q = '%' . str_replace(['%', '_'], ['\%', '\_'], trim($search)) . '%';
            $query->where(fn ($w) => $w->where('buyer_email', 'like', $q)->orWhere('buyer_name', 'like', $q)->orWhere('buyer_phone', 'like', $q));
        }

        return $query;
    }

    /** @return array{0: string, 1: Writer} */
    private function xlsx(): array
    {
        $dir = storage_path('app/exports');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $path = $dir . DIRECTORY_SEPARATOR . Str::random(16) . '.xlsx';
        $writer = new Writer();
        $writer->openToFile($path);

        return [$path, $writer];
    }

    /** @return array{0: ?LandingPageOrder, 1: ?JsonResponse} */
    private function findOrder(Request $request, int $orderId): array
    {
        [$organization, $error] = $this->sellerOrganization($request);
        if ($error) {
            return [null, $error];
        }
        $order = LandingPageOrder::query()->where('organization_id', $organization->id)->find($orderId);

        return $order ? [$order, null] : [null, $this->fail('Pesanan tidak ditemukan', ['code' => 'ORDER_NOT_FOUND'], 404)];
    }

    /** @return array<string, mixed> */
    private function listPayload(LandingPageOrder $o): array
    {
        return [
            'id' => $o->id,
            'reference' => $o->reference_id,
            'status' => $o->status,
            'status_label' => LandingPageOrder::LABELS[$o->status] ?? $o->status,
            'needs_action' => $o->status === LandingPageOrder::STATUS_PAID && in_array((string) $o->product_kind, [LandingProduct::TYPE_PHYSICAL, LandingProduct::TYPE_SERVICE], true),
            'product_name' => $o->product_name,
            'product_type' => $o->product_kind,
            'quantity' => (int) $o->quantity,
            'amount' => (int) $o->amount,
            'net_amount' => (int) $o->net_amount,
            'buyer_name' => $o->buyer_name,
            'buyer_email' => $o->buyer_email,
            'created_at' => optional($o->created_at)->toIso8601String(),
            'paid_at' => optional($o->paid_at)->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function detailPayload(LandingPageOrder $o): array
    {
        $digital = app(OrderAccessService::class)->isDigital($o);

        return $this->listPayload($o) + [
            'buyer_phone' => $o->buyer_phone,
            'subtotal_amount' => (int) ($o->subtotal_amount ?? $o->amount),
            'discount_amount' => (int) $o->discount_amount,
            'coupon_code' => $o->coupon_code,
            'shipping_amount' => (int) $o->shipping_amount,
            'commission_amount' => (int) $o->commission_amount,
            'payment_method' => $o->payment_method,
            'payment_channel' => $o->payment_channel,
            'shipping_address' => $o->shipping_address,
            'custom_fields' => $o->custom_fields ?? [],
            'shipping_courier' => $o->shipping_courier,
            'tracking_number' => $o->tracking_number,
            'shipped_at' => optional($o->shipped_at)->toIso8601String(),
            'fulfilled_at' => optional($o->fulfilled_at)->toIso8601String(),
            'expires_at' => optional($o->expires_at)->toIso8601String(),
            'access' => $digital ? [
                'opens_used' => (int) $o->access_open_count,
                'opens_max' => $o->access_max_opens,
                'downloads_used' => (int) $o->download_count,
                'downloads_max' => $o->download_limit,
                'expires_at' => $o->accessExpiresAt()?->toIso8601String(),
                'last_opened_at' => optional($o->access_last_opened_at)->toIso8601String(),
            ] : null,
            'emails_sent_at' => optional($o->emails_sent_at)->toIso8601String(),
            'email_resend_count' => (int) $o->email_resend_count,
            'oversold' => (bool) data_get($o->metadata, 'oversold', false),
            'refunds' => $o->refunds->map(fn (LandingRefund $r) => [
                'reference' => $r->reference,
                'status' => $r->status,
                'status_label' => LandingRefund::LABELS[$r->status] ?? $r->status,
                'amount' => (int) $r->amount,
                'reason' => $r->reason,
                'failure_reason' => $r->failure_reason,
                'created_at' => optional($r->created_at)->toIso8601String(),
                'paid_at' => optional($r->paid_at)->toIso8601String(),
            ])->values(),
            'can_refund' => $o->isPaid() && $o->refunds->whereIn('status', [LandingRefund::STATUS_REQUESTED, LandingRefund::STATUS_PAID])->isEmpty(),
        ];
    }
}
