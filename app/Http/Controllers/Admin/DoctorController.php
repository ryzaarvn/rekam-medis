<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\BlockchainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreDoctorRequest;
use App\Http\Requests\Admin\SyncDoctorDeactivateRequest;
use App\Http\Requests\Admin\SyncDoctorOnchainRequest;
use App\Http\Requests\Admin\UpdateDoctorRequest;
use App\Models\Authorization;
use App\Models\Doctor;
use App\Models\User;
use App\Services\BlockchainService;
use App\Support\CodeGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
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

        $walletChanged = strtolower($doctor->user->wallet_address) !== $validated['wallet_address'];

        if ($doctor->is_registered_onchain && $walletChanged) {
            $warningMessage = 'Alamat wallet tidak dapat diubah saat akun masih aktif di blockchain Sepolia. Nonaktifkan akun dari blockchain terlebih dahulu agar tidak meninggalkan state aktif di smart contract.';

            return redirect()->route('admin.doctors.index')
                ->withInput()
                ->with('warning', $warningMessage)
                ->withErrors(['wallet_address' => $warningMessage]);
        }

        DB::transaction(function () use ($doctor, $validated, $walletChanged): void {
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
     * Delete a doctor profile and user account after verifying inactive on-chain status and no history.
     */
    public function destroy(Doctor $doctor): RedirectResponse
    {
        if ($doctor->is_registered_onchain) {
            return redirect()->route('admin.doctors.index')
                ->with('warning', 'Akun masih aktif di blockchain Sepolia. Nonaktifkan on-chain terlebih dahulu sebelum menghapus data.');
        }

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
     * Verify addDoctor() receipt and event on Sepolia before persisting on-chain registration state.
     */
    public function registerOnchain(
        SyncDoctorOnchainRequest $request,
        Doctor $doctor,
        BlockchainService $blockchainService,
    ): JsonResponse|RedirectResponse {
        $validated = $request->validated();
        $doctor->loadMissing('user');

        try {
            $blockchainService->verifyDoctorAddedTransaction(
                $validated['registration_tx_hash'],
                $request->user()->wallet_address,
                $doctor->user->wallet_address,
            );
        } catch (BlockchainException $exception) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $exception->getMessage()], $exception->getHttpStatus());
            }

            return redirect()->route('admin.doctors.index')->with('error', $exception->getMessage());
        }

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
     * Verify removeDoctor() on Sepolia, deactivate doctor on-chain, and revoke all active off-chain authorizations.
     */
    public function deactivateOnchain(
        SyncDoctorDeactivateRequest $request,
        Doctor $doctor,
        BlockchainService $blockchainService,
    ): JsonResponse|RedirectResponse {
        $validated = $request->validated();
        $doctor->loadMissing('user');

        try {
            $blockchainService->verifyDoctorRemovedTransaction(
                $validated['tx_hash'],
                $request->user()->wallet_address,
                $doctor->user->wallet_address,
            );
        } catch (BlockchainException $exception) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $exception->getMessage()], $exception->getHttpStatus());
            }

            return redirect()->route('admin.doctors.index')->with('error', $exception->getMessage());
        }

        DB::transaction(function () use ($doctor): void {
            $doctor->update([
                'is_registered_onchain' => false,
            ]);

            Authorization::query()
                ->where('doctor_id', $doctor->id)
                ->where('is_authorized', true)
                ->update([
                    'is_authorized' => false,
                    'tx_hash' => null,
                ]);
        });

        $message = "Status on-chain dokter {$doctor->full_name} ({$doctor->doctor_code}) berhasil dinonaktifkan dan seluruh otorisasi aktif diputus.";

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'doctor' => $doctor->fresh('user'),
                'etherscan_url' => "https://sepolia.etherscan.io/tx/{$validated['tx_hash']}",
            ]);
        }

        return redirect()->route('admin.doctors.index')->with('status', $message);
    }
}
