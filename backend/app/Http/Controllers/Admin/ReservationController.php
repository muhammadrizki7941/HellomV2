<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Services\Realtime\RealtimeClient;
use Illuminate\Http\Request;

class ReservationController extends Controller
{
    public function index(Request $request)
    {
        $status = $request->query('status', 'all');

        $q = Reservation::query()
            ->orderByDesc('scheduled_at')
            ->orderByDesc('id');

        if ($status !== 'all') {
            $q->where('status', $status);
        }

        $reservations = $q->limit(200)->get();

        return view('admin.reservations.index', [
            'reservations' => $reservations,
            'status' => $status,
        ]);
    }

    public function show(Request $request, string $reservation)
    {
        $reservationModel = Reservation::where('id', $reservation)
            ->firstOrFail();
        
        return view('admin.reservations.show', [
            'reservation' => $reservationModel,
        ]);
    }

    public function updateStatus(Request $request, string $reservation, RealtimeClient $realtime)
    {
        $reservationModel = Reservation::where('id', $reservation)
            ->firstOrFail();
        
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:pending,confirmed,cancelled,completed'],
            'admin_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $reservationModel->update([
            'status' => $validated['status'],
            'admin_notes' => $validated['admin_notes'] ?? null,
        ]);

        $realtime->emit('reservation.updated', $reservationModel->toArray(), $reservationModel->tenant_id ?? null);

        return back();
    }
}
