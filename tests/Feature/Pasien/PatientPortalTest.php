<?php

use App\Exceptions\BlockchainException;
use App\Models\Authorization;
use App\Models\Doctor;
use App\Models\LogBlockchain;
use App\Models\MedicalRecord;
use App\Models\Patient;
use App\Models\User;
use App\Services\BlockchainService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function setupPatientPortalScenario(): array
{
    $patientUser = User::factory()->pasien()->create([
        'username' => 'pasien_budi',
        'wallet_address' => '0xb2c3d4e5f60718293a4b5c6d7e8f901234567890',
    ]);
    $patient = Patient::create([
        'patient_code' => 'PAT001',
        'user_id' => $patientUser->id,
        'full_name' => 'Budi Santoso',
        'date_of_birth' => '1992-05-14',
        'gender' => Patient::GENDER_MALE,
        'address' => 'Jl. Merdeka No. 10',
    ]);

    $otherPatientUser = User::factory()->pasien()->create([
        'username' => 'pasien_lain',
        'wallet_address' => '0xc3d4e5f60718293a4b5c6d7e8f90123456789012',
    ]);
    $otherPatient = Patient::create([
        'patient_code' => 'PAT002',
        'user_id' => $otherPatientUser->id,
        'full_name' => 'Siti Aminah',
        'date_of_birth' => '1997-02-12',
        'gender' => Patient::GENDER_FEMALE,
        'address' => 'Jl. Sudirman No. 45',
    ]);

    $doctorUser = User::factory()->dokter()->create([
        'username' => 'dr_andi',
        'wallet_address' => '0xa1b2c3d4e5f60718293a4b5c6d7e8f9012345678',
    ]);
    $activeDoctor = Doctor::create([
        'doctor_code' => 'DOC001',
        'user_id' => $doctorUser->id,
        'full_name' => 'Dr. Andi Wijaya',
        'specialization' => 'Umum',
        'str_number' => 'STR-2026-0001',
        'is_registered_onchain' => true,
        'registration_tx_hash' => '0x'.str_repeat('1', 64),
    ]);

    $pendingDoctorUser = User::factory()->dokter()->create([
        'username' => 'dr_pending',
        'wallet_address' => '0xd4e5f60718293a4b5c6d7e8f9012345678901234',
    ]);
    $pendingDoctor = Doctor::create([
        'doctor_code' => 'DOC002',
        'user_id' => $pendingDoctorUser->id,
        'full_name' => 'Dr. Belum Onchain',
        'specialization' => 'Anak',
        'str_number' => 'STR-2026-0002',
        'is_registered_onchain' => false,
        'registration_tx_hash' => null,
    ]);

    return compact('patientUser', 'patient', 'otherPatientUser', 'otherPatient', 'activeDoctor', 'pendingDoctor');
}

it('lists only active on-chain doctors and grants or revokes authorization with lowercase tx_hash', function () {
    $ctx = setupPatientPortalScenario();

    $this->actingAs($ctx['patientUser'])
        ->get(route('pasien.authorizations.index'))
        ->assertOk()
        ->assertSee('Dr. Andi Wijaya')
        ->assertDontSee('Dr. Belum Onchain');

    $grantTx = '0x'.str_repeat('A', 64);
    $this->actingAs($ctx['patientUser'])
        ->postJson(route('pasien.authorizations.grant', $ctx['activeDoctor']), [
            'tx_hash' => $grantTx,
        ])
        ->assertOk()
        ->assertJsonPath('authorization.is_authorized', true);

    $authorization = Authorization::query()
        ->where('patient_id', $ctx['patient']->id)
        ->where('doctor_id', $ctx['activeDoctor']->id)
        ->firstOrFail();

    expect($authorization->is_authorized)->toBeTrue()
        ->and($authorization->tx_hash)->toBe(strtolower($grantTx));

    $revokeTx = '0x'.str_repeat('B', 64);
    $this->actingAs($ctx['patientUser'])
        ->postJson(route('pasien.authorizations.revoke', $ctx['activeDoctor']), [
            'tx_hash' => $revokeTx,
        ])
        ->assertOk()
        ->assertJsonPath('authorization.is_authorized', false);

    expect($authorization->fresh()->is_authorized)->toBeFalse()
        ->and($authorization->fresh()->tx_hash)->toBe(strtolower($revokeTx));

    $this->actingAs($ctx['patientUser'])
        ->postJson(route('pasien.authorizations.grant', $ctx['pendingDoctor']), [
            'tx_hash' => '0x'.str_repeat('c', 64),
        ])
        ->assertUnprocessable();
});

it('restricts patient dashboard medical records to the logged-in patient and hides other patients records', function () {
    $ctx = setupPatientPortalScenario();
    $service = app(BlockchainService::class);

    $ownHash = $service->hashMedicalRecord([
        'record_code' => 'MR001',
        'patient_id' => $ctx['patient']->id,
        'doctor_id' => $ctx['activeDoctor']->id,
        'record_date' => '2026-11-26',
        'complaint' => 'Batuk',
        'diagnosis' => 'ISPA <script>alert("xss")</script>',
        'treatment' => 'Istirahat',
        'notes' => null,
    ]);

    MedicalRecord::create([
        'record_code' => 'MR001',
        'patient_id' => $ctx['patient']->id,
        'doctor_id' => $ctx['activeDoctor']->id,
        'record_date' => '2026-11-26',
        'complaint' => 'Batuk',
        'diagnosis' => 'ISPA <script>alert("xss")</script>',
        'treatment' => 'Istirahat',
        'current_hash' => $ownHash,
    ]);

    $otherRecord = MedicalRecord::create([
        'record_code' => 'MR002',
        'patient_id' => $ctx['otherPatient']->id,
        'doctor_id' => $ctx['activeDoctor']->id,
        'record_date' => '2026-11-26',
        'complaint' => 'Demam rahasia',
        'diagnosis' => 'Diagnosis rahasia pasien lain',
        'treatment' => 'Obat rahasia',
        'current_hash' => '0x'.str_repeat('9', 64),
    ]);

    $this->actingAs($ctx['patientUser'])
        ->get(route('pasien.dashboard'))
        ->assertOk()
        ->assertSee('MR001')
        ->assertSee('&lt;script&gt;', false)
        ->assertDontSee('<script>alert("xss")</script>', false)
        ->assertDontSee('MR002')
        ->assertDontSee('Diagnosis rahasia pasien lain');

    $this->actingAs($ctx['patientUser'])
        ->postJson(route('pasien.records.verify', $otherRecord))
        ->assertNotFound();
});

it('verifies a valid medical record against mocked Sepolia BlockchainService', function () {
    $ctx = setupPatientPortalScenario();
    $realService = app(BlockchainService::class);

    $canonicalHash = $realService->hashMedicalRecord([
        'record_code' => 'MR001',
        'patient_id' => $ctx['patient']->id,
        'doctor_id' => $ctx['activeDoctor']->id,
        'record_date' => '2026-11-26',
        'complaint' => 'Demam dan sakit kepala',
        'diagnosis' => 'Demam Berdarah Ringan',
        'treatment' => 'Cairan oral dan paracetamol',
        'notes' => 'Kontrol 2 hari lagi',
    ]);

    $record = MedicalRecord::create([
        'record_code' => 'MR001',
        'patient_id' => $ctx['patient']->id,
        'doctor_id' => $ctx['activeDoctor']->id,
        'record_date' => '2026-11-26',
        'complaint' => 'Demam dan sakit kepala',
        'diagnosis' => 'Demam Berdarah Ringan',
        'treatment' => 'Cairan oral dan paracetamol',
        'notes' => 'Kontrol 2 hari lagi',
        'current_hash' => $canonicalHash,
    ]);

    $latestTxHash = '0x'.str_repeat('7', 64);
    LogBlockchain::create([
        'medical_record_id' => $record->id,
        'doctor_id' => $ctx['activeDoctor']->id,
        'onchain_hash' => $canonicalHash,
        'tx_hash' => $latestTxHash,
        'block_number' => 7000500,
        'action_type' => LogBlockchain::ACTION_CREATE,
    ]);

    $mock = Mockery::mock(BlockchainService::class)->makePartial();
    $mock->shouldReceive('getMedicalRecord')
        ->once()
        ->with('MR001')
        ->andReturn([
            'record_hash' => $canonicalHash,
            'patient' => $ctx['patientUser']->wallet_address,
            'doctor' => $ctx['activeDoctor']->user->wallet_address,
            'timestamp' => 1795680000,
        ]);
    $mock->shouldReceive('verifyMedicalRecord')
        ->once()
        ->with('MR001', $canonicalHash)
        ->andReturn(true);

    $this->app->instance(BlockchainService::class, $mock);

    $response = $this->actingAs($ctx['patientUser'])
        ->postJson(route('pasien.records.verify', $record));

    $response->assertOk()
        ->assertJson([
            'is_valid' => true,
            'record_code' => 'MR001',
            'recomputed_hash' => $canonicalHash,
            'stored_current_hash' => $canonicalHash,
            'onchain_hash' => $canonicalHash,
            'onchain_doctor' => $ctx['activeDoctor']->user->wallet_address,
            'onchain_timestamp' => 1795680000,
            'last_doctor_name' => 'Dr. Andi Wijaya (DOC001)',
            'latest_tx_hash' => $latestTxHash,
            'etherscan_url' => "https://sepolia.etherscan.io/tx/{$latestTxHash}",
        ]);
});

it('detects tampered off-chain medical record data against mocked Sepolia BlockchainService', function () {
    $ctx = setupPatientPortalScenario();
    $realService = app(BlockchainService::class);

    $originalHash = $realService->hashMedicalRecord([
        'record_code' => 'MR002',
        'patient_id' => $ctx['patient']->id,
        'doctor_id' => $ctx['activeDoctor']->id,
        'record_date' => '2026-11-26',
        'complaint' => 'Batuk ringan',
        'diagnosis' => 'ISPA',
        'treatment' => 'Paracetamol',
        'notes' => null,
    ]);

    $record = MedicalRecord::create([
        'record_code' => 'MR002',
        'patient_id' => $ctx['patient']->id,
        'doctor_id' => $ctx['activeDoctor']->id,
        'record_date' => '2026-11-26',
        'complaint' => 'Batuk ringan',
        'diagnosis' => 'ISPA',
        'treatment' => 'Paracetamol',
        'current_hash' => $originalHash,
    ]);

    $latestTxHash = '0x'.str_repeat('8', 64);
    LogBlockchain::create([
        'medical_record_id' => $record->id,
        'doctor_id' => $ctx['activeDoctor']->id,
        'onchain_hash' => $originalHash,
        'tx_hash' => $latestTxHash,
        'block_number' => 7000510,
        'action_type' => LogBlockchain::ACTION_CREATE,
    ]);

    // Simulate tampering directly in database without updating on-chain hash.
    $record->update(['diagnosis' => 'DIAGNOSIS DIMANIPULASI']);
    $tamperedRecomputedHash = $realService->hashMedicalRecord($record->fresh());

    $mock = Mockery::mock(BlockchainService::class)->makePartial();
    $mock->shouldReceive('getMedicalRecord')
        ->once()
        ->with('MR002')
        ->andReturn([
            'record_hash' => $originalHash,
            'patient' => $ctx['patientUser']->wallet_address,
            'doctor' => $ctx['activeDoctor']->user->wallet_address,
            'timestamp' => 1795681000,
        ]);
    $mock->shouldReceive('verifyMedicalRecord')
        ->once()
        ->with('MR002', $tamperedRecomputedHash)
        ->andReturn(false);

    $this->app->instance(BlockchainService::class, $mock);

    $response = $this->actingAs($ctx['patientUser'])
        ->postJson(route('pasien.records.verify', $record));

    $response->assertOk()
        ->assertJson([
            'is_valid' => false,
            'record_code' => 'MR002',
            'recomputed_hash' => $tamperedRecomputedHash,
            'stored_current_hash' => $originalHash,
            'onchain_hash' => $originalHash,
            'last_doctor_name' => 'Dr. Andi Wijaya (DOC001)',
            'latest_tx_hash' => $latestTxHash,
        ]);
});

it('returns a 502 error response when Sepolia RPC verification fails', function () {
    $ctx = setupPatientPortalScenario();
    $record = MedicalRecord::create([
        'record_code' => 'MR003',
        'patient_id' => $ctx['patient']->id,
        'doctor_id' => $ctx['activeDoctor']->id,
        'record_date' => '2026-11-26',
        'complaint' => 'Pusing',
        'diagnosis' => 'Vertigo',
        'treatment' => 'Betahistine',
        'current_hash' => '0x'.str_repeat('5', 64),
    ]);

    $mock = Mockery::mock(BlockchainService::class)->makePartial();
    $mock->shouldReceive('getMedicalRecord')
        ->once()
        ->with('MR003')
        ->andThrow(BlockchainException::rpcTransportFailed('getMedicalRecord', 'Connection timed out'));

    $this->app->instance(BlockchainService::class, $mock);

    $this->actingAs($ctx['patientUser'])
        ->postJson(route('pasien.records.verify', $record))
        ->assertStatus(502)
        ->assertJsonStructure(['message']);
});
