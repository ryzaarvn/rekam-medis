<?php

namespace App\Http\Controllers\Dokter;

use App\Exceptions\BlockchainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dokter\PrepareStoreMedicalRecordRequest;
use App\Http\Requests\Dokter\PrepareUpdateMedicalRecordRequest;
use App\Http\Requests\Dokter\StoreMedicalRecordRequest;
use App\Http\Requests\Dokter\UpdateMedicalRecordRequest;
use App\Models\Authorization;
use App\Models\Doctor;
use App\Models\LogBlockchain;
use App\Models\MedicalRecord;
use App\Models\Patient;
use App\Services\BlockchainService;
use App\Support\CodeGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class MedicalRecordController extends Controller
{
    /**
     * Show the examination input page with only patients actively authorizing this doctor.
     */
    public function create(Request $request): View
    {
        $doctor = $this->resolveDoctor($request);

        $authorizedPatients = $doctor->authorizedPatients()
            ->registeredOnchain()
            ->with('user')
            ->orderBy('full_name')
            ->get();

        return view('dokter.dashboard', [
            'doctor' => $doctor,
            'authorizedPatients' => $authorizedPatients,
        ]);
    }

    /**
     * Display medical records owned by patients currently authorizing this active on-chain doctor.
     */
    public function index(Request $request): View
    {
        $doctor = $this->resolveDoctor($request);

        if (! $doctor->is_registered_onchain) {
            return view('dokter.records.index', [
                'doctor' => $doctor,
                'records' => collect(),
                'authorizedPatientIds' => [],
            ]);
        }

        $authorizedPatientIds = $doctor->authorizedPatients()
            ->registeredOnchain()
            ->pluck('patients.id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $records = $authorizedPatientIds === []
            ? collect()
            : MedicalRecord::query()
                ->with(['patient.user', 'doctor', 'latestLogBlockchain', 'logBlockchains.doctor'])
                ->whereIn('patient_id', $authorizedPatientIds)
                ->orderByDesc('record_date')
                ->orderByDesc('id')
                ->get();

        return view('dokter.records.index', [
            'doctor' => $doctor,
            'records' => $records,
            'authorizedPatientIds' => $authorizedPatientIds,
        ]);
    }

    /**
     * Validate inputs and authorization, generate record_code atomically, and compute canonical SHA-256 hash.
     */
    public function prepareStore(
        PrepareStoreMedicalRecordRequest $request,
        BlockchainService $blockchainService,
    ): JsonResponse {
        $doctor = $this->resolveDoctor($request);
        $validated = $request->validated();
        $patientId = (int) $validated['patient_id'];

        $patient = $this->ensureDoctorAuthorizedForPatient($doctor, $patientId);
        $recordCode = CodeGenerator::nextMedicalRecordCode();

        $payload = $this->buildHashPayload(
            $recordCode,
            $patient->patient_code,
            $doctor->doctor_code,
            $validated,
        );
        $currentHash = $blockchainService->hashMedicalRecord($payload);

        return response()->json([
            'record_code' => $recordCode,
            'patient_id' => $patientId,
            'doctor_id' => $doctor->id,
            'patient_wallet' => $patient->user->wallet_address,
            'current_hash' => $currentHash,
        ]);
    }

    /**
     * Recompute hash, verify Sepolia receipt & event, and persist the medical record and CREATE log atomically.
     */
    public function store(
        StoreMedicalRecordRequest $request,
        BlockchainService $blockchainService,
    ): JsonResponse {
        $doctor = $this->resolveDoctor($request);
        $doctor->loadMissing('user');
        $validated = $request->validated();
        $patientId = (int) $validated['patient_id'];

        $patient = $this->ensureDoctorAuthorizedForPatient($doctor, $patientId);

        $payload = $this->buildHashPayload(
            $validated['record_code'],
            $patient->patient_code,
            $doctor->doctor_code,
            $validated,
        );
        $expectedHash = $blockchainService->hashMedicalRecord($payload);

        if (! hash_equals($expectedHash, $validated['current_hash'])) {
            return response()->json([
                'message' => 'Hash rekam medis tidak cocok dengan data yang dikirimkan. Harap ulangi proses simpan.',
            ], 422);
        }

        try {
            $verified = $blockchainService->verifyMedicalRecordAddedTransaction(
                $validated['tx_hash'],
                $doctor->user->wallet_address,
                $validated['record_code'],
                $patient->user->wallet_address,
                $expectedHash,
            );
        } catch (BlockchainException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], $exception->getHttpStatus());
        }

        $result = DB::transaction(function () use ($doctor, $patientId, $validated, $expectedHash, $verified): array {
            $medicalRecord = MedicalRecord::create([
                'record_code' => $validated['record_code'],
                'patient_id' => $patientId,
                'doctor_id' => $doctor->id,
                'record_date' => $validated['record_date'],
                'complaint' => $validated['complaint'],
                'diagnosis' => $validated['diagnosis'],
                'treatment' => $validated['treatment'],
                'notes' => $validated['notes'] ?? null,
                'current_hash' => $expectedHash,
            ]);

            $logBlockchain = LogBlockchain::create([
                'medical_record_id' => $medicalRecord->id,
                'doctor_id' => $doctor->id,
                'onchain_hash' => $expectedHash,
                'tx_hash' => $verified['tx_hash'],
                'block_number' => $verified['block_number'],
                'action_type' => LogBlockchain::ACTION_CREATE,
            ]);

            return [
                'medical_record' => $medicalRecord,
                'log_blockchain' => $logBlockchain,
            ];
        });

        return response()->json([
            'message' => "Rekam medis {$result['medical_record']->record_code} berhasil dicatat on-chain dan disimpan ke database.",
            'medical_record' => $result['medical_record'],
            'log_blockchain' => $result['log_blockchain'],
            'etherscan_url' => "https://sepolia.etherscan.io/tx/{$result['log_blockchain']->tx_hash}",
        ], 201);
    }

    /**
     * Show the elder-friendly medical record edit form for an actively authorized on-chain patient's record.
     */
    public function edit(Request $request, MedicalRecord $medicalRecord): View
    {
        $doctor = $this->resolveDoctor($request);
        $this->ensureDoctorAuthorizedForPatient($doctor, $medicalRecord->patient_id);

        $medicalRecord->load(['patient.user', 'latestLogBlockchain']);

        return view('dokter.records.edit', [
            'doctor' => $doctor,
            'medicalRecord' => $medicalRecord,
        ]);
    }

    /**
     * Validate update payload and authorization, then return the new canonical SHA-256 hash.
     */
    public function prepareUpdate(
        PrepareUpdateMedicalRecordRequest $request,
        MedicalRecord $medicalRecord,
        BlockchainService $blockchainService,
    ): JsonResponse {
        $doctor = $this->resolveDoctor($request);
        $patient = $this->ensureDoctorAuthorizedForPatient($doctor, $medicalRecord->patient_id);

        $validated = $request->validated();

        $payload = $this->buildHashPayload(
            $medicalRecord->record_code,
            $patient->patient_code,
            $doctor->doctor_code,
            $validated,
        );
        $newHash = $blockchainService->hashMedicalRecord($payload);

        return response()->json([
            'record_code' => $medicalRecord->record_code,
            'patient_id' => $medicalRecord->patient_id,
            'doctor_id' => $doctor->id,
            'patient_wallet' => $patient->user->wallet_address,
            'current_hash' => $newHash,
        ]);
    }

    /**
     * Recompute hash, verify Sepolia receipt & event, update the medical record, and insert an UPDATE log.
     */
    public function update(
        UpdateMedicalRecordRequest $request,
        MedicalRecord $medicalRecord,
        BlockchainService $blockchainService,
    ): JsonResponse {
        $doctor = $this->resolveDoctor($request);
        $doctor->loadMissing('user');
        $patient = $this->ensureDoctorAuthorizedForPatient($doctor, $medicalRecord->patient_id);

        $validated = $request->validated();

        $payload = $this->buildHashPayload(
            $medicalRecord->record_code,
            $patient->patient_code,
            $doctor->doctor_code,
            $validated,
        );
        $expectedHash = $blockchainService->hashMedicalRecord($payload);

        if (! hash_equals($expectedHash, $validated['current_hash'])) {
            return response()->json([
                'message' => 'Hash pembaruan rekam medis tidak cocok dengan data yang dikirimkan. Harap ulangi proses simpan.',
            ], 422);
        }

        try {
            $verified = $blockchainService->verifyMedicalRecordUpdatedTransaction(
                $validated['tx_hash'],
                $doctor->user->wallet_address,
                $medicalRecord->record_code,
                $patient->user->wallet_address,
                $expectedHash,
            );
        } catch (BlockchainException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], $exception->getHttpStatus());
        }

        $result = DB::transaction(function () use ($medicalRecord, $doctor, $validated, $expectedHash, $verified): array {
            $medicalRecord->update([
                'doctor_id' => $doctor->id,
                'record_date' => $validated['record_date'],
                'complaint' => $validated['complaint'],
                'diagnosis' => $validated['diagnosis'],
                'treatment' => $validated['treatment'],
                'notes' => $validated['notes'] ?? null,
                'current_hash' => $expectedHash,
            ]);

            $logBlockchain = LogBlockchain::create([
                'medical_record_id' => $medicalRecord->id,
                'doctor_id' => $doctor->id,
                'onchain_hash' => $expectedHash,
                'tx_hash' => $verified['tx_hash'],
                'block_number' => $verified['block_number'],
                'action_type' => LogBlockchain::ACTION_UPDATE,
            ]);

            return [
                'medical_record' => $medicalRecord->fresh(),
                'log_blockchain' => $logBlockchain,
            ];
        });

        return response()->json([
            'message' => "Rekam medis {$result['medical_record']->record_code} berhasil diperbarui on-chain dan disimpan ke database.",
            'medical_record' => $result['medical_record'],
            'log_blockchain' => $result['log_blockchain'],
            'etherscan_url' => "https://sepolia.etherscan.io/tx/{$result['log_blockchain']->tx_hash}",
        ]);
    }

    /**
     * Resolve the authenticated user's doctor profile.
     */
    private function resolveDoctor(Request $request): Doctor
    {
        $doctor = $request->user()?->doctor;

        if ($doctor === null) {
            abort(403, 'Profil dokter tidak ditemukan pada akun ini.');
        }

        return $doctor;
    }

    /**
     * Verify active patient authorization and on-chain registration for both doctor and patient.
     */
    private function ensureDoctorAuthorizedForPatient(Doctor $doctor, int $patientId): Patient
    {
        if (! $doctor->is_registered_onchain) {
            abort(403, 'Akun dokter Anda belum terdaftar aktif on-chain oleh Admin.');
        }

        $patient = Patient::query()->with('user')->findOrFail($patientId);

        if (! $patient->is_registered_onchain) {
            abort(403, 'Pasien ini belum terdaftar aktif on-chain oleh Admin.');
        }

        $hasActiveAuthorization = Authorization::query()
            ->authorized()
            ->where('patient_id', $patientId)
            ->where('doctor_id', $doctor->id)
            ->exists();

        if (! $hasActiveAuthorization) {
            abort(403, 'Anda belum memiliki otorisasi aktif dari pasien ini.');
        }

        return $patient;
    }

    /**
     * Build the deterministic payload array expected by BlockchainService::hashMedicalRecord.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function buildHashPayload(
        string $recordCode,
        string $patientCode,
        string $doctorCode,
        array $validated,
    ): array {
        return [
            'record_code' => $recordCode,
            'patient_code' => $patientCode,
            'doctor_code' => $doctorCode,
            'record_date' => $validated['record_date'],
            'complaint' => $validated['complaint'],
            'diagnosis' => $validated['diagnosis'],
            'treatment' => $validated['treatment'],
            'notes' => $validated['notes'] ?? null,
        ];
    }
}
