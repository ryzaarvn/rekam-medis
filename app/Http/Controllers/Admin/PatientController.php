<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\BlockchainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePatientRequest;
use App\Http\Requests\Admin\SyncPatientDeactivateRequest;
use App\Http\Requests\Admin\SyncPatientOnchainRequest;
use App\Http\Requests\Admin\UpdatePatientRequest;
use App\Models\Authorization;
use App\Models\Patient;
use App\Models\User;
use App\Services\BlockchainService;
use App\Support\CodeGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
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

        $walletChanged = strtolower($patient->user->wallet_address) !== $validated['wallet_address'];

        if ($patient->is_registered_onchain && $walletChanged) {
            $warningMessage = 'Alamat wallet tidak dapat diubah saat akun masih aktif di blockchain Sepolia. Nonaktifkan akun dari blockchain terlebih dahulu agar tidak meninggalkan state aktif di smart contract.';

            return redirect()->route('admin.patients.index')
                ->withInput()
                ->with('warning', $warningMessage)
                ->withErrors(['wallet_address' => $warningMessage]);
        }

        DB::transaction(function () use ($patient, $validated, $walletChanged): void {
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
     * Delete a patient profile and user account after verifying inactive on-chain status and no history.
     */
    public function destroy(Patient $patient): RedirectResponse
    {
        if ($patient->is_registered_onchain) {
            return redirect()->route('admin.patients.index')
                ->with('warning', 'Akun masih aktif di blockchain Sepolia. Nonaktifkan on-chain terlebih dahulu sebelum menghapus data.');
        }

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
     * Verify addPatient() receipt and event on Sepolia before persisting on-chain registration state.
     */
    public function registerOnchain(
        SyncPatientOnchainRequest $request,
        Patient $patient,
        BlockchainService $blockchainService,
    ): JsonResponse|RedirectResponse {
        $validated = $request->validated();
        $patient->loadMissing('user');

        try {
            $blockchainService->verifyPatientAddedTransaction(
                $validated['registration_tx_hash'],
                $request->user()->wallet_address,
                $patient->user->wallet_address,
            );
        } catch (BlockchainException $exception) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $exception->getMessage()], $exception->getHttpStatus());
            }

            return redirect()->route('admin.patients.index')->with('error', $exception->getMessage());
        }

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
     * Verify removePatient() on Sepolia, deactivate patient on-chain, and revoke all active off-chain authorizations.
     */
    public function deactivateOnchain(
        SyncPatientDeactivateRequest $request,
        Patient $patient,
        BlockchainService $blockchainService,
    ): JsonResponse|RedirectResponse {
        $validated = $request->validated();
        $patient->loadMissing('user');

        try {
            $blockchainService->verifyPatientRemovedTransaction(
                $validated['tx_hash'],
                $request->user()->wallet_address,
                $patient->user->wallet_address,
            );
        } catch (BlockchainException $exception) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $exception->getMessage()], $exception->getHttpStatus());
            }

            return redirect()->route('admin.patients.index')->with('error', $exception->getMessage());
        }

        DB::transaction(function () use ($patient): void {
            $patient->update([
                'is_registered_onchain' => false,
            ]);

            Authorization::query()
                ->where('patient_id', $patient->id)
                ->where('is_authorized', true)
                ->update([
                    'is_authorized' => false,
                    'tx_hash' => null,
                ]);
        });

        $message = "Status on-chain pasien {$patient->full_name} ({$patient->patient_code}) berhasil dinonaktifkan dan seluruh otorisasi aktif diputus.";

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'patient' => $patient->fresh('user'),
                'etherscan_url' => "https://sepolia.etherscan.io/tx/{$validated['tx_hash']}",
            ]);
        }

        return redirect()->route('admin.patients.index')->with('status', $message);
    }
}
