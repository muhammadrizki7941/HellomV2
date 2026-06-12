<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Reservation;
use App\Services\Cache\TenantCache;
use Illuminate\Http\JsonResponse;

class NotificationController extends Controller
{
    public function counts(): JsonResponse
    {
        $cache = app(TenantCache::class);

        $counts = $cache->remember('notification_counts', 30, function () {
            return [
                'orders_new' => (int) Order::query()->where('status', Order::STATUS_NEW)->count(),
                'reservations_pending' => (int) Reservation::query()->where('status', 'pending')->count(),
            ];
        });

        return response()->json($counts);
    }
}
