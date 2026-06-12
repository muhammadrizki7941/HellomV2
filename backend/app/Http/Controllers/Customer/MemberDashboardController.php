<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Reservation;
use App\Models\MemberPromotion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class MemberDashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            abort(403);
        }

        $orders = Order::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        $reservations = Reservation::query()
            ->where('user_id', $user->id)
            ->orderByDesc('scheduled_at')
            ->limit(20)
            ->get();

        $promotions = MemberPromotion::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return view('customer.member.dashboard', [
            'user' => $user,
            'orders' => $orders,
            'reservations' => $reservations,
            'promotions' => $promotions,
        ]);
    }
}
