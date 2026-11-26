<?php

namespace App\Http\Controllers\Pasien;

use App\Exceptions\BlockchainException;
use App\Http\Controllers\Controller;
use App\Models\Doctor;
use App\Models\MedicalRecord;
use App\Models\Patient;
use App\Services\BlockchainService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /**
     * Display the authenticated patient's medical records and verification interface.
     */
    public function index(Request $request): View
    {
        $patient = $this->resolvePatient($request);

        $records = $patient->medicalRecords()
            ->with(['doctor', 'latestLogBlockchain', 'logBlockchains.doctor'])
            ->orderByDesc('record_date')
            ->orderByDesc('id')
            ->get();

        return view('pasien.dashboard', [
            'patient' => $patient,
            'records' => $records,
        ]);
    }

    /**
     * Recompute the off-chain SHA-256 hash and verify it against Ethereum Sepolia smart contract state.
     */
    public function verify(
        Request $request,
        MedicalRecord $medicalRecord,
        BlockchainService $blockchainService,
    ): JsonResponse {
        $patient = $this->resolvePatient($request);

        if ($medicalRecord->patient_id !== $patient->id) {
            abort(404);
        }

        $medicalRecord->load(['doctor', 'latestLogBlockchain.doctor']);

        $recomputedHash = $blockchainService->hashMedicalRecord($medicalRecord);

        try {
            $onchainRecord = $blockchainService->getMedicalRecord($medicalRecord->record_code);
            $verifiedOnchain = $blockchainService->verifyMedicalRecord($medicalRecord->record_code, $recomputedHash);
        } catch (BlockchainException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 502);
        }

        $onchainHash = strtolower($onchainRecord['record_hash']);
        $storedCurrentHash = strtolower($medicalRecord->current_hash);
        $isValid = $verifiedOnchain
            && hash_equals($onchainHash, $recomputedHash)
            && hash_equals($storedCurrentHash, $recomputedHash);

        $onchainDoctorAddress = strtolower($onchainRecord['doctor']);
        $matchedDoctor = Doctor::query()
            ->whereHas('user', fn ($query) => $query->where('wallet_address', $onchainDoctorAddress))
            ->first()
            ?? $medicalRecord->latestLogBlockchain?->doctor
            ?? $medicalRecord->doctor;

        $lastDoctorName = $matchedDoctor !== null
            ? "{$matchedDoctor->full_name} ({$matchedDoctor->doctor_code})"
            : $onchainDoctorAddress;

        $latestTxHash = $medicalRecord->latestLogBlockchain?->tx_hash;
        $timestamp = (int) $onchainRecord['timestamp'];

        return response()->json([
            'is_valid' => $isValid,
            'record_code' => $medicalRecord->record_code,
            'recomputed_hash' => $recomputedHash,
            'stored_current_hash' => $storedCurrentHash,
            'onchain_hash' => $onchainHash,
            'onchain_patient' => strtolower($onchainRecord['patient']),
            'onchain_doctor' => $onchainDoctorAddress,
            'onchain_timestamp' => $timestamp,
            'onchain_timestamp_formatted' => $timestamp > 0
                ? CarbonImmutable::createFromTimestamp($timestamp)->timezone('Asia/Jakarta')->format('Y-m-d H:i:s').' WIB'
                : '-',
            'last_doctor_name' => $lastDoctorName,
            'latest_tx_hash' => $latestTxHash,
            'etherscan_url' => $latestTxHash !== null ? "https://sepolia.etherscan.io/tx/{$latestTxHash}" : null,
        ]);
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
