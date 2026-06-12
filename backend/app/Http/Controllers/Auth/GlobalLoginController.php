<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\DummyAuthService;
use App\Services\Auth\RoleRouter;
use Illuminate\Http\Request;

class GlobalLoginController extends Controller
{
    public function __construct(
        private readonly DummyAuthService $auth,
        private readonly RoleRouter $router,
    ) {
    }

    public function show(Request $request)
    {
        $user = $this->auth->currentGlobalUser($request);
        if ($user) {
            return redirect($this->router->postLoginPath($user));
        }

        return view('auth.login');
    }

    public function login(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = $this->auth->attemptGlobalLogin($request, (string) $validated['email'], (string) $validated['password']);
        if (!$user) {
            return back()->withErrors(['email' => 'Login gagal (dummy auth).'])->withInput();
        }

        return redirect($this->router->postLoginPath($user));
    }

    public function logout(Request $request)
    {
        $this->auth->logoutGlobal($request);

        return redirect('/login');
    }
}
