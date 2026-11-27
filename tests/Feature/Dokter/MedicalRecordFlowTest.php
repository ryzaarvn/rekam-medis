<?php

use App\Models\Authorization;
use App\Models\Doctor;
use App\Models\LogBlockchain;
use App\Models\MedicalRecord;
use App\Models\Patient;
use App\Models\User;
use App\Services\BlockchainService;
use App\Support\CodeGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Config::set('blockchain.chain_id', 11155111);
    Config::set('blockchain.contract_address', '0x6d1c13645e2b1ca4f4dfb6a4bbd22daa8072f4a4');
    Config::set('blockchain.rpc_url', 'https://eth-sepolia.example.test/v2/test-key');
    Config::set('blockchain.receipt_max_attempts', 1);
    Config::set('blockchain.receipt_retry_delay_ms', 0);
});

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
        'is_registered_onchain' => true,
        'registration_tx_hash' => '0x'.str_repeat('9', 64),
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
        'is_registered_onchain' => true,
        'registration_tx_hash' => '0x'.str_repeat('8', 64),
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
        'is_registered_onchain' => true,
        'registration_tx_hash' => '0x'.str_repeat('7', 64),
    ]);

    $unregisteredAuthPatientUser = User::factory()->pasien()->create([
        'username' => 'pasien_belum_onchain',
        'wallet_address' => '0xe5f60718293a4b5c6d7e8f901234567890123456',
    ]);
    $unregisteredAuthorizedPatient = Patient::create([
        'patient_code' => 'PAT004',
        'user_id' => $unregisteredAuthPatientUser->id,
        'full_name' => 'Dewi Pending',
        'date_of_birth' => '1993-11-11',
        'gender' => Patient::GENDER_FEMALE,
        'address' => 'Jl. Teratai No. 4',
        'is_registered_onchain' => false,
        'registration_tx_hash' => null,
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

    Authorization::create([
        'patient_id' => $unregisteredAuthorizedPatient->id,
        'doctor_id' => $doctor->id,
        'is_authorized' => true,
        'tx_hash' => '0x'.str_repeat('6', 64),
    ]);

    return compact(
        'doctorUser',
        'doctor',
        'authorizedPatient',
        'revokedPatient',
        'unauthorizedPatient',
        'unregisteredAuthorizedPatient',
    );
}

function dokterFakeRecordReceipt(
    string $txHash,
    string $topic0,
    string $recordCode,
    string $patientWallet,
    string $doctorWallet,
    string $recordHash,
    string $blockNumberHex = '0x6ad4aa',
    string $status = '0x1',
): array {
    $contract = '0x6d1c13645e2b1ca4f4dfb6a4bbd22daa8072f4a4';
    $paddedPatient = '0x'.str_pad(substr(strtolower($patientWallet), 2), 64, '0', STR_PAD_LEFT);
    $paddedDoctor = '0x'.str_pad(substr(strtolower($doctorWallet), 2), 64, '0', STR_PAD_LEFT);
    $timestampWord = str_pad(dechex(1795680000), 64, '0', STR_PAD_LEFT);

    return [
        'transactionHash' => strtolower($txHash),
        'from' => strtolower($doctorWallet),
        'to' => $contract,
        'status' => $status,
        'blockNumber' => $blockNumberHex,
        'logs' => [[
            'address' => $contract,
            'topics' => [
                $topic0,
                BlockchainService::encodeRecordId($recordCode),
                $paddedPatient,
                $paddedDoctor,
            ],
            'data' => strtolower($recordHash).$timestampWord,
        ]],
    ];
}

it('generates sequential medical record codes safely via CodeGenerator::nextMedicalRecordCode', function () {
    expect(CodeGenerator::nextMedicalRecordCode())->toBe('MR001')
        ->and(CodeGenerator::nextMedicalRecordCode())->toBe('MR002')
        ->and(CodeGenerator::nextMedicalRecordCode())->toBe('MR003');
});

it('shows only actively authorized on-chain patients in the doctor examination form', function () {
    $ctx = setupDoctorAndPatients();

    $response = $this->actingAs($ctx['doctorUser'])->get(route('dokter.records.create'));

    $response->assertOk()
        ->assertSee('data-page="dokter-record-create"', false)
        ->assertSee('Budi Santoso (PAT001)')
        ->assertSee('data-wallet="0xb2c3d4e5f60718293a4b5c6d7e8f901234567890"', false)
        ->assertDontSee('Rina Permata (PAT002)')
        ->assertDontSee('Dodi Pratama (PAT003)')
        ->assertDontSee('Dewi Pending (PAT004)');
});

it('displays filtered statistics and read-only tables on the doctor dashboard without shortcut buttons', function () {
    $ctx = setupDoctorAndPatients();

    $otherDoctorUser = User::factory()->dokter()->create([
        'username' => 'dr_sari',
        'wallet_address' => '0xf1e2d3c4b5a69788796a5b4c3d2e1f0011223344',
    ]);
    $otherDoctor = Doctor::create([
        'doctor_code' => 'DOC002',
        'user_id' => $otherDoctorUser->id,
        'full_name' => 'Dr. Sari Dewi',
        'specialization' => 'Anak',
        'str_number' => 'STR-2026-0002',
        'is_registered_onchain' => true,
        'registration_tx_hash' => '0x'.str_repeat('5', 64),
    ]);

    $ownRecord = MedicalRecord::create([
        'record_code' => 'MR001',
        'patient_id' => $ctx['authorizedPatient']->id,
        'doctor_id' => $ctx['doctor']->id,
        'record_date' => '2026-11-26',
        'complaint' => 'Demam tinggi',
        'diagnosis' => 'Infeksi virus',
        'treatment' => 'Paracetamol 500mg',
        'current_hash' => '0x'.str_repeat('a', 64),
    ]);

    $otherDoctorRecord = MedicalRecord::create([
        'record_code' => 'MR002',
        'patient_id' => $ctx['authorizedPatient']->id,
        'doctor_id' => $otherDoctor->id,
        'record_date' => '2026-11-25',
        'complaint' => 'Batuk ringan',
        'diagnosis' => 'ISPA',
        'treatment' => 'Vitamin C',
        'current_hash' => '0x'.str_repeat('b', 64),
    ]);

    $revokedRecord = MedicalRecord::create([
        'record_code' => 'MR003',
        'patient_id' => $ctx['revokedPatient']->id,
        'doctor_id' => $ctx['doctor']->id,
        'record_date' => '2026-11-24',
        'complaint' => 'Pusing',
        'diagnosis' => 'Vertigo Tersembunyi',
        'treatment' => 'Betahistine',
        'current_hash' => '0x'.str_repeat('c', 64),
    ]);

    $unregisteredRecord = MedicalRecord::create([
        'record_code' => 'MR004',
        'patient_id' => $ctx['unregisteredAuthorizedPatient']->id,
        'doctor_id' => $ctx['doctor']->id,
        'record_date' => '2026-11-23',
        'complaint' => 'Sakit perut',
        'diagnosis' => 'Gastritis Tersembunyi',
        'treatment' => 'Antasida',
        'current_hash' => '0x'.str_repeat('d', 64),
    ]);

    $ownCreateTx = '0x'.str_repeat('a', 63).'1';
    $ownUpdateTx = '0x'.str_repeat('a', 63).'2';
    $otherDoctorTx = '0x'.str_repeat('b', 63).'1';
    $revokedTx = '0x'.str_repeat('c', 63).'1';
    $unregisteredTx = '0x'.str_repeat('d', 63).'1';

    LogBlockchain::create([
        'medical_record_id' => $ownRecord->id,
        'doctor_id' => $ctx['doctor']->id,
        'onchain_hash' => '0x'.str_repeat('1', 64),
        'tx_hash' => $ownCreateTx,
        'block_number' => 7000101,
        'action_type' => LogBlockchain::ACTION_CREATE,
    ]);

    LogBlockchain::create([
        'medical_record_id' => $ownRecord->id,
        'doctor_id' => $ctx['doctor']->id,
        'onchain_hash' => '0x'.str_repeat('2', 64),
        'tx_hash' => $ownUpdateTx,
        'block_number' => 7000102,
        'action_type' => LogBlockchain::ACTION_UPDATE,
    ]);

    LogBlockchain::create([
        'medical_record_id' => $otherDoctorRecord->id,
        'doctor_id' => $otherDoctor->id,
        'onchain_hash' => '0x'.str_repeat('3', 64),
        'tx_hash' => $otherDoctorTx,
        'block_number' => 7000103,
        'action_type' => LogBlockchain::ACTION_CREATE,
    ]);

    LogBlockchain::create([
        'medical_record_id' => $revokedRecord->id,
        'doctor_id' => $ctx['doctor']->id,
        'onchain_hash' => '0x'.str_repeat('4', 64),
        'tx_hash' => $revokedTx,
        'block_number' => 7000104,
        'action_type' => LogBlockchain::ACTION_CREATE,
    ]);

    LogBlockchain::create([
        'medical_record_id' => $unregisteredRecord->id,
        'doctor_id' => $ctx['doctor']->id,
        'onchain_hash' => '0x'.str_repeat('5', 64),
        'tx_hash' => $unregisteredTx,
        'block_number' => 7000105,
        'action_type' => LogBlockchain::ACTION_CREATE,
    ]);

    $response = $this->actingAs($ctx['doctorUser'])->get(route('dokter.dashboard'));

    $response->assertOk()
        ->assertViewIs('dokter.dashboard')
        ->assertViewHas('authorizedPatientsCount', 1)
        ->assertViewHas('accessibleRecordsCount', 2)
        ->assertViewHas('myLogsTotalCount', 2)
        ->assertViewHas('myCreateLogsCount', 1)
        ->assertViewHas('myUpdateLogsCount', 1)
        ->assertSee('data-page="dokter-dashboard"', false)
        ->assertSee('Status On-Chain')
        ->assertSee('Terdaftar')
        ->assertSee('DOC001 • Umum')
        ->assertSee('1 CREATE • 1 UPDATE')
        ->assertSee('Pasien Terotorisasi Aktif')
        ->assertSee('Budi Santoso')
        ->assertSee('PAT001')
        ->assertSee('MR001')
        ->assertSee('CREATE')
        ->assertSee('UPDATE')
        ->assertSee("https://sepolia.etherscan.io/tx/{$ownCreateTx}", false)
        ->assertSee("https://sepolia.etherscan.io/tx/{$ownUpdateTx}", false)
        ->assertDontSee('Rina Permata')
        ->assertDontSee('PAT002')
        ->assertDontSee('Dodi Pratama')
        ->assertDontSee('PAT003')
        ->assertDontSee('Dewi Pending')
        ->assertDontSee('PAT004')
        ->assertDontSee('MR003')
        ->assertDontSee('MR004')
        ->assertDontSee($otherDoctorTx, false)
        ->assertDontSee($revokedTx, false)
        ->assertDontSee($unregisteredTx, false);

    $matchedSidebar = preg_match('/<aside[^>]*id="appSidebar"[^>]*>(.*?)<\/aside>/s', $response->getContent(), $sidebarMatches);
    expect($matchedSidebar)->toBe(1)
        ->and($sidebarMatches[1])->toContain('<a href="'.route('dokter.dashboard').'" class="active">')
        ->and($sidebarMatches[1])->toContain(route('dokter.records.create'))
        ->and($sidebarMatches[1])->toContain(route('dokter.records.index'))
        ->and($sidebarMatches[1])->toContain(route('account.edit'));

    $matchedMain = preg_match('/<main[^>]*class="content-area"[^>]*>(.*?)<\/main>/s', $response->getContent(), $mainMatches);
    expect($matchedMain)->toBe(1)
        ->and($mainMatches[1])->not->toContain(route('dokter.records.create'))
        ->and($mainMatches[1])->not->toContain(route('dokter.records.index'))
        ->and($mainMatches[1])->not->toContain(route('dokter.records.edit', $ownRecord))
        ->and($mainMatches[1])->not->toContain(route('account.edit'))
        ->and($mainMatches[1])->not->toContain('Catat Pemeriksaan')
        ->and($mainMatches[1])->not->toContain('Lihat Semua')
        ->and($mainMatches[1])->not->toContain('Detail')
        ->and($mainMatches[1])->not->toContain('Edit');
});

it('shows zero statistics and empty tables on the doctor dashboard when the doctor is not registered on-chain', function () {
    $unregistered = setupDoctorAndPatients(doctorOnchain: false);

    $record = MedicalRecord::create([
        'record_code' => 'MR777',
        'patient_id' => $unregistered['authorizedPatient']->id,
        'doctor_id' => $unregistered['doctor']->id,
        'record_date' => '2026-11-26',
        'complaint' => 'Demam',
        'diagnosis' => 'Flu',
        'treatment' => 'Istirahat',
        'current_hash' => '0x'.str_repeat('a', 64),
    ]);

    LogBlockchain::create([
        'medical_record_id' => $record->id,
        'doctor_id' => $unregistered['doctor']->id,
        'onchain_hash' => '0x'.str_repeat('b', 64),
        'tx_hash' => '0x'.str_repeat('c', 64),
        'block_number' => 7000200,
        'action_type' => LogBlockchain::ACTION_CREATE,
    ]);

    $response = $this->actingAs($unregistered['doctorUser'])->get(route('dokter.dashboard'));

    $response->assertOk()
        ->assertViewHas('authorizedPatientsCount', 0)
        ->assertViewHas('accessibleRecordsCount', 0)
        ->assertViewHas('myLogsTotalCount', 0)
        ->assertViewHas('myCreateLogsCount', 0)
        ->assertViewHas('myUpdateLogsCount', 0)
        ->assertViewHas('recentAuthorizedPatients', fn ($items): bool => $items->isEmpty())
        ->assertViewHas('recentBlockchainLogs', fn ($items): bool => $items->isEmpty())
        ->assertSee('Belum Terdaftar')
        ->assertSee('0 CREATE • 0 UPDATE')
        ->assertDontSee('Akun Belum Terdaftar On-Chain:')
        ->assertDontSee('Budi Santoso')
        ->assertDontSee('PAT001')
        ->assertDontSee('MR777');

    $deactivatedTx = '0x'.str_repeat('e', 64);
    $unregistered['doctor']->update(['registration_tx_hash' => $deactivatedTx]);

    $this->actingAs($unregistered['doctorUser'])
        ->get(route('dokter.dashboard'))
        ->assertOk()
        ->assertViewHas('authorizedPatientsCount', 0)
        ->assertViewHas('accessibleRecordsCount', 0)
        ->assertViewHas('myLogsTotalCount', 0)
        ->assertSee('Dinonaktifkan')
        ->assertSee("https://sepolia.etherscan.io/tx/{$deactivatedTx}", false)
        ->assertDontSee('MR777');
});

it('executes the 2-step prepare and store CREATE flow with receipt verification and receipt block_number', function () {
    Http::preventStrayRequests();

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
        'patient_code' => $ctx['authorizedPatient']->patient_code,
        'doctor_code' => $ctx['doctor']->doctor_code,
    ]);

    expect($preparedHash)->toBe($expectedHash);

    $txHash = '0x'.str_repeat('A', 64);

    Http::fake([
        'https://eth-sepolia.example.test/v2/test-key' => Http::response([
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => dokterFakeRecordReceipt(
                $txHash,
                '0x30ce74f24973c3793a1a44ecce360de22f3a981e9e7a4b1aed68c6c3611d54a2',
                'MR001',
                $ctx['authorizedPatient']->user->wallet_address,
                $ctx['doctorUser']->wallet_address,
                $expectedHash,
                '0x6ad4aa',
            ),
        ]),
    ]);

    $storeResponse = $this->actingAs($ctx['doctorUser'])
        ->postJson(route('dokter.records.store'), [
            ...$payload,
            'record_code' => 'MR001',
            'current_hash' => strtoupper($preparedHash),
            'tx_hash' => $txHash,
            'block_number' => 9999999,
        ]);

    $storeResponse->assertCreated()
        ->assertJsonPath('medical_record.record_code', 'MR001')
        ->assertJsonPath('log_blockchain.action_type', LogBlockchain::ACTION_CREATE);

    $record = MedicalRecord::query()->where('record_code', 'MR001')->firstOrFail();
    $log = LogBlockchain::query()->where('medical_record_id', $record->id)->firstOrFail();

    expect($record->current_hash)->toBe($expectedHash)
        ->and($log->onchain_hash)->toBe($expectedHash)
        ->and($log->tx_hash)->toBe(strtolower($txHash))
        ->and($log->block_number)->toBe(7001258)
        ->and($log->action_type)->toBe(LogBlockchain::ACTION_CREATE);
});

it('rejects store when submitted current_hash mismatches or on-chain receipt verification fails', function () {
    Http::preventStrayRequests();

    $ctx = setupDoctorAndPatients();

    $payload = [
        'record_code' => 'MR001',
        'patient_id' => $ctx['authorizedPatient']->id,
        'record_date' => '2026-11-26',
        'complaint' => 'Demam',
        'diagnosis' => 'Flu',
        'treatment' => 'Istirahat',
        'notes' => null,
    ];

    $this->actingAs($ctx['doctorUser'])
        ->postJson(route('dokter.records.store'), [
            ...$payload,
            'current_hash' => '0x'.str_repeat('f', 64),
            'tx_hash' => '0x'.str_repeat('e', 64),
        ])
        ->assertUnprocessable();

    $validHash = app(BlockchainService::class)->hashMedicalRecord([
        ...$payload,
        'patient_code' => $ctx['authorizedPatient']->patient_code,
        'doctor_code' => $ctx['doctor']->doctor_code,
    ]);

    $revertedTx = '0x'.str_repeat('d', 64);

    Http::fake([
        'https://eth-sepolia.example.test/v2/test-key' => Http::response([
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => dokterFakeRecordReceipt(
                $revertedTx,
                '0x30ce74f24973c3793a1a44ecce360de22f3a981e9e7a4b1aed68c6c3611d54a2',
                'MR001',
                $ctx['authorizedPatient']->user->wallet_address,
                $ctx['doctorUser']->wallet_address,
                $validHash,
                '0x6ad4aa',
                '0x0',
            ),
        ]),
    ]);

    $this->actingAs($ctx['doctorUser'])
        ->postJson(route('dokter.records.store'), [
            ...$payload,
            'current_hash' => $validHash,
            'tx_hash' => $revertedTx,
        ])
        ->assertUnprocessable()
        ->assertJsonStructure(['message']);

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

it('forbids doctor prepare and hides records index when doctor is not registered on-chain', function () {
    $unregistered = setupDoctorAndPatients(doctorOnchain: false);

    MedicalRecord::create([
        'record_code' => 'MR001',
        'patient_id' => $unregistered['authorizedPatient']->id,
        'doctor_id' => $unregistered['doctor']->id,
        'record_date' => '2026-11-26',
        'complaint' => 'Demam',
        'diagnosis' => 'Flu',
        'treatment' => 'Istirahat',
        'current_hash' => '0x'.str_repeat('a', 64),
    ]);

    $this->actingAs($unregistered['doctorUser'])
        ->get(route('dokter.records.index'))
        ->assertOk()
        ->assertSee('Akun Belum Terdaftar On-Chain')
        ->assertDontSee('MR001');

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

it('executes the 2-step update flow with receipt verification and completely blocks read and edit access once authorization is revoked', function () {
    Http::preventStrayRequests();

    $ctx = setupDoctorAndPatients();
    $service = app(BlockchainService::class);

    $initialHash = $service->hashMedicalRecord([
        'record_code' => 'MR001',
        'patient_code' => $ctx['authorizedPatient']->patient_code,
        'doctor_code' => $ctx['doctor']->doctor_code,
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

    Http::fake([
        'https://eth-sepolia.example.test/v2/test-key' => Http::sequence()
            ->push([
                'jsonrpc' => '2.0',
                'id' => 1,
                'result' => dokterFakeRecordReceipt(
                    $updateTx,
                    '0xfdaac535c2479f1b829c9d1d754dd8e4739233afe60a0917d08855ef393fa4d9',
                    'MR001',
                    $ctx['authorizedPatient']->user->wallet_address,
                    $ctx['doctorUser']->wallet_address,
                    '0x'.str_repeat('9', 64),
                    '0x6ad892',
                ),
            ])
            ->push([
                'jsonrpc' => '2.0',
                'id' => 1,
                'result' => dokterFakeRecordReceipt(
                    $updateTx,
                    '0xfdaac535c2479f1b829c9d1d754dd8e4739233afe60a0917d08855ef393fa4d9',
                    'MR001',
                    $ctx['authorizedPatient']->user->wallet_address,
                    $ctx['doctorUser']->wallet_address,
                    $newHash,
                    '0x6ad892',
                ),
            ]),
    ]);

    // First attempt fails receipt hash verification and leaves DB untouched
    $this->actingAs($ctx['doctorUser'])
        ->putJson(route('dokter.records.update', $record), [
            ...$updatePayload,
            'current_hash' => $newHash,
            'tx_hash' => $updateTx,
        ])
        ->assertUnprocessable();

    expect($record->fresh()->diagnosis)->toBe('Bronkitis awal')
        ->and($record->fresh()->logBlockchains)->toHaveCount(1);

    // Second attempt passes receipt verification and uses receipt block_number
    $this->actingAs($ctx['doctorUser'])
        ->putJson(route('dokter.records.update', $record), [
            ...$updatePayload,
            'current_hash' => $newHash,
            'tx_hash' => $updateTx,
            'block_number' => 123456,
        ])
        ->assertOk()
        ->assertJsonPath('log_blockchain.action_type', LogBlockchain::ACTION_UPDATE)
        ->assertJsonPath('log_blockchain.block_number', 7002258);

    $record->refresh();
    expect($record->diagnosis)->toBe('Bronkitis membaik')
        ->and($record->current_hash)->toBe($newHash)
        ->and($record->logBlockchains)->toHaveCount(2);

    // Revoke authorization: doctor must completely lose both index visibility and edit access
    Authorization::query()
        ->where('patient_id', $ctx['authorizedPatient']->id)
        ->where('doctor_id', $ctx['doctor']->id)
        ->update(['is_authorized' => false]);

    $this->actingAs($ctx['doctorUser'])
        ->get(route('dokter.records.index'))
        ->assertOk()
        ->assertDontSee('MR001')
        ->assertDontSee('Bronkitis membaik');

    $this->actingAs($ctx['doctorUser'])
        ->get(route('dokter.records.edit', $record))
        ->assertForbidden();
});

it('forbids index visibility, edit, prepareStore, store, prepareUpdate, and update when patient is not registered on-chain', function () {
    $ctx = setupDoctorAndPatients();

    $basePayload = [
        'record_date' => '2026-11-26',
        'complaint' => 'Demam ringan',
        'diagnosis' => 'Observasi',
        'treatment' => 'Istirahat',
        'notes' => null,
    ];

    $this->actingAs($ctx['doctorUser'])
        ->postJson(route('dokter.records.prepare'), [
            ...$basePayload,
            'patient_id' => $ctx['unregisteredAuthorizedPatient']->id,
        ])
        ->assertForbidden();

    $this->actingAs($ctx['doctorUser'])
        ->postJson(route('dokter.records.store'), [
            ...$basePayload,
            'record_code' => 'MR900',
            'patient_id' => $ctx['unregisteredAuthorizedPatient']->id,
            'current_hash' => '0x'.str_repeat('a', 64),
            'tx_hash' => '0x'.str_repeat('b', 64),
        ])
        ->assertForbidden();

    $existingRecord = MedicalRecord::create([
        'record_code' => 'MR901',
        'patient_id' => $ctx['unregisteredAuthorizedPatient']->id,
        'doctor_id' => $ctx['doctor']->id,
        'record_date' => '2026-11-25',
        'complaint' => 'Batuk',
        'diagnosis' => 'ISPA',
        'treatment' => 'Vitamin',
        'current_hash' => '0x'.str_repeat('c', 64),
    ]);

    $this->actingAs($ctx['doctorUser'])
        ->get(route('dokter.records.index'))
        ->assertOk()
        ->assertDontSee('MR901');

    $this->actingAs($ctx['doctorUser'])
        ->get(route('dokter.records.edit', $existingRecord))
        ->assertForbidden();

    $this->actingAs($ctx['doctorUser'])
        ->postJson(route('dokter.records.prepare-update', $existingRecord), $basePayload)
        ->assertForbidden();

    $this->actingAs($ctx['doctorUser'])
        ->putJson(route('dokter.records.update', $existingRecord), [
            ...$basePayload,
            'current_hash' => '0x'.str_repeat('d', 64),
            'tx_hash' => '0x'.str_repeat('e', 64),
        ])
        ->assertForbidden();
});
