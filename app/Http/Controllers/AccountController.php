<?php

namespace App\Http\Controllers;

use App\Http\Requests\Account\UpdateAccountCredentialsRequest;
use App\Http\Requests\Account\UpdatePasswordRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AccountController extends Controller
{
    /**
     * Display the authenticated user's account credentials and update forms.
     */
    public function edit(Request $request): View
    {
        $user = $request->user()->load(['doctor', 'patient']);

        $layoutMeta = match ($user->role) {
            User::ROLE_ADMIN => [
                'sidebar' => 'admin.partials.sidebar',
                'topbarTitle' => 'Panel Administrator',
                'userDisplay' => 'Admin',
                'roleLabel' => 'Administrator',
                'roleBadgeClass' => 'bg-danger',
            ],
            User::ROLE_DOKTER => [
                'sidebar' => 'dokter.partials.sidebar',
                'topbarTitle' => 'Panel Dokter',
                'userDisplay' => $user->doctor?->full_name ?? $user->username,
                'roleLabel' => 'Dokter',
                'roleBadgeClass' => 'bg-primary',
            ],
            User::ROLE_PASIEN => [
                'sidebar' => 'pasien.partials.sidebar',
                'topbarTitle' => 'Panel Pasien',
                'userDisplay' => $user->patient?->full_name ?? $user->username,
                'roleLabel' => 'Pasien',
                'roleBadgeClass' => 'bg-success',
            ],
            default => abort(403, 'Peran akun tidak dikenali.'),
        };

        return view('account.edit', [
            'user' => $user,
            ...$layoutMeta,
        ]);
    }

    /**
     * Update only the authenticated user's username and optional email off-chain.
     */
    public function updateCredentials(UpdateAccountCredentialsRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $request->user()->update([
            'username' => $validated['username'],
            'email' => $validated['email'] ?? null,
        ]);

        return redirect()
            ->route('account.edit')
            ->with('success', 'Kredensial akun berhasil diperbarui.');
    }

    /**
     * Update only the authenticated user's password off-chain and regenerate the session.
     */
    public function updatePassword(UpdatePasswordRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $request->user()->update([
            'password' => $validated['password'],
        ]);

        $request->session()->regenerate();

        return redirect()
            ->route('account.edit')
            ->with('success', 'Password berhasil diperbarui.');
    }
}
