<?php

namespace App\Http\Controllers\Api\V1\Hellom;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvoiceController extends BaseApiController
{
    /** Member: list invoices for current org. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail(__('hellom.unauthorized'), ['code' => 'UNAUTHORIZED'], 401);
        }

        $organizationId = (int) ($user->current_organization_id ?? 0);
        if ($organizationId <= 0) {
            return $this->fail(__('hellom.no_active_organization'), ['code' => 'NO_ACTIVE_ORGANIZATION'], 403);
        }

        $limit = max(1, min((int) ($request->query('limit') ?: 30), 100));

        $invoices = Invoice::query()
            ->where('organization_id', $organizationId)
            ->orderByDesc('issued_at')
            ->limit($limit)
            ->get();

        return $this->ok(['items' => $invoices], 'Invoices');
    }

    /** Member: show single invoice. */
    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail(__('hellom.unauthorized'), ['code' => 'UNAUTHORIZED'], 401);
        }

        $organizationId = (int) ($user->current_organization_id ?? 0);

        $invoice = Invoice::query()
            ->where('id', $id)
            ->where('organization_id', $organizationId)
            ->first();

        if (!$invoice) {
            return $this->fail('Invoice not found', ['code' => 'INVOICE_NOT_FOUND'], 404);
        }

        return $this->ok($invoice, 'Invoice detail');
    }

    /** Admin: list all invoices across orgs. */
    public function adminIndex(Request $request): JsonResponse
    {
        $limit = max(1, min((int) ($request->query('limit') ?: 50), 200));

        $query = Invoice::query()
            ->with('organization')
            ->orderByDesc('issued_at');

        if ($request->filled('organization_id')) {
            $query->where('organization_id', (int) $request->query('organization_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }

        $invoices = $query->limit($limit)->get();

        return $this->ok(['items' => $invoices], 'All invoices');
    }

    // ─── Invoice Generation Helper ───

    public static function generateFromCheckout(
        int $organizationId,
        int $subscriptionId,
        int $amount,
        int $discount,
        string $appSlug,
        string $planSlug,
        string $paymentMethod,
    ): Invoice {
        $invoiceNumber = 'INV-' . strtoupper(date('Ymd')) . '-' . strtoupper(\Illuminate\Support\Str::random(6));

        $tax = 0; // no tax logic yet
        $total = max(0, $amount - $discount);

        return Invoice::query()->create([
            'organization_id' => $organizationId,
            'subscription_id' => $subscriptionId,
            'invoice_number' => $invoiceNumber,
            'status' => 'paid',
            'amount' => $amount,
            'tax' => $tax,
            'total' => $total,
            'currency' => config('app.currency', 'IDR'),
            'line_items' => [
                [
                    'description' => "{$appSlug} - {$planSlug}",
                    'amount' => $amount,
                    'discount' => $discount,
                ],
            ],
            'issued_at' => now(),
            'due_at' => now(),
            'paid_at' => now(),
            'metadata' => [
                'payment_method' => $paymentMethod,
                'discount' => $discount,
            ],
        ]);
    }
}
