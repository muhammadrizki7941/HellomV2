<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\ReservationSpace;
use App\Services\Realtime\RealtimeClient;
use App\Services\Reservations\ReservationBookingService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): View
    {
        return view('auth.register', [
            'reservationIntent' => session('reservation.intent'),
        ]);
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request, RealtimeClient $realtime): RedirectResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'phone' => ['required', 'string', 'max:40', 'unique:'.User::class],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'password' => Hash::make($request->password),
            'role' => 'member',
        ]);

        event(new Registered($user));

        Auth::login($user);

        $intent = $request->session()->pull('reservation.intent');
        if (is_array($intent) && isset($intent['reservation_space_id'])) {
            $space = ReservationSpace::query()->find($intent['reservation_space_id']);
            if ($space) {
                try {
                    $reservation = app(ReservationBookingService::class)->createReservation($user, $space, $intent);
                    $realtime->emit('reservation.created', $reservation->toArray(), $reservation->tenant_id);
                } catch (\RuntimeException $e) {
                    // If failed (e.g. conflict), just continue to member dashboard.
                }
            }

            return redirect()->route('member.dashboard', ['tenant' => $request->route('tenant')])->with('success', 'Akun berhasil dibuat & permintaan reservasi terkirim.');
        }

        return redirect()->route('member.dashboard', ['tenant' => $request->route('tenant')]);
    }
}
