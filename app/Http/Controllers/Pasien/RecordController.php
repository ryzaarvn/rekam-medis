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

class RecordController extends Controller
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

        return view('pasien.records.index', [
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

        $patient->loadMissing('user');
        $medicalRecord->load(['patient.user', 'doctor', 'latestLogBlockchain.doctor']);

        $recomputedHash = $blockchainService->hashMedicalRecord($medicalRecord);
        $storedCurrentHash = strtolower($medicalRecord->current_hash);
        $latestTxHash = $medicalRecord->latestLogBlockchain?->tx_hash;
        $etherscanUrl = $latestTxHash !== null ? "https://sepolia.etherscan.io/tx/{$latestTxHash}" : null;

        try {
            $onchainRecord = $blockchainService->getMedicalRecord($medicalRecord->record_code);
        } catch (BlockchainException $exception) {
            $isNotFoundOnchain = $exception->revertReason() === 'Rekam medis tidak ditemukan'
                || str_contains($exception->getMessage(), 'Rekam medis tidak ditemukan');

            if ($isNotFoundOnchain) {
                $fallbackDoctor = $medicalRecord->latestLogBlockchain?->doctor ?? $medicalRecord->doctor;
                $lastDoctorName = $fallbackDoctor !== null
                    ? "{$fallbackDoctor->full_name} ({$fallbackDoctor->doctor_code})"
                    : '-';

                return response()->json([
                    'is_valid' => false,
                    'status' => 'not_found_onchain',
                    'message' => 'Rekam medis belum tercatat di smart contract Sepolia.',
                    'record_code' => $medicalRecord->record_code,
                    'recomputed_hash' => $recomputedHash,
                    'stored_current_hash' => $storedCurrentHash,
                    'onchain_hash' => null,
                    'onchain_patient' => null,
                    'patient_wallet_matched' => false,
                    'onchain_doctor' => null,
                    'onchain_timestamp' => null,
                    'onchain_timestamp_formatted' => '-',
                    'last_doctor_name' => $lastDoctorName,
                    'latest_tx_hash' => $latestTxHash,
                    'etherscan_url' => $etherscanUrl,
                ]);
            }

            return response()->json([
                'message' => $exception->getMessage(),
            ], 502);
        }

        $onchainHash = strtolower($onchainRecord['record_hash']);
        $onchainPatient = strtolower($onchainRecord['patient']);
        $onchainDoctorAddress = strtolower($onchainRecord['doctor']);
        $patientWalletMatched = hash_equals($onchainPatient, strtolower($patient->user->wallet_address));

        $isValid = hash_equals($onchainHash, $recomputedHash)
            && hash_equals($storedCurrentHash, $recomputedHash)
            && $patientWalletMatched;

        $status = $isValid
            ? 'valid'
            : (! $patientWalletMatched ? 'patient_mismatch' : 'tampered');

        $message = match ($status) {
            'valid' => 'Hash SHA-256 dan pemilik rekam medis cocok dengan catatan Smart Contract Sepolia.',
            'patient_mismatch' => 'Alamat wallet pasien pada Smart Contract Sepolia tidak cocok dengan wallet akun Anda.',
            default => 'Isi rekam medis di database tidak sesuai dengan catatan asli di Blockchain Sepolia.',
        };

        $matchedDoctor = Doctor::query()
            ->whereHas('user', fn ($query) => $query->where('wallet_address', $onchainDoctorAddress))
            ->first()
            ?? $medicalRecord->latestLogBlockchain?->doctor
            ?? $medicalRecord->doctor;

        $lastDoctorName = $matchedDoctor !== null
            ? "{$matchedDoctor->full_name} ({$matchedDoctor->doctor_code})"
            : $onchainDoctorAddress;

        $timestamp = (int) $onchainRecord['timestamp'];

        return response()->json([
            'is_valid' => $isValid,
            'status' => $status,
            'message' => $message,
            'record_code' => $medicalRecord->record_code,
            'recomputed_hash' => $recomputedHash,
            'stored_current_hash' => $storedCurrentHash,
            'onchain_hash' => $onchainHash,
            'onchain_patient' => $onchainPatient,
            'patient_wallet_matched' => $patientWalletMatched,
            'onchain_doctor' => $onchainDoctorAddress,
            'onchain_timestamp' => $timestamp,
            'onchain_timestamp_formatted' => $timestamp > 0
                ? CarbonImmutable::createFromTimestamp($timestamp)->timezone('Asia/Jakarta')->format('Y-m-d H:i:s').' WIB'
                : '-',
            'last_doctor_name' => $lastDoctorName,
            'latest_tx_hash' => $latestTxHash,
            'etherscan_url' => $etherscanUrl,
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
