<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthController extends Controller
{
    /**
     * Show the login form view or redirect an authenticated user to their dashboard.
     */
    public function showLogin(Request $request): View|RedirectResponse
    {
        if ($request->user() !== null) {
            return redirect()->route($request->user()->dashboardRoute());
        }

        return view('auth.login');
    }

    /**
     * Authenticate a user using username and password and redirect by role.
     */
    public function login(LoginRequest $request): RedirectResponse
    {
        $credentials = [
            'username' => $request->string('username')->toString(),
            'password' => $request->string('password')->toString(),
        ];

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            return back()
                ->withErrors(['username' => 'Username atau password salah.'])
                ->onlyInput('username');
        }

        $request->session()->regenerate();

        /** @var User $user */
        $user = Auth::user();

        return redirect()->route($user->dashboardRoute());
    }

    /**
     * Log the user out and invalidate the current session.
     */
    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
