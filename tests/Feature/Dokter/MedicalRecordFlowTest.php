<?php

use App\Models\Authorization;
use App\Models\Doctor;
use App\Models\LogBlockchain;
use App\Models\MedicalRecord;
use App\Models\Patient;
use App\Models\User;
use App\Services\BlockchainService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function setupDoctorAndPatients(bool $doctorOnchain = true): array
{
    $doctorUser = User::factory()->dokter()->create([
        'username' => 'dr_andi',
        'wallet_address' => '0xa1b2c3d4e5f60718293a4b5c6d7e8f9012345678',
    ]);
    $doctor = Doctor::create([
        'doctor_code' => 'DOC001',
        'user_id' => $doctorUser->id,
        'full_name' => 'Dr. Andi Wijaya',
        'specialization' => 'Umum',
        'str_number' => 'STR-2026-0001',
        'is_registered_onchain' => $doctorOnchain,
        'registration_tx_hash' => $doctorOnchain ? '0x'.str_repeat('1', 64) : null,
    ]);

    $authPatientUser = User::factory()->pasien()->create([
        'username' => 'pasien_budi',
        'wallet_address' => '0xb2c3d4e5f60718293a4b5c6d7e8f901234567890',
    ]);
    $authorizedPatient = Patient::create([
        'patient_code' => 'PAT001',
        'user_id' => $authPatientUser->id,
        'full_name' => 'Budi Santoso',
        'date_of_birth' => '1992-05-14',
        'gender' => Patient::GENDER_MALE,
        'address' => 'Jl. Merdeka No. 10',
    ]);

    $revokedPatientUser = User::factory()->pasien()->create([
        'username' => 'pasien_rina',
        'wallet_address' => '0xc3d4e5f60718293a4b5c6d7e8f90123456789012',
    ]);
    $revokedPatient = Patient::create([
        'patient_code' => 'PAT002',
        'user_id' => $revokedPatientUser->id,
        'full_name' => 'Rina Permata',
        'date_of_birth' => '1995-07-20',
        'gender' => Patient::GENDER_FEMALE,
        'address' => 'Jl. Melati No. 3',
    ]);

    $unauthPatientUser = User::factory()->pasien()->create([
        'username' => 'pasien_dodi',
        'wallet_address' => '0xd4e5f60718293a4b5c6d7e8f9012345678901234',
    ]);
    $unauthorizedPatient = Patient::create([
        'patient_code' => 'PAT003',
        'user_id' => $unauthPatientUser->id,
        'full_name' => 'Dodi Pratama',
        'date_of_birth' => '1990-01-11',
        'gender' => Patient::GENDER_MALE,
        'address' => 'Jl. Kenanga No. 8',
    ]);

    Authorization::create([
        'patient_id' => $authorizedPatient->id,
        'doctor_id' => $doctor->id,
        'is_authorized' => true,
        'tx_hash' => '0x'.str_repeat('2', 64),
    ]);

    Authorization::create([
        'patient_id' => $revokedPatient->id,
        'doctor_id' => $doctor->id,
        'is_authorized' => false,
        'tx_hash' => '0x'.str_repeat('3', 64),
    ]);

    return compact('doctorUser', 'doctor', 'authorizedPatient', 'revokedPatient', 'unauthorizedPatient');
}

it('shows only actively authorized patients in the doctor examination form', function () {
    $ctx = setupDoctorAndPatients();

    $response = $this->actingAs($ctx['doctorUser'])->get(route('dokter.dashboard'));

    $response->assertOk()
        ->assertSee('Budi Santoso (PAT001)')
        ->assertSee('data-wallet="0xb2c3d4e5f60718293a4b5c6d7e8f901234567890"', false)
        ->assertDontSee('Rina Permata (PAT002)')
        ->assertDontSee('Dodi Pratama (PAT003)');
});

it('executes the 2-step prepare and store CREATE flow with blockchain log persistence', function () {
    $ctx = setupDoctorAndPatients();

    $payload = [
        'patient_id' => $ctx['authorizedPatient']->id,
        'record_date' => '2026-11-26',
        'complaint' => 'Demam tinggi',
        'diagnosis' => 'Infeksi virus',
        'treatment' => 'Paracetamol 500mg',
        'notes' => 'Istirahat cukup',
    ];

    $prepareResponse = $this->actingAs($ctx['doctorUser'])
        ->postJson(route('dokter.records.prepare'), $payload);

    $prepareResponse->assertOk()
        ->assertJsonPath('record_code', 'MR001')
        ->assertJsonPath('patient_wallet', '0xb2c3d4e5f60718293a4b5c6d7e8f901234567890');

    $preparedHash = $prepareResponse->json('current_hash');
    $expectedHash = app(BlockchainService::class)->hashMedicalRecord([
        ...$payload,
        'record_code' => 'MR001',
        'doctor_id' => $ctx['doctor']->id,
    ]);

    expect($preparedHash)->toBe($expectedHash);

    $txHash = '0x'.str_repeat('A', 64);
    $storeResponse = $this->actingAs($ctx['doctorUser'])
        ->postJson(route('dokter.records.store'), [
            ...$payload,
            'record_code' => 'MR001',
            'current_hash' => strtoupper($preparedHash),
            'tx_hash' => $txHash,
            'block_number' => 7001234,
        ]);

    $storeResponse->assertCreated()
        ->assertJsonPath('medical_record.record_code', 'MR001')
        ->assertJsonPath('log_blockchain.action_type', LogBlockchain::ACTION_CREATE);

    $record = MedicalRecord::query()->where('record_code', 'MR001')->firstOrFail();
    $log = LogBlockchain::query()->where('medical_record_id', $record->id)->firstOrFail();

    expect($record->current_hash)->toBe($expectedHash)
        ->and($log->onchain_hash)->toBe($expectedHash)
        ->and($log->tx_hash)->toBe(strtolower($txHash))
        ->and($log->block_number)->toBe(7001234)
        ->and($log->action_type)->toBe(LogBlockchain::ACTION_CREATE);
});

it('rejects store when submitted current_hash does not match recomputed payload hash', function () {
    $ctx = setupDoctorAndPatients();

    $this->actingAs($ctx['doctorUser'])
        ->postJson(route('dokter.records.store'), [
            'record_code' => 'MR001',
            'patient_id' => $ctx['authorizedPatient']->id,
            'record_date' => '2026-11-26',
            'complaint' => 'Demam',
            'diagnosis' => 'Flu',
            'treatment' => 'Istirahat',
            'notes' => null,
            'current_hash' => '0x'.str_repeat('f', 64),
            'tx_hash' => '0x'.str_repeat('e', 64),
        ])
        ->assertUnprocessable();

    $this->assertDatabaseCount('medical_records', 0);
    $this->assertDatabaseCount('log_blockchains', 0);
});

it('forbids doctor prepare and store when patient has not authorized the doctor', function () {
    $ctx = setupDoctorAndPatients();

    $basePayload = [
        'record_date' => '2026-11-26',
        'complaint' => 'Sakit kepala',
        'diagnosis' => 'Migrain',
        'treatment' => 'Analgesik',
        'notes' => null,
    ];

    $this->actingAs($ctx['doctorUser'])
        ->postJson(route('dokter.records.prepare'), [
            ...$basePayload,
            'patient_id' => $ctx['revokedPatient']->id,
        ])
        ->assertForbidden();

    $this->actingAs($ctx['doctorUser'])
        ->postJson(route('dokter.records.store'), [
            ...$basePayload,
            'record_code' => 'MR099',
            'patient_id' => $ctx['unauthorizedPatient']->id,
            'current_hash' => '0x'.str_repeat('a', 64),
            'tx_hash' => '0x'.str_repeat('b', 64),
        ])
        ->assertForbidden();
});

it('forbids doctor prepare when doctor is not registered on-chain', function () {
    $unregistered = setupDoctorAndPatients(doctorOnchain: false);

    $this->actingAs($unregistered['doctorUser'])
        ->postJson(route('dokter.records.prepare'), [
            'patient_id' => $unregistered['authorizedPatient']->id,
            'record_date' => '2026-11-26',
            'complaint' => 'Sakit kepala',
            'diagnosis' => 'Migrain',
            'treatment' => 'Analgesik',
            'notes' => null,
        ])
        ->assertForbidden();
});

it('executes the 2-step prepare-update and update flow and appends an UPDATE log', function () {
    $ctx = setupDoctorAndPatients();
    $service = app(BlockchainService::class);

    $initialHash = $service->hashMedicalRecord([
        'record_code' => 'MR001',
        'patient_id' => $ctx['authorizedPatient']->id,
        'doctor_id' => $ctx['doctor']->id,
        'record_date' => '2026-11-25',
        'complaint' => 'Batuk kering',
        'diagnosis' => 'Bronkitis awal',
        'treatment' => 'Obat batuk',
        'notes' => null,
    ]);

    $record = MedicalRecord::create([
        'record_code' => 'MR001',
        'patient_id' => $ctx['authorizedPatient']->id,
        'doctor_id' => $ctx['doctor']->id,
        'record_date' => '2026-11-25',
        'complaint' => 'Batuk kering',
        'diagnosis' => 'Bronkitis awal',
        'treatment' => 'Obat batuk',
        'current_hash' => $initialHash,
    ]);

    LogBlockchain::create([
        'medical_record_id' => $record->id,
        'doctor_id' => $ctx['doctor']->id,
        'onchain_hash' => $initialHash,
        'tx_hash' => '0x'.str_repeat('4', 64),
        'block_number' => 7000100,
        'action_type' => LogBlockchain::ACTION_CREATE,
    ]);

    $this->actingAs($ctx['doctorUser'])
        ->get(route('dokter.records.index'))
        ->assertOk()
        ->assertSee('MR001');

    $this->actingAs($ctx['doctorUser'])
        ->get(route('dokter.records.edit', $record))
        ->assertOk()
        ->assertSee('MR001');

    $updatePayload = [
        'record_date' => '2026-11-26',
        'complaint' => 'Batuk berkurang',
        'diagnosis' => 'Bronkitis membaik',
        'treatment' => 'Lanjutkan vitamin',
        'notes' => 'Kontrol 1 minggu',
    ];

    $prepareUpdateResponse = $this->actingAs($ctx['doctorUser'])
        ->postJson(route('dokter.records.prepare-update', $record), $updatePayload);

    $prepareUpdateResponse->assertOk()
        ->assertJsonPath('record_code', 'MR001');

    $newHash = $prepareUpdateResponse->json('current_hash');
    $updateTx = '0x'.str_repeat('d', 64);

    $this->actingAs($ctx['doctorUser'])
        ->putJson(route('dokter.records.update', $record), [
            ...$updatePayload,
            'current_hash' => $newHash,
            'tx_hash' => $updateTx,
            'block_number' => 7000999,
        ])
        ->assertOk()
        ->assertJsonPath('log_blockchain.action_type', LogBlockchain::ACTION_UPDATE);

    $record->refresh();
    expect($record->diagnosis)->toBe('Bronkitis membaik')
        ->and($record->current_hash)->toBe($newHash)
        ->and($record->logBlockchains)->toHaveCount(2);

    Authorization::query()
        ->where('patient_id', $ctx['authorizedPatient']->id)
        ->where('doctor_id', $ctx['doctor']->id)
        ->update(['is_authorized' => false]);

    $this->actingAs($ctx['doctorUser'])
        ->get(route('dokter.records.edit', $record))
        ->assertForbidden();
});
