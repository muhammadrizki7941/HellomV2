<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Models\ReservationSpace;
use App\Models\Product;
use App\Services\Realtime\RealtimeClient;
use App\Services\Reservations\ReservationBookingService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class ReservationController extends Controller
{
    public function index()
    {
        $spaces = ReservationSpace::query()
            ->where('is_active', true)
            ->with(['images', 'items'])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return view('customer.reservations.index', [
            'spaces' => $spaces,
        ]);
    }

    public function show(ReservationSpace $space)
    {
        $space->load(['images', 'items']);

        $menuProducts = Product::query()
            ->where('is_available', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->limit(200)
            ->get();

        return view('customer.reservations.show', [
            'space' => $space,
            'menuProducts' => $menuProducts,
        ]);
    }

    public function store(Request $request, ReservationSpace $space, RealtimeClient $realtime)
    {
        if (!$space->is_active) {
            abort(404);
        }

        $rules = [
            'customer_name' => ['required', 'string', 'max:120'],
            'customer_phone' => ['required', 'string', 'max:40'],
            'customer_email' => ['nullable', 'email', 'max:160'],
            'scheduled_at' => ['required', 'date'],
            'duration_minutes' => ['required', 'integer', 'min:30', 'max:720'],
            'guests_count' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'menu_items' => ['nullable', 'string', 'max:200000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];

        if ((int) ($space->min_menu_total ?? 0) > 0) {
            $rules['menu_items'] = ['required', 'string', 'max:200000'];
        }

        $validated = $request->validate($rules);

        $menuItems = [];
        if (isset($validated['menu_items']) && $validated['menu_items'] !== null && $validated['menu_items'] !== '') {
            $decoded = json_decode((string) $validated['menu_items'], true);
            if (!is_array($decoded)) {
                return back()->withErrors(['menu_items' => 'Format menu tidak valid.'])->withInput();
            }

            foreach ($decoded as $row) {
                if (!is_array($row)) continue;
                $pid = (int) ($row['product_id'] ?? 0);
                $qty = (int) ($row['qty'] ?? 0);
                if ($pid <= 0 || $qty <= 0) continue;
                if ($qty > 99) $qty = 99;
                $menuItems[] = ['product_id' => $pid, 'qty' => $qty];
            }
        }

        $validated['menu_items'] = $menuItems;

        $scheduledAt = Carbon::parse($validated['scheduled_at']);
        $durationMinutes = (int) $validated['duration_minutes'];

        $booking = app(ReservationBookingService::class);

        try {
            $booking->ensureAvailableOrFail($space, $scheduledAt, $durationMinutes);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['scheduled_at' => $e->getMessage()])->withInput();
        }

        $user = Auth::user();
        if (!$user) {
            // Store intent then redirect to register (customer will set password there).
            $request->session()->put('reservation.intent', array_merge($validated, [
                'reservation_space_id' => $space->id,
            ]));

            return redirect()->route('customer.member.register')
                ->with('info', 'Buat akun dulu untuk melanjutkan reservasi.');
        }

        try {
            $reservation = $booking->createReservation($user, $space, $validated);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['scheduled_at' => $e->getMessage()])->withInput();
        }

        $realtime->emit('reservation.created', $reservation->toArray(), $reservation->tenant_id);

        return redirect()->route('member.dashboard')->with('success', 'Permintaan reservasi terkirim. Status: pending.');
    }

    public function availability(Request $request, ReservationSpace $space)
    {
        $validated = $request->validate([
            'scheduled_at' => ['required', 'date'],
            'duration_minutes' => ['required', 'integer', 'min:30', 'max:720'],
        ]);

        $scheduledAt = Carbon::parse($validated['scheduled_at']);
        $durationMinutes = (int) $validated['duration_minutes'];

        $booking = app(ReservationBookingService::class);

        try {
            $booking->ensureAvailableOrFail($space, $scheduledAt, $durationMinutes);
        } catch (\RuntimeException $e) {
            return response()->json([
                'available' => false,
                'message' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'available' => true,
            'message' => 'Jadwal tersedia.',
        ]);
    }

    public function thanks(Reservation $reservation)
    {
        $user = Auth::user();
        if ($reservation->user_id && (!$user || (int) $reservation->user_id !== (int) $user->id)) {
            abort(403);
        }

        return view('customer.reservations.thanks', [
            'reservation' => $reservation,
        ]);
    }
}
