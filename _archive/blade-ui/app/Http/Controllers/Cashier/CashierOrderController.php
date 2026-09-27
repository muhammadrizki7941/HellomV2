<?php

namespace App\Http\Controllers\Cashier;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantUrlService;
use Illuminate\Http\Request;

class CashierOrderController extends Controller
{
    public function __construct(
        private readonly TenantUrlService $urls,
    )
    {
    }

    public function index(Request $request)
    {
        /** @var TenantContext $tenant */
        $tenant = $request->attributes->get('tenant');

        $basePath = $this->urls->basePathForRequestMode(
            routeTenantParam: $request->route('tenant'),
            tenantSlug: $tenant->slug,
        );

        $orders = Order::query()
            ->with(['items.product', 'items.options'])
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get();

        return view('cashier.orders.index', [
            'tenant' => $tenant,
            'basePath' => $basePath,
            'orders' => $orders,
        ]);
    }

    public function show(Request $request, string $orderNumber)
    {
        /** @var TenantContext $tenant */
        $tenant = $request->attributes->get('tenant');

        $basePath = $this->urls->basePathForRequestMode(
            routeTenantParam: $request->route('tenant'),
            tenantSlug: $tenant->slug,
        );

        $order = Order::query()
            ->with(['items.product', 'items.options'])
            ->where('order_number', $orderNumber)
            ->firstOrFail();

        return view('cashier.orders.show', [
            'tenant' => $tenant,
            'basePath' => $basePath,
            'order' => $order,
        ]);
    }

    public function updateStatus(Request $request, string $orderNumber)
    {
        $request->validate([
            'status' => 'required|in:new,accepted,preparing,completed',
        ]);

        $order = Order::where('order_number', $orderNumber)->firstOrFail();
        $order->status = $request->status;
        $order->save();

        return redirect()->back()->with('success', 'Order status updated.');
    }
}