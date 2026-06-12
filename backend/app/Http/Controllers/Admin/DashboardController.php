<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\Category;
use App\Models\BrandSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        // Get brand settings
        $brand = BrandSetting::current();

        // Get today's date range
        $today = now()->startOfDay();
        $todayEnd = now()->endOfDay();

        // Today's orders count
        $todayOrdersCount = Order::whereBetween('created_at', [$today, $todayEnd])->count();

        // Today's revenue
        $todayRevenue = Order::whereBetween('created_at', [$today, $todayEnd])
            ->where('status', '!=', Order::STATUS_CANCELLED)
            ->sum('total_amount');

        // Total products
        $totalProducts = Product::count();

        // Total categories
        $totalCategories = Category::count();

        // Total customers (members)
        $totalCustomers = User::where('role', 'member')->count();

        // Recent orders
        $recentOrders = Order::with(['user', 'diningTable'])
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        // Low stock products (if track_stock is enabled)
        $lowStockProducts = Product::where('track_stock', true)
            ->where('stock', '<=', 10)
            ->where('stock', '>', 0)
            ->limit(5)
            ->get();

        return view('dashboard', compact(
            'todayOrdersCount',
            'todayRevenue',
            'totalProducts',
            'totalCategories',
            'totalCustomers',
            'recentOrders',
            'lowStockProducts',
            'brand'
        ));
    }
}
