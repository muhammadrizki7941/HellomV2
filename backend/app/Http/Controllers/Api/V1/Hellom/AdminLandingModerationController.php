<?php

namespace App\Http\Controllers\Api\V1\Hellom;

use App\Models\AuditLog;
use App\Models\LandingPageOrder;
use App\Models\LandingProduct;
use App\Models\LandingReport;
use App\Models\Organization;
use App\Models\SellerBalance;
use App\Services\Landing\SellerTrust;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Super admin › Moderasi Toko: reports from public pages, switching sellers and products
 * off, and holding a problematic seller's balance (seller_balances.is_frozen).
 */
class AdminLandingModerationController extends BaseApiController
{
    public function __construct(private readonly SellerTrust $trust)
    {
    }

    public function reports(Request $request): JsonResponse
    {
        $status = (string) $request->query('status', 'open');
        $reports = LandingReport::query()
            ->with(['organization:id,name,slug,landing_suspended_at', 'product:id,public_id,name,organization_id,admin_disabled_at'])
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->orderByDesc('id')
            ->paginate(20);
        $reports->getCollection()->transform(fn (LandingReport $r) => [
            'id' => $r->id,
            'reason' => $r->reason,
            'reason_label' => LandingReport::REASONS[$r->reason] ?? $r->reason,
            'description' => $r->description,
            'reporter_email' => $r->reporter_email,
            'page_url' => $r->page_url,
            'status' => $r->status,
            'resolution_note' => $r->resolution_note,
            'created_at' => optional($r->created_at)->toIso8601String(),
            'handled_at' => optional($r->handled_at)->toIso8601String(),
            'organization' => $r->organization ? ['id' => $r->organization->id, 'name' => $r->organization->name, 'slug' => $r->organization->slug,
                'suspended' => $r->organization->landing_suspended_at !== null] : null,
            'product' => $r->product ? ['id' => $r->product->id, 'public_id' => $r->product->public_id, 'name' => $r->product->name,
                'disabled' => $r->product->admin_disabled_at !== null] : null,
        ]);

        return $this->ok($reports, 'Laporan');
    }

    public function updateReport(Request $request, int $reportId): JsonResponse
    {
        $report = LandingReport::query()->find($reportId);
        if (!$report) {
            return $this->fail('Laporan tidak ditemukan', ['code' => 'REPORT_NOT_FOUND'], 404);
        }
        $validated = $request->validate([
            'status' => ['required', 'in:' . implode(',', LandingReport::STATUSES)],
            'resolution_note' => ['nullable', 'string', 'max:500'],
        ]);
        $report->forceFill($validated + ['handled_by_user_id' => $request->user()?->id, 'handled_at' => now()])->save();

        return $this->ok(['id' => $report->id, 'status' => $report->status], 'Laporan diperbarui');
    }

    public function sellers(Request $request): JsonResponse
    {
        $validated = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'filter' => ['nullable', 'in:all,suspended,frozen,reported']]);
        $query = Organization::query()
            ->where(fn ($q) => $q->whereIn('id', LandingProduct::withTrashed()->select('organization_id'))
                ->orWhereIn('id', LandingPageOrder::query()->select('organization_id')))
            ->withCount(['landingReports as open_reports' => fn ($q) => $q->where('status', 'open')]);
        if (!empty($validated['q'])) {
            $q = '%' . str_replace(['%', '_'], ['\%', '\_'], trim($validated['q'])) . '%';
            $query->where(fn ($w) => $w->where('name', 'like', $q)->orWhere('slug', 'like', $q));
        }
        match ($validated['filter'] ?? 'all') {
            'suspended' => $query->whereNotNull('landing_suspended_at'),
            'frozen' => $query->whereIn('id', SellerBalance::query()->where('is_frozen', true)->select('organization_id')),
            'reported' => $query->whereHas('landingReports', fn ($q) => $q->where('status', 'open')),
            default => null,
        };
        $sellers = $query->orderByDesc('open_reports')->orderBy('name')->paginate(20);
        $balances = SellerBalance::query()->whereIn('organization_id', $sellers->getCollection()->pluck('id'))->get()->keyBy('organization_id');
        $sellers->getCollection()->transform(fn (Organization $o) => [
            'id' => $o->id,
            'name' => $o->name,
            'slug' => $o->slug,
            'verified' => $this->trust->isVerified((int) $o->id),
            'suspended' => $o->landing_suspended_at !== null,
            'suspended_reason' => $o->landing_suspended_reason,
            'balance_frozen' => (bool) ($balances[$o->id]->is_frozen ?? false),
            'balance_available' => (int) ($balances[$o->id]->available ?? 0),
            'balance_pending' => (int) ($balances[$o->id]->pending ?? 0),
            'open_reports' => (int) $o->open_reports,
            'products' => LandingProduct::query()->where('organization_id', $o->id)->count(),
            'paid_orders' => LandingPageOrder::query()->where('organization_id', $o->id)->whereIn('status', [LandingPageOrder::STATUS_PAID, LandingPageOrder::STATUS_FULFILLED])->count(),
        ]);

        return $this->ok($sellers, 'Penjual');
    }

    /** Switch a seller's public pages and checkout off/on. */
    public function suspendSeller(Request $request, int $organizationId): JsonResponse
    {
        $organization = Organization::query()->find($organizationId);
        if (!$organization) {
            return $this->fail('Penjual tidak ditemukan', ['code' => 'SELLER_NOT_FOUND'], 404);
        }
        $validated = $request->validate(['suspended' => ['required', 'boolean'], 'reason' => ['required_if:suspended,true', 'nullable', 'string', 'max:255']]);
        $before = ['suspended' => $organization->landing_suspended_at !== null];
        $organization->forceFill([
            'landing_suspended_at' => $validated['suspended'] ? now() : null,
            'landing_suspended_reason' => $validated['suspended'] ? $validated['reason'] : null,
        ])->save();
        AuditLog::record('landing.seller_' . ($validated['suspended'] ? 'suspended' : 'unsuspended'), $request->user()?->id, $organization->id, 'organization', $organization->id,
            $before, $validated, null, $request->ip());

        return $this->ok(['suspended' => $validated['suspended']], $validated['suspended'] ? 'Toko dinonaktifkan' : 'Toko diaktifkan lagi');
    }

    public function products(Request $request): JsonResponse
    {
        $validated = $request->validate(['organization_id' => ['nullable', 'integer'], 'q' => ['nullable', 'string', 'max:100']]);
        $products = LandingProduct::query()->with('organization:id,name,slug')
            ->when(!empty($validated['organization_id']), fn ($q) => $q->where('organization_id', (int) $validated['organization_id']))
            ->when(!empty($validated['q']), fn ($q) => $q->where('name', 'like', '%' . str_replace(['%', '_'], ['\%', '\_'], trim($validated['q'])) . '%'))
            ->orderByDesc('id')->paginate(20);
        $products->getCollection()->transform(fn (LandingProduct $p) => [
            'id' => $p->id,
            'public_id' => $p->public_id,
            'name' => $p->name,
            'type' => $p->type,
            'type_label' => LandingProduct::TYPE_LABELS[$p->type] ?? $p->type,
            'price' => (int) $p->price,
            'is_active' => (bool) $p->is_active,
            'sold_count' => (int) $p->sold_count,
            'disabled' => $p->admin_disabled_at !== null,
            'disabled_reason' => $p->admin_disabled_reason,
            'organization' => $p->organization ? ['id' => $p->organization->id, 'name' => $p->organization->name, 'slug' => $p->organization->slug] : null,
        ]);

        return $this->ok($products, 'Produk');
    }

    public function disableProduct(Request $request, int $productId): JsonResponse
    {
        $product = LandingProduct::query()->find($productId);
        if (!$product) {
            return $this->fail('Produk tidak ditemukan', ['code' => 'PRODUCT_NOT_FOUND'], 404);
        }
        $validated = $request->validate(['disabled' => ['required', 'boolean'], 'reason' => ['required_if:disabled,true', 'nullable', 'string', 'max:255']]);
        $product->forceFill([
            'admin_disabled_at' => $validated['disabled'] ? now() : null,
            'admin_disabled_reason' => $validated['disabled'] ? $validated['reason'] : null,
        ])->save();
        AuditLog::record('landing.product_' . ($validated['disabled'] ? 'disabled' : 'enabled'), $request->user()?->id, (int) $product->organization_id, 'landing_product', $product->id,
            null, $validated, null, $request->ip());

        return $this->ok(['disabled' => $validated['disabled']], $validated['disabled'] ? 'Produk dinonaktifkan' : 'Produk diaktifkan lagi');
    }
}
