<?php

namespace App\Http\Controllers\Api\V1\Hellom;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\DiningTable;
use App\Models\Organization;
use App\Models\Order;
use App\Models\PosLoyaltySetting;
use App\Models\PosMember;
use App\Models\PosPointTransaction;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class OrderController extends Controller
{
    // ─── Helper: ambil tenant slug dari org ───
    private function getTenantSlug(Organization $org): string
    {
        return (string) ($org->pos_tenant_slug ?? $org->slug);
    }

    // ─── Helper: ambil org dari user ───
    private function getOrg(Request $request): ?Organization
    {
        return $request->user()?->currentOrganization;
    }

    // ─── Orders ───
    public function index(Request $request): JsonResponse
    {
        $org = $this->getOrg($request);
        if (!$org) return response()->json(['success' => false, 'message' => 'No organization'], 403);

        $tenantSlug = $this->getTenantSlug($org);

        $orders = \App\Models\Order::query()
            ->with('items')
            ->where('tenant_id', $tenantSlug)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return response()->json([
            'success' => true,
            'data' => ['orders' => $orders],
            'message' => 'Orders retrieved',
        ]);
    }

    public function updateStatus(Request $request, string $orderId): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|string|in:new,accepted,preparing,prepared,completed,cancelled',
        ]);

        $order = \App\Models\Order::findOrFail($orderId);
        $order->update(['status' => $validated['status']]);

        return response()->json([
            'success' => true,
            'data' => ['order' => $order],
            'message' => 'Order status updated',
        ]);
    }

    public function confirmPayment(
        Request $request,
        int $orderId
    ): JsonResponse {
        $tenantSlug = $request->attributes->get('posTenantSlug');
        if (!$tenantSlug) {
            $org = $this->getOrg($request);
            $tenantSlug = $org ? $this->getTenantSlug($org) : null;
        }

        if (!$tenantSlug) {
            return response()->json([
                'success' => false,
                'message' => 'Konteks POS tidak tersedia',
            ], 403);
        }

        $validated = $request->validate([
            'payment_method' => 'required|in:cash,transfer,qris,other',
            'payment_amount' => 'required|integer|min:0',
            'payment_note'   => 'nullable|string|max:200',
        ]);

        /** @var Order $order */
        $order = DB::transaction(function () use ($tenantSlug, $orderId, $validated): Order {
            $order = Order::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenantSlug)
                ->lockForUpdate()
                ->findOrFail($orderId);

            if ($order->payment_status === 'paid') {
                throw new HttpResponseException(response()->json([
                    'success' => false,
                    'message' => 'This order has already been paid',
                ], 422));
            }

            $finalAmount = (int) ($order->final_amount ?? $order->total_amount);

            if ($validated['payment_method'] === 'cash'
                && $validated['payment_amount'] < $finalAmount) {
                throw new HttpResponseException(response()->json([
                    'success' => false,
                    'message' => 'Jumlah bayar kurang dari total pesanan',
                    'data' => [
                        'total'   => $finalAmount,
                        'paid'    => $validated['payment_amount'],
                        'kurang'  => $finalAmount - $validated['payment_amount'],
                    ],
                ], 422));
            }

            $change = max(0, $validated['payment_amount'] - $finalAmount);

            $order->update([
                'payment_method' => $validated['payment_method'],
                'payment_amount' => $validated['payment_amount'],
                'payment_change' => $change,
                'payment_note'   => $validated['payment_note'] ?? null,
                'payment_status' => 'paid',
                'paid_at'        => now(),
                'status'         => 'completed',
            ]);

            return $order->fresh();
        });

        $finalAmount = (int) ($order->final_amount ?? $order->total_amount);
        $change = max(0, (int) $order->payment_amount - $finalAmount);

        // Award poin loyalitas jika ada member (setelah paid)
        if ($order->member_id) {
            try {
                $this->awardLoyaltyPoints($order, $tenantSlug);
            } catch (\Exception $e) {
                // Log error but don't fail the payment
                \Log::error('Failed to award loyalty points', [
                    'order_id' => $order->id,
                    'member_id' => $order->member_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'order' => [
                    'id'             => $order->id,
                    'order_number'   => $order->order_number,
                    'total_amount'   => $finalAmount,
                    'payment_method' => $order->payment_method,
                    'payment_amount' => $order->payment_amount,
                    'payment_change' => $order->payment_change,
                    'payment_status' => 'paid',
                    'status'         => 'completed',
                    'paid_at'        => $order->paid_at,
                ],
                'change_amount' => $change,
            ],
            'message' => 'Payment confirmed successfully! ✅',
        ]);
    }

    // ─── Categories ───
    public function categories(Request $request): JsonResponse
    {
        $org = $this->getOrg($request);
        if (!$org) return response()->json(['success' => false, 'message' => 'No organization'], 403);

        $categories = Category::withoutGlobalScope('tenant')
            ->where('tenant_id', $this->getTenantSlug($org))
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'success' => true,
            'data' => ['categories' => $categories],
            'message' => 'Categories retrieved',
        ]);
    }

    public function createCategory(Request $request): JsonResponse
    {
        $org = $this->getOrg($request);
        if (!$org) return response()->json(['success' => false, 'message' => 'No organization'], 403);

        $tenantSlug = $this->getTenantSlug($org);

        $validated = $request->validate([
            'name'      => 'required|string|max:255',
            'is_active' => 'boolean',
        ]);

        // Slug unik per tenant
        $slug = $base = Str::slug($validated['name']);
        $i = 1;
        while (Category::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantSlug)
            ->where('slug', $slug)->exists()) {
            $slug = $base . '-' . $i++;
        }

        $category = Category::create([
            'tenant_id' => $tenantSlug,
            'name'      => $validated['name'],
            'slug'      => $slug,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json([
            'success' => true,
            'data'    => ['category' => $category],
            'message' => 'Category created',
        ], 201);
    }

    public function updateCategory(Request $request, int $categoryId): JsonResponse
    {
        $org = $this->getOrg($request);
        if (!$org) return response()->json(['success' => false, 'message' => 'No organization'], 403);

        $category = Category::withoutGlobalScope('tenant')
            ->where('tenant_id', $this->getTenantSlug($org))
            ->findOrFail($categoryId);

        $validated = $request->validate([
            'name'      => 'required|string|max:255',
            'is_active' => 'boolean',
        ]);

        // Slug unik per tenant, exclude current category
        $slug = $base = Str::slug($validated['name']);
        $i = 1;
        while (Category::withoutGlobalScope('tenant')
            ->where('tenant_id', $this->getTenantSlug($org))
            ->where('slug', $slug)
            ->where('id', '!=', $categoryId)
            ->exists()) {
            $slug = $base . '-' . $i++;
        }

        $category->update([
            'name'      => $validated['name'],
            'slug'      => $slug,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json([
            'success' => true,
            'data'    => ['category' => $category],
            'message' => 'Category updated',
        ]);
    }

    public function deleteCategory(Request $request, int $categoryId): JsonResponse
    {
        $org = $this->getOrg($request);
        if (!$org) return response()->json(['success' => false, 'message' => 'No organization'], 403);

        $category = Category::withoutGlobalScope('tenant')
            ->where('tenant_id', $this->getTenantSlug($org))
            ->findOrFail($categoryId);

        $category->delete();

        return response()->json(['success' => true, 'message' => 'Category deleted']);
    }

    // ─── Products ───
    public function products(Request $request): JsonResponse
    {
        $org = $this->getOrg($request);
        if (!$org) return response()->json(['success' => false, 'message' => 'No organization'], 403);

        $products = Product::withoutGlobalScope('tenant')
            ->where('tenant_id', $this->getTenantSlug($org))
            ->with('category')
            ->orderBy('category_id')
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => ['products' => $products],
            'message' => 'Products retrieved',
        ]);
    }

    public function createProduct(Request $request): JsonResponse
    {
        $org = $this->getOrg($request);
        if (!$org) return response()->json(['success' => false, 'message' => 'No organization'], 403);

        $tenantSlug = $this->getTenantSlug($org);

        $validated = $request->validate([
            'category_id' => 'required|exists:categories,id',
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string',
            'price'       => 'required|integer|min:0',
            'is_available'=> 'boolean',
            'image'       => 'nullable|image|max:2048',
        ]);

        // Slug unik per tenant
        $slug = $base = Str::slug($validated['name']);
        $i = 1;
        while (Product::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantSlug)
            ->where('slug', $slug)->exists()) {
            $slug = $base . '-' . $i++;
        }

        $imagePath = null;
        if ($request->hasFile('image')) {
            $imagePath = $request->file('image')->store('products', 'public');
        }

        $product = Product::create([
            'tenant_id'   => $tenantSlug,
            'category_id' => $validated['category_id'],
            'name'        => $validated['name'],
            'slug'        => $slug,
            'description' => $validated['description'] ?? null,
            'price'       => $validated['price'],
            'image_path'  => $imagePath,
            'is_available'=> $validated['is_available'] ?? true,
        ]);

        return response()->json([
            'success' => true,
            'data'    => ['product' => $product->load('category')],
            'message' => 'Product created',
        ], 201);
    }

    public function updateProduct(Request $request, int $productId): JsonResponse
    {
        $org = $this->getOrg($request);
        if (!$org) return response()->json(['success' => false, 'message' => 'No organization'], 403);

        $product = Product::withoutGlobalScope('tenant')
            ->where('tenant_id', $this->getTenantSlug($org))
            ->findOrFail($productId);

        $validated = $request->validate([
            'category_id' => 'required|exists:categories,id',
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string',
            'price'       => 'required|integer|min:0',
            'is_available'=> 'boolean',
            'image'       => 'nullable|image|max:2048',
        ]);

        // Slug unik per tenant, exclude current product
        $slug = $base = Str::slug($validated['name']);
        $i = 1;
        while (Product::withoutGlobalScope('tenant')
            ->where('tenant_id', $this->getTenantSlug($org))
            ->where('slug', $slug)
            ->where('id', '!=', $productId)
            ->exists()) {
            $slug = $base . '-' . $i++;
        }

        $imagePath = $product->image_path;
        if ($request->hasFile('image')) {
            if ($imagePath) Storage::disk('public')->delete($imagePath);
            $imagePath = $request->file('image')->store('products', 'public');
        }

        $product->update([
            'category_id' => $validated['category_id'],
            'name'        => $validated['name'],
            'slug'        => $slug,
            'description' => $validated['description'] ?? null,
            'price'       => $validated['price'],
            'image_path'  => $imagePath,
            'is_available'=> $validated['is_available'] ?? true,
        ]);

        return response()->json([
            'success' => true,
            'data'    => ['product' => $product->load('category')],
            'message' => 'Product updated',
        ]);
    }

    public function deleteProduct(Request $request, int $productId): JsonResponse
    {
        $org = $this->getOrg($request);
        if (!$org) return response()->json(['success' => false, 'message' => 'No organization'], 403);

        $product = Product::withoutGlobalScope('tenant')
            ->where('tenant_id', $this->getTenantSlug($org))
            ->findOrFail($productId);

        if ($product->image_path) Storage::disk('public')->delete($product->image_path);
        $product->delete();

        return response()->json(['success' => true, 'message' => 'Product deleted']);
    }

    // ─── Tables ───
    public function tables(Request $request): JsonResponse
    {
        $org = $this->getOrg($request);
        if (!$org) return response()->json(['success' => false, 'message' => 'No organization'], 403);

        $tables = DiningTable::withoutGlobalScope('tenant')
            ->where('tenant_id', $this->getTenantSlug($org))
            ->orderBy('code')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => ['tables' => $tables],
            'message' => 'Tables retrieved',
        ]);
    }

    public function createTable(Request $request): JsonResponse
    {
        $org = $this->getOrg($request);
        if (!$org) return response()->json(['success' => false, 'message' => 'No organization'], 403);

        $validated = $request->validate([
            'code'      => 'required|string|max:16|unique:dining_tables,code',
            'name'      => 'nullable|string|max:80',
            'is_active' => 'boolean',
        ]);

        $table = DiningTable::create([
            'tenant_id' => $this->getTenantSlug($org),
            'public_id' => Str::lower(Str::random(12)),
            'code'      => $validated['code'],
            'name'      => $validated['name'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json([
            'success' => true,
            'data'    => ['table' => $table],
            'message' => 'Table created',
        ], 201);
    }

    public function updateTable(Request $request, int $tableId): JsonResponse
    {
        $org = $this->getOrg($request);
        if (!$org) return response()->json(['success' => false, 'message' => 'No organization'], 403);

        $table = DiningTable::withoutGlobalScope('tenant')
            ->where('tenant_id', $this->getTenantSlug($org))
            ->findOrFail($tableId);

        $validated = $request->validate([
            'code'      => 'required|string|max:16|unique:dining_tables,code,' . $tableId,
            'name'      => 'nullable|string|max:80',
            'is_active' => 'boolean',
        ]);

        $table->update([
            'code'      => $validated['code'],
            'name'      => $validated['name'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json([
            'success' => true,
            'data'    => ['table' => $table],
            'message' => 'Table updated',
        ]);
    }

    public function deleteTable(Request $request, int $tableId): JsonResponse
    {
        $org = $this->getOrg($request);
        if (!$org) return response()->json(['success' => false, 'message' => 'No organization'], 403);

        $table = DiningTable::withoutGlobalScope('tenant')
            ->where('tenant_id', $this->getTenantSlug($org))
            ->findOrFail($tableId);

        $table->delete();

        return response()->json(['success' => true, 'message' => 'Table deleted']);
    }

    private function awardLoyaltyPoints(Order $order, string $tenantSlug): void
    {
        $member = PosMember::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantSlug)
            ->find($order->member_id);

        if (!$member) {
            return;
        }

        $settings = PosLoyaltySetting::currentForTenant($tenantSlug);
        if (!$settings || !$settings->enabled) {
            return;
        }

        $spendAmount = $order->final_amount ?? $order->total_amount ?? 0;
        if ($spendAmount < (int) $settings->min_spend_amount) {
            return;
        }

        $pointsToEarn = $this->calculatePointsToEarn($settings, $spendAmount);

        if ($pointsToEarn <= 0) {
            return;
        }

        // Record transaction
        PosPointTransaction::create([
            'tenant_id' => $tenantSlug,
            'member_id' => $member->id,
            'order_id' => $order->id,
            'type' => 'earn',
            'points' => $pointsToEarn,
            'balance_after' => $member->total_points + $pointsToEarn,
            'description' => "Poin dari pesanan {$order->order_number}",
        ]);

        // Update member
        $member->increment('total_points', $pointsToEarn);
        $member->increment('redeemable_points', $pointsToEarn);
        $member->increment('total_orders');
        $member->increment('total_spent', $spendAmount);
        $member->update(['last_order_at' => now()]);

        // Update order
        $order->update(['points_earned' => $pointsToEarn]);
    }

    private function calculatePointsToEarn(PosLoyaltySetting $settings, int $amount): int
    {
        if (!$settings->enabled) {
            return 0;
        }

        if ($amount < (int) $settings->min_spend_amount) {
            return 0;
        }

        $points = (int) floor($amount / max(1, (int) $settings->points_per_amount));

        if ($settings->max_points_per_order !== null) {
            $points = min($points, (int) $settings->max_points_per_order);
        }

        return max(0, $points);
    }
}
