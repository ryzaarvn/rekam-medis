<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterPatientRequest;
use App\Models\Patient;
use App\Models\User;
use App\Support\CodeGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
     * Show the public patient registration form.
     */
    public function showRegister(): View
    {
        return view('auth.register');
    }

    /**
     * Register a new patient account and profile atomically, forcing the patient role.
     */
    public function register(RegisterPatientRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $user = DB::transaction(function () use ($validated): User {
            $user = User::create([
                'username' => $validated['username'],
                'email' => $validated['email'] ?? null,
                'password' => $validated['password'],
                'role' => User::ROLE_PASIEN,
                'wallet_address' => $validated['wallet_address'],
            ]);

            $patientCode = CodeGenerator::nextCode(
                'PAT',
                Patient::query()->lockForUpdate()->pluck('patient_code'),
            );

            Patient::create([
                'patient_code' => $patientCode,
                'user_id' => $user->id,
                'full_name' => $validated['full_name'],
                'date_of_birth' => $validated['date_of_birth'],
                'gender' => $validated['gender'],
                'address' => $validated['address'],
            ]);

            return $user;
        });

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('pasien.dashboard')
            ->with('status', 'Pendaftaran akun pasien berhasil. Selamat datang di Sistem Rekam Medis.');
    }

    /**
     * Log the user out and invalidate the current session.
     */
    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')
            ->with('status', 'Anda telah keluar dari sistem.');
    }
}
