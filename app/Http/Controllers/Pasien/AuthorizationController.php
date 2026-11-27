<?php

namespace App\Http\Controllers\Pasien;

use App\Exceptions\BlockchainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pasien\UpdateAuthorizationRequest;
use App\Models\Authorization;
use App\Models\Doctor;
use App\Models\Patient;
use App\Services\BlockchainService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuthorizationController extends Controller
{
    /**
     * Display active on-chain doctors and the patient's per-doctor authorization state.
     */
    public function index(Request $request): View
    {
        $patient = $this->resolvePatient($request);

        $doctors = Doctor::query()
            ->with('user')
            ->registeredOnchain()
            ->whereNotNull('registration_tx_hash')
            ->orderBy('doctor_code')
            ->orderBy('id')
            ->get();

        $authorizationsByDoctor = $patient->authorizations()
            ->get()
            ->keyBy('doctor_id');

        return view('pasien.authorizations', [
            'patient' => $patient,
            'doctors' => $doctors,
            'authorizationsByDoctor' => $authorizationsByDoctor,
        ]);
    }

    /**
     * Verify grantAccess() on Sepolia and persist authorization state.
     */
    public function grant(
        UpdateAuthorizationRequest $request,
        Doctor $doctor,
        BlockchainService $blockchainService,
    ): JsonResponse|RedirectResponse {
        $patient = $this->resolvePatient($request);

        if ($rejection = $this->rejectIfPatientNotOnchain($request, $patient)) {
            return $rejection;
        }

        if (! $doctor->is_registered_onchain || $doctor->registration_tx_hash === null) {
            $errorMessage = 'Izin tidak dapat diberikan karena dokter belum terdaftar aktif di blockchain Sepolia.';

            if ($request->expectsJson()) {
                return response()->json(['message' => $errorMessage], 422);
            }

            return redirect()->route('pasien.authorizations.index')->with('error', $errorMessage);
        }

        $validated = $request->validated();
        $patient->loadMissing('user');
        $doctor->loadMissing('user');

        try {
            $blockchainService->verifyAccessGrantedTransaction(
                $validated['tx_hash'],
                $patient->user->wallet_address,
                $doctor->user->wallet_address,
            );
        } catch (BlockchainException $exception) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $exception->getMessage()], $exception->getHttpStatus());
            }

            return redirect()->route('pasien.authorizations.index')->with('error', $exception->getMessage());
        }

        $authorization = Authorization::updateOrCreate(
            [
                'patient_id' => $patient->id,
                'doctor_id' => $doctor->id,
            ],
            [
                'is_authorized' => true,
                'tx_hash' => $validated['tx_hash'],
            ],
        );

        $message = "Izin akses berhasil diberikan kepada {$doctor->full_name} ({$doctor->doctor_code}).";

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'authorization' => $authorization,
                'etherscan_url' => "https://sepolia.etherscan.io/tx/{$authorization->tx_hash}",
            ]);
        }

        return redirect()->route('pasien.authorizations.index')->with('status', $message);
    }

    /**
     * Verify revokeAccess() on Sepolia and persist authorization state.
     */
    public function revoke(
        UpdateAuthorizationRequest $request,
        Doctor $doctor,
        BlockchainService $blockchainService,
    ): JsonResponse|RedirectResponse {
        $patient = $this->resolvePatient($request);

        if ($rejection = $this->rejectIfPatientNotOnchain($request, $patient)) {
            return $rejection;
        }

        $validated = $request->validated();
        $patient->loadMissing('user');
        $doctor->loadMissing('user');

        try {
            $blockchainService->verifyAccessRevokedTransaction(
                $validated['tx_hash'],
                $patient->user->wallet_address,
                $doctor->user->wallet_address,
            );
        } catch (BlockchainException $exception) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $exception->getMessage()], $exception->getHttpStatus());
            }

            return redirect()->route('pasien.authorizations.index')->with('error', $exception->getMessage());
        }

        $authorization = Authorization::updateOrCreate(
            [
                'patient_id' => $patient->id,
                'doctor_id' => $doctor->id,
            ],
            [
                'is_authorized' => false,
                'tx_hash' => $validated['tx_hash'],
            ],
        );

        $message = "Izin akses untuk {$doctor->full_name} ({$doctor->doctor_code}) berhasil dicabut.";

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'authorization' => $authorization,
                'etherscan_url' => "https://sepolia.etherscan.io/tx/{$authorization->tx_hash}",
            ]);
        }

        return redirect()->route('pasien.authorizations.index')->with('status', $message);
    }

    /**
     * Reject grant or revoke attempts when the patient wallet is not registered on-chain.
     */
    private function rejectIfPatientNotOnchain(Request $request, Patient $patient): ?JsonResponse
    {
        if ($patient->is_registered_onchain) {
            return null;
        }

        $errorMessage = 'Wallet Anda belum terdaftar/aktif di blockchain Sepolia. Hubungi Admin untuk registrasi on-chain sebelum mengatur izin dokter.';

        if ($request->expectsJson()) {
            return response()->json(['message' => $errorMessage], 403);
        }

        abort(403, $errorMessage);
    }

    /**
     * Resolve the authenticated user's patient profile.
     */
    private function resolvePatient(Request $request): Patient
    {
        $patient = $request->user()?->patient;

        if ($patient === null) {
            abort(403, 'Profil pasien tidak ditemukan pada akun ini.');
        }

        return $patient;
    }
}
