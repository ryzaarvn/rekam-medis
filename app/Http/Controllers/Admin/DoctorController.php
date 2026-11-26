<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreDoctorRequest;
use App\Http\Requests\Admin\SyncDoctorOnchainRequest;
use App\Http\Requests\Admin\UpdateDoctorRequest;
use App\Models\Doctor;
use App\Models\User;
use App\Support\CodeGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DoctorController extends Controller
{
    /**
     * Display the doctor management table.
     */
    public function index(): View
    {
        $doctors = Doctor::query()
            ->with('user')
            ->orderBy('doctor_code')
            ->orderBy('id')
            ->get();

        return view('admin.doctors', [
            'doctors' => $doctors,
        ]);
    }

    /**
     * Store a new doctor user account and profile atomically.
     */
    public function store(StoreDoctorRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $doctor = DB::transaction(function () use ($validated): Doctor {
            $user = User::create([
                'username' => $validated['username'],
                'email' => $validated['email'] ?? null,
                'password' => $validated['password'],
                'role' => User::ROLE_DOKTER,
                'wallet_address' => $validated['wallet_address'],
            ]);

            $doctorCode = $validated['doctor_code'] ?? CodeGenerator::nextCode(
                'DOC',
                Doctor::query()->lockForUpdate()->pluck('doctor_code'),
            );

            return Doctor::create([
                'doctor_code' => $doctorCode,
                'user_id' => $user->id,
                'full_name' => $validated['full_name'],
                'specialization' => $validated['specialization'],
                'str_number' => $validated['str_number'],
                'is_registered_onchain' => false,
                'registration_tx_hash' => null,
            ]);
        });

        return redirect()->route('admin.doctors.index')
            ->with('status', "Data dokter {$doctor->full_name} ({$doctor->doctor_code}) berhasil dibuat. Silakan daftarkan wallet dokter ke blockchain Sepolia.");
    }

    /**
     * Update an existing doctor profile and linked user credentials atomically.
     */
    public function update(UpdateDoctorRequest $request, Doctor $doctor): RedirectResponse
    {
        $validated = $request->validated();
        $doctor->load('user');

        DB::transaction(function () use ($doctor, $validated): void {
            $walletChanged = strtolower($doctor->user->wallet_address) !== $validated['wallet_address'];

            $userAttributes = [
                'username' => $validated['username'],
                'email' => $validated['email'] ?? null,
                'wallet_address' => $validated['wallet_address'],
            ];

            if (($validated['password'] ?? null) !== null) {
                $userAttributes['password'] = $validated['password'];
            }

            $doctor->user->update($userAttributes);

            $doctorAttributes = [
                'doctor_code' => $validated['doctor_code'],
                'full_name' => $validated['full_name'],
                'specialization' => $validated['specialization'],
                'str_number' => $validated['str_number'],
            ];

            if ($walletChanged) {
                $doctorAttributes['is_registered_onchain'] = false;
                $doctorAttributes['registration_tx_hash'] = null;
            }

            $doctor->update($doctorAttributes);
        });

        return redirect()->route('admin.doctors.index')
            ->with('status', "Data dokter {$doctor->full_name} ({$doctor->doctor_code}) berhasil diperbarui.");
    }

    /**
     * Delete a doctor profile and user account when no medical records or logs exist.
     */
    public function destroy(Doctor $doctor): RedirectResponse
    {
        if ($doctor->medicalRecords()->exists() || $doctor->logBlockchains()->exists()) {
            return redirect()->route('admin.doctors.index')
                ->with('error', "Dokter {$doctor->full_name} tidak dapat dihapus karena sudah memiliki riwayat rekam medis atau log blockchain.");
        }

        $doctor->load('user');
        $fullName = $doctor->full_name;

        DB::transaction(function () use ($doctor): void {
            $user = $doctor->user;
            $doctor->delete();
            $user?->delete();
        });

        return redirect()->route('admin.doctors.index')
            ->with('status', "Data dokter {$fullName} berhasil dihapus.");
    }

    /**
     * Persist Sepolia addDoctor() registration transaction hash for the doctor.
     */
    public function registerOnchain(SyncDoctorOnchainRequest $request, Doctor $doctor): JsonResponse|RedirectResponse
    {
        $validated = $request->validated();

        $doctor->update([
            'is_registered_onchain' => true,
            'registration_tx_hash' => $validated['registration_tx_hash'],
        ]);

        $message = "Dokter {$doctor->full_name} ({$doctor->doctor_code}) berhasil didaftarkan on-chain di Sepolia.";

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'doctor' => $doctor->fresh('user'),
                'etherscan_url' => "https://sepolia.etherscan.io/tx/{$doctor->registration_tx_hash}",
            ]);
        }

        return redirect()->route('admin.doctors.index')->with('status', $message);
    }

    /**
     * Mark a doctor inactive on-chain after removeDoctor() succeeds on Sepolia.
     */
    public function deactivateOnchain(Request $request, Doctor $doctor): JsonResponse|RedirectResponse
    {
        $doctor->update([
            'is_registered_onchain' => false,
        ]);

        $message = "Status on-chain dokter {$doctor->full_name} ({$doctor->doctor_code}) berhasil dinonaktifkan.";

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'doctor' => $doctor->fresh('user'),
            ]);
        }

        return redirect()->route('admin.doctors.index')->with('status', $message);
    }
}
