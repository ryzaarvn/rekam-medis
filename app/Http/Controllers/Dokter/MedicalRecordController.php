<?php

namespace App\Http\Controllers\Dokter;

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
     * Display medical records authored by this doctor or owned by currently authorizing patients.
     */
    public function index(Request $request): View
    {
        $doctor = $this->resolveDoctor($request);

        $authorizedPatientIds = $doctor->authorizedPatients()
            ->registeredOnchain()
            ->pluck('patients.id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $records = MedicalRecord::query()
            ->with(['patient.user', 'doctor', 'latestLogBlockchain', 'logBlockchains.doctor'])
            ->where(function ($query) use ($doctor, $authorizedPatientIds): void {
                $query->where('doctor_id', $doctor->id);

                if ($authorizedPatientIds !== []) {
                    $query->orWhereIn('patient_id', $authorizedPatientIds);
                }
            })
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
     * Validate inputs and authorization, generate record_code, and compute canonical SHA-256 hash.
     */
    public function prepareStore(
        PrepareStoreMedicalRecordRequest $request,
        BlockchainService $blockchainService,
    ): JsonResponse {
        $doctor = $this->resolveDoctor($request);
        $validated = $request->validated();
        $patientId = (int) $validated['patient_id'];

        $patient = $this->ensureDoctorAuthorizedForPatient($doctor, $patientId);

        $recordCode = CodeGenerator::nextCode(
            'MR',
            MedicalRecord::query()->pluck('record_code'),
        );

        $payload = $this->buildHashPayload($recordCode, $patientId, $doctor->id, $validated);
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
     * Recompute hash, persist the medical record and CREATE blockchain audit log atomically.
     */
    public function store(
        StoreMedicalRecordRequest $request,
        BlockchainService $blockchainService,
    ): JsonResponse {
        $doctor = $this->resolveDoctor($request);
        $validated = $request->validated();
        $patientId = (int) $validated['patient_id'];

        $this->ensureDoctorAuthorizedForPatient($doctor, $patientId);

        $payload = $this->buildHashPayload(
            $validated['record_code'],
            $patientId,
            $doctor->id,
            $validated,
        );
        $expectedHash = $blockchainService->hashMedicalRecord($payload);

        if (! hash_equals($expectedHash, $validated['current_hash'])) {
            return response()->json([
                'message' => 'Hash rekam medis tidak cocok dengan data yang dikirimkan. Harap ulangi proses simpan.',
            ], 422);
        }

        $result = DB::transaction(function () use ($doctor, $patientId, $validated, $expectedHash): array {
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
                'tx_hash' => $validated['tx_hash'],
                'block_number' => $validated['block_number'] ?? null,
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
     * Show the elder-friendly medical record edit form for an authorized patient's record.
     */
    public function edit(Request $request, MedicalRecord $medicalRecord): View
    {
        $doctor = $this->resolveDoctor($request);
        $this->ensureDoctorAuthorizedForPatient($doctor, $medicalRecord->patient_id, requireOnchainRegistration: false);

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
        $this->ensureDoctorAuthorizedForPatient($doctor, $medicalRecord->patient_id);

        $medicalRecord->loadMissing('patient.user');
        $validated = $request->validated();

        $payload = $this->buildHashPayload(
            $medicalRecord->record_code,
            $medicalRecord->patient_id,
            $doctor->id,
            $validated,
        );
        $newHash = $blockchainService->hashMedicalRecord($payload);

        return response()->json([
            'record_code' => $medicalRecord->record_code,
            'patient_id' => $medicalRecord->patient_id,
            'doctor_id' => $doctor->id,
            'patient_wallet' => $medicalRecord->patient->user->wallet_address,
            'current_hash' => $newHash,
        ]);
    }

    /**
     * Recompute hash, update the medical record, and insert an UPDATE blockchain audit log.
     */
    public function update(
        UpdateMedicalRecordRequest $request,
        MedicalRecord $medicalRecord,
        BlockchainService $blockchainService,
    ): JsonResponse {
        $doctor = $this->resolveDoctor($request);
        $this->ensureDoctorAuthorizedForPatient($doctor, $medicalRecord->patient_id);

        $validated = $request->validated();

        $payload = $this->buildHashPayload(
            $medicalRecord->record_code,
            $medicalRecord->patient_id,
            $doctor->id,
            $validated,
        );
        $expectedHash = $blockchainService->hashMedicalRecord($payload);

        if (! hash_equals($expectedHash, $validated['current_hash'])) {
            return response()->json([
                'message' => 'Hash pembaruan rekam medis tidak cocok dengan data yang dikirimkan. Harap ulangi proses simpan.',
            ], 422);
        }

        $result = DB::transaction(function () use ($medicalRecord, $doctor, $validated, $expectedHash): array {
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
                'tx_hash' => $validated['tx_hash'],
                'block_number' => $validated['block_number'] ?? null,
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
    private function ensureDoctorAuthorizedForPatient(
        Doctor $doctor,
        int $patientId,
        bool $requireOnchainRegistration = true,
    ): Patient {
        if ($requireOnchainRegistration && ! $doctor->is_registered_onchain) {
            abort(403, 'Akun dokter Anda belum terdaftar aktif on-chain oleh Admin.');
        }

        $patient = Patient::query()->with('user')->findOrFail($patientId);

        if ($requireOnchainRegistration && ! $patient->is_registered_onchain) {
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
        int $patientId,
        int $doctorId,
        array $validated,
    ): array {
        return [
            'record_code' => $recordCode,
            'patient_id' => $patientId,
            'doctor_id' => $doctorId,
            'record_date' => $validated['record_date'],
            'complaint' => $validated['complaint'],
            'diagnosis' => $validated['diagnosis'],
            'treatment' => $validated['treatment'],
            'notes' => $validated['notes'] ?? null,
        ];
    }
}
