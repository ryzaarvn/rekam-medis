<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePatientRequest;
use App\Http\Requests\Admin\SyncPatientOnchainRequest;
use App\Http\Requests\Admin\UpdatePatientRequest;
use App\Models\Patient;
use App\Models\User;
use App\Support\CodeGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class PatientController extends Controller
{
    /**
     * Display the patient management table.
     */
    public function index(): View
    {
        $patients = Patient::query()
            ->with('user')
            ->orderBy('patient_code')
            ->orderBy('id')
            ->get();

        return view('admin.patients', [
            'patients' => $patients,
        ]);
    }

    /**
     * Store a new patient user account and profile atomically.
     */
    public function store(StorePatientRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $patient = DB::transaction(function () use ($validated): Patient {
            $user = User::create([
                'username' => $validated['username'],
                'email' => $validated['email'] ?? null,
                'password' => $validated['password'],
                'role' => User::ROLE_PASIEN,
                'wallet_address' => $validated['wallet_address'],
            ]);

            $patientCode = $validated['patient_code'] ?? CodeGenerator::nextCode(
                'PAT',
                Patient::query()->lockForUpdate()->pluck('patient_code'),
            );

            return Patient::create([
                'patient_code' => $patientCode,
                'user_id' => $user->id,
                'full_name' => $validated['full_name'],
                'date_of_birth' => $validated['date_of_birth'],
                'gender' => $validated['gender'],
                'address' => $validated['address'],
                'is_registered_onchain' => false,
                'registration_tx_hash' => null,
            ]);
        });

        return redirect()->route('admin.patients.index')
            ->with('status', "Data pasien {$patient->full_name} ({$patient->patient_code}) berhasil dibuat. Silakan daftarkan wallet pasien ke blockchain Sepolia.");
    }

    /**
     * Update an existing patient profile and linked user credentials atomically.
     */
    public function update(UpdatePatientRequest $request, Patient $patient): RedirectResponse
    {
        $validated = $request->validated();
        $patient->load('user');

        DB::transaction(function () use ($patient, $validated): void {
            $walletChanged = strtolower($patient->user->wallet_address) !== $validated['wallet_address'];

            $userAttributes = [
                'username' => $validated['username'],
                'email' => $validated['email'] ?? null,
                'wallet_address' => $validated['wallet_address'],
            ];

            if (($validated['password'] ?? null) !== null) {
                $userAttributes['password'] = $validated['password'];
            }

            $patient->user->update($userAttributes);

            $patientAttributes = [
                'patient_code' => $validated['patient_code'],
                'full_name' => $validated['full_name'],
                'date_of_birth' => $validated['date_of_birth'],
                'gender' => $validated['gender'],
                'address' => $validated['address'],
            ];

            if ($walletChanged) {
                $patientAttributes['is_registered_onchain'] = false;
                $patientAttributes['registration_tx_hash'] = null;
            }

            $patient->update($patientAttributes);
        });

        return redirect()->route('admin.patients.index')
            ->with('status', "Data pasien {$patient->full_name} ({$patient->patient_code}) berhasil diperbarui.");
    }

    /**
     * Delete a patient profile and user account when no medical records exist.
     */
    public function destroy(Patient $patient): RedirectResponse
    {
        if ($patient->medicalRecords()->exists()) {
            return redirect()->route('admin.patients.index')
                ->with('error', "Pasien {$patient->full_name} tidak dapat dihapus karena sudah memiliki riwayat rekam medis.");
        }

        $patient->load('user');
        $fullName = $patient->full_name;

        DB::transaction(function () use ($patient): void {
            $user = $patient->user;
            $patient->delete();
            $user?->delete();
        });

        return redirect()->route('admin.patients.index')
            ->with('status', "Data pasien {$fullName} berhasil dihapus.");
    }

    /**
     * Persist Sepolia addPatient() registration transaction hash for the patient.
     */
    public function registerOnchain(SyncPatientOnchainRequest $request, Patient $patient): JsonResponse|RedirectResponse
    {
        $validated = $request->validated();

        $patient->update([
            'is_registered_onchain' => true,
            'registration_tx_hash' => $validated['registration_tx_hash'],
        ]);

        $message = "Pasien {$patient->full_name} ({$patient->patient_code}) berhasil didaftarkan on-chain di Sepolia.";

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'patient' => $patient->fresh('user'),
                'etherscan_url' => "https://sepolia.etherscan.io/tx/{$patient->registration_tx_hash}",
            ]);
        }

        return redirect()->route('admin.patients.index')->with('status', $message);
    }

    /**
     * Mark a patient inactive on-chain after removePatient() succeeds on Sepolia.
     */
    public function deactivateOnchain(Request $request, Patient $patient): JsonResponse|RedirectResponse
    {
        $patient->update([
            'is_registered_onchain' => false,
        ]);

        $message = "Status on-chain pasien {$patient->full_name} ({$patient->patient_code}) berhasil dinonaktifkan.";

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'patient' => $patient->fresh('user'),
            ]);
        }

        return redirect()->route('admin.patients.index')->with('status', $message);
    }
}
