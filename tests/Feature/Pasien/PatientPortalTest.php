<?php

use App\Models\Authorization;
use App\Models\Doctor;
use App\Models\LogBlockchain;
use App\Models\MedicalRecord;
use App\Models\Patient;
use App\Models\User;
use App\Services\BlockchainService;
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
        'is_registered_onchain' => true,
        'registration_tx_hash' => '0x'.str_repeat('9', 64),
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
        'is_registered_onchain' => true,
        'registration_tx_hash' => '0x'.str_repeat('8', 64),
    ]);

    $unregisteredPatientUser = User::factory()->pasien()->create([
        'username' => 'pasien_belum_onchain',
        'wallet_address' => '0xe5f60718293a4b5c6d7e8f901234567890123456',
    ]);
    $unregisteredPatient = Patient::create([
        'patient_code' => 'PAT003',
        'user_id' => $unregisteredPatientUser->id,
        'full_name' => 'Joko Pending',
        'date_of_birth' => '1988-09-01',
        'gender' => Patient::GENDER_MALE,
        'address' => 'Jl. Diponegoro No. 2',
        'is_registered_onchain' => false,
        'registration_tx_hash' => null,
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

    return compact(
        'patientUser',
        'patient',
        'otherPatientUser',
        'otherPatient',
        'unregisteredPatientUser',
        'unregisteredPatient',
        'activeDoctor',
        'pendingDoctor',
    );
}

function pasienFakeAccessReceipt(
    string $txHash,
    string $topic0,
    string $patientWallet,
    string $doctorWallet,
    string $status = '0x1',
): array {
    $contract = '0x6d1c13645e2b1ca4f4dfb6a4bbd22daa8072f4a4';
    $paddedPatient = '0x'.str_pad(substr(strtolower($patientWallet), 2), 64, '0', STR_PAD_LEFT);
    $paddedDoctor = '0x'.str_pad(substr(strtolower($doctorWallet), 2), 64, '0', STR_PAD_LEFT);

    return [
        'transactionHash' => strtolower($txHash),
        'from' => strtolower($patientWallet),
        'to' => $contract,
        'status' => $status,
        'blockNumber' => '0x6ad3c2',
        'logs' => [[
            'address' => $contract,
            'topics' => [$topic0, $paddedPatient, $paddedDoctor],
            'data' => '0x',
        ]],
    ];
}

function pasienEncodeGetMedicalRecordAbi(
    string $recordHash,
    string $patientWallet,
    string $doctorWallet,
    int $timestamp = 1795680000,
): string {
    return '0x'
        .substr(strtolower($recordHash), 2)
        .str_pad(substr(strtolower($patientWallet), 2), 64, '0', STR_PAD_LEFT)
        .str_pad(substr(strtolower($doctorWallet), 2), 64, '0', STR_PAD_LEFT)
        .str_pad(dechex($timestamp), 64, '0', STR_PAD_LEFT);
}

it('lists only active on-chain doctors, verifies grant/revoke receipts, and prevents tx_hash replay', function () {
    Http::preventStrayRequests();

    $ctx = setupPatientPortalScenario();

    $this->actingAs($ctx['patientUser'])
        ->get(route('pasien.authorizations.index'))
        ->assertOk()
        ->assertSee('Dr. Andi Wijaya')
        ->assertDontSee('Dr. Belum Onchain');

    $grantTx = '0x'.str_repeat('A', 64);
    $revokeTx = '0x'.str_repeat('B', 64);
    $badReceiptTx = '0x'.str_repeat('C', 64);

    Http::fake([
        'https://eth-sepolia.example.test/v2/test-key' => Http::sequence()
            ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => pasienFakeAccessReceipt(
                $grantTx,
                '0x9f520b3bfc56d06f7065d10b4683b3f57ac8895d5026cd176dee95ce1454cb8d',
                $ctx['patientUser']->wallet_address,
                $ctx['activeDoctor']->user->wallet_address,
            )])
            ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x'.str_repeat('0', 63).'1'])
            ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => pasienFakeAccessReceipt(
                $revokeTx,
                '0x825c8be24eb0df19500f63e86c29e7d0d951e73056b889b891d85e40938d9b6e',
                $ctx['patientUser']->wallet_address,
                $ctx['activeDoctor']->user->wallet_address,
            )])
            ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x'.str_repeat('0', 64)])
            ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => pasienFakeAccessReceipt(
                $badReceiptTx,
                '0x9f520b3bfc56d06f7065d10b4683b3f57ac8895d5026cd176dee95ce1454cb8d',
                $ctx['patientUser']->wallet_address,
                $ctx['activeDoctor']->user->wallet_address,
                '0x0',
            )]),
    ]);

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

    $this->actingAs($ctx['patientUser'])
        ->postJson(route('pasien.authorizations.revoke', $ctx['activeDoctor']), [
            'tx_hash' => $revokeTx,
        ])
        ->assertOk()
        ->assertJsonPath('authorization.is_authorized', false);

    expect($authorization->fresh()->is_authorized)->toBeFalse()
        ->and($authorization->fresh()->tx_hash)->toBe(strtolower($revokeTx));

    // Reject replaying an already-recorded tx_hash
    $this->actingAs($ctx['patientUser'])
        ->postJson(route('pasien.authorizations.grant', $ctx['activeDoctor']), [
            'tx_hash' => $grantTx,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('tx_hash');

    // Reject reverted receipt without altering authorization state
    $this->actingAs($ctx['patientUser'])
        ->postJson(route('pasien.authorizations.grant', $ctx['activeDoctor']), [
            'tx_hash' => $badReceiptTx,
        ])
        ->assertUnprocessable()
        ->assertJsonStructure(['message']);

    expect($authorization->fresh()->is_authorized)->toBeFalse();

    $this->actingAs($ctx['patientUser'])
        ->postJson(route('pasien.authorizations.grant', $ctx['pendingDoctor']), [
            'tx_hash' => '0x'.str_repeat('d', 64),
        ])
        ->assertUnprocessable();
});

it('warns and forbids granting or revoking access when the patient is not registered on-chain', function () {
    $ctx = setupPatientPortalScenario();

    Authorization::create([
        'patient_id' => $ctx['unregisteredPatient']->id,
        'doctor_id' => $ctx['activeDoctor']->id,
        'is_authorized' => true,
        'tx_hash' => '0x'.str_repeat('d', 64),
    ]);

    $this->actingAs($ctx['unregisteredPatientUser'])
        ->get(route('pasien.authorizations.index'))
        ->assertOk()
        ->assertSee('Wallet Anda belum terdaftar/aktif di blockchain Sepolia. Hubungi Admin untuk registrasi on-chain sebelum mengatur izin dokter.')
        ->assertSee('disabled', false);

    $this->actingAs($ctx['unregisteredPatientUser'])
        ->postJson(route('pasien.authorizations.grant', $ctx['activeDoctor']), [
            'tx_hash' => '0x'.str_repeat('e', 64),
        ])
        ->assertForbidden()
        ->assertJsonPath(
            'message',
            'Wallet Anda belum terdaftar/aktif di blockchain Sepolia. Hubungi Admin untuk registrasi on-chain sebelum mengatur izin dokter.',
        );

    $this->actingAs($ctx['unregisteredPatientUser'])
        ->postJson(route('pasien.authorizations.revoke', $ctx['activeDoctor']), [
            'tx_hash' => '0x'.str_repeat('f', 64),
        ])
        ->assertForbidden();

    expect(
        Authorization::query()
            ->where('patient_id', $ctx['unregisteredPatient']->id)
            ->where('doctor_id', $ctx['activeDoctor']->id)
            ->firstOrFail()
            ->is_authorized,
    )->toBeTrue();
});

it('restricts patient dashboard medical records to the logged-in patient and hides other patients records', function () {
    $ctx = setupPatientPortalScenario();
    $service = app(BlockchainService::class);

    $ownHash = $service->hashMedicalRecord([
        'record_code' => 'MR001',
        'patient_code' => $ctx['patient']->patient_code,
        'doctor_code' => $ctx['activeDoctor']->doctor_code,
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
        ->assertSee('Dokter Terakhir')
        ->assertSee('&lt;script&gt;', false)
        ->assertDontSee('<script>alert("xss")</script>', false)
        ->assertDontSee('MR002')
        ->assertDontSee('Diagnosis rahasia pasien lain');

    $this->actingAs($ctx['patientUser'])
        ->postJson(route('pasien.records.verify', $otherRecord))
        ->assertNotFound();
});

it('verifies a VALID medical record using a single getMedicalRecord eth_call when hashes and patient wallet match', function () {
    Http::preventStrayRequests();

    $ctx = setupPatientPortalScenario();
    $realService = app(BlockchainService::class);

    $canonicalHash = $realService->hashMedicalRecord([
        'record_code' => 'MR001',
        'patient_code' => $ctx['patient']->patient_code,
        'doctor_code' => $ctx['activeDoctor']->doctor_code,
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

    Http::fake([
        'https://eth-sepolia.example.test/v2/test-key' => Http::response([
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => pasienEncodeGetMedicalRecordAbi(
                $canonicalHash,
                $ctx['patientUser']->wallet_address,
                $ctx['activeDoctor']->user->wallet_address,
                1795680000,
            ),
        ]),
    ]);

    $response = $this->actingAs($ctx['patientUser'])
        ->postJson(route('pasien.records.verify', $record));

    $response->assertOk()
        ->assertJson([
            'is_valid' => true,
            'status' => 'valid',
            'record_code' => 'MR001',
            'recomputed_hash' => $canonicalHash,
            'stored_current_hash' => $canonicalHash,
            'onchain_hash' => $canonicalHash,
            'onchain_patient' => $ctx['patientUser']->wallet_address,
            'patient_wallet_matched' => true,
            'onchain_doctor' => $ctx['activeDoctor']->user->wallet_address,
            'onchain_timestamp' => 1795680000,
            'last_doctor_name' => 'Dr. Andi Wijaya (DOC001)',
            'latest_tx_hash' => $latestTxHash,
            'etherscan_url' => "https://sepolia.etherscan.io/tx/{$latestTxHash}",
        ]);

    Http::assertSentCount(1);
});

it('detects TAMPERED off-chain medical record data during patient verification', function () {
    Http::preventStrayRequests();

    $ctx = setupPatientPortalScenario();
    $realService = app(BlockchainService::class);

    $originalHash = $realService->hashMedicalRecord([
        'record_code' => 'MR002',
        'patient_code' => $ctx['patient']->patient_code,
        'doctor_code' => $ctx['activeDoctor']->doctor_code,
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
    $tamperedRecomputedHash = $realService->hashMedicalRecord($record->fresh(['patient', 'doctor']));

    Http::fake([
        'https://eth-sepolia.example.test/v2/test-key' => Http::response([
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => pasienEncodeGetMedicalRecordAbi(
                $originalHash,
                $ctx['patientUser']->wallet_address,
                $ctx['activeDoctor']->user->wallet_address,
                1795681000,
            ),
        ]),
    ]);

    $response = $this->actingAs($ctx['patientUser'])
        ->postJson(route('pasien.records.verify', $record));

    $response->assertOk()
        ->assertJson([
            'is_valid' => false,
            'status' => 'tampered',
            'record_code' => 'MR002',
            'recomputed_hash' => $tamperedRecomputedHash,
            'stored_current_hash' => $originalHash,
            'onchain_hash' => $originalHash,
            'onchain_patient' => $ctx['patientUser']->wallet_address,
            'patient_wallet_matched' => true,
            'last_doctor_name' => 'Dr. Andi Wijaya (DOC001)',
            'latest_tx_hash' => $latestTxHash,
        ]);

    Http::assertSentCount(1);
});

it('detects PATIENT MISMATCH when on-chain patient wallet differs from logged-in patient wallet', function () {
    Http::preventStrayRequests();

    $ctx = setupPatientPortalScenario();
    $realService = app(BlockchainService::class);

    $canonicalHash = $realService->hashMedicalRecord([
        'record_code' => 'MR003',
        'patient_code' => $ctx['patient']->patient_code,
        'doctor_code' => $ctx['activeDoctor']->doctor_code,
        'record_date' => '2026-11-26',
        'complaint' => 'Nyeri otot',
        'diagnosis' => 'Myalgia',
        'treatment' => 'Ibuprofen',
        'notes' => null,
    ]);

    $record = MedicalRecord::create([
        'record_code' => 'MR003',
        'patient_id' => $ctx['patient']->id,
        'doctor_id' => $ctx['activeDoctor']->id,
        'record_date' => '2026-11-26',
        'complaint' => 'Nyeri otot',
        'diagnosis' => 'Myalgia',
        'treatment' => 'Ibuprofen',
        'current_hash' => $canonicalHash,
    ]);

    Http::fake([
        'https://eth-sepolia.example.test/v2/test-key' => Http::response([
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => pasienEncodeGetMedicalRecordAbi(
                $canonicalHash,
                $ctx['otherPatientUser']->wallet_address,
                $ctx['activeDoctor']->user->wallet_address,
                1795682000,
            ),
        ]),
    ]);

    $this->actingAs($ctx['patientUser'])
        ->postJson(route('pasien.records.verify', $record))
        ->assertOk()
        ->assertJson([
            'is_valid' => false,
            'status' => 'patient_mismatch',
            'record_code' => 'MR003',
            'recomputed_hash' => $canonicalHash,
            'stored_current_hash' => $canonicalHash,
            'onchain_hash' => $canonicalHash,
            'onchain_patient' => $ctx['otherPatientUser']->wallet_address,
            'patient_wallet_matched' => false,
        ]);
});

it('returns HTTP 200 with status not_found_onchain when smart contract reverts with Rekam medis tidak ditemukan', function () {
    Http::preventStrayRequests();

    $ctx = setupPatientPortalScenario();
    $realService = app(BlockchainService::class);

    $canonicalHash = $realService->hashMedicalRecord([
        'record_code' => 'MR004',
        'patient_code' => $ctx['patient']->patient_code,
        'doctor_code' => $ctx['activeDoctor']->doctor_code,
        'record_date' => '2026-11-26',
        'complaint' => 'Pusing',
        'diagnosis' => 'Vertigo',
        'treatment' => 'Betahistine',
        'notes' => null,
    ]);

    $record = MedicalRecord::create([
        'record_code' => 'MR004',
        'patient_id' => $ctx['patient']->id,
        'doctor_id' => $ctx['activeDoctor']->id,
        'record_date' => '2026-11-26',
        'complaint' => 'Pusing',
        'diagnosis' => 'Vertigo',
        'treatment' => 'Betahistine',
        'current_hash' => $canonicalHash,
    ]);

    $revertData = '0x08c379a0'
        .'0000000000000000000000000000000000000000000000000000000000000020'
        .'000000000000000000000000000000000000000000000000000000000000001b'
        .'52656b616d206d6564697320746964616b20646974656d756b616e0000000000';

    Http::fake([
        'https://eth-sepolia.example.test/v2/test-key' => Http::response([
            'jsonrpc' => '2.0',
            'id' => 1,
            'error' => [
                'code' => 3,
                'message' => 'execution reverted',
                'data' => $revertData,
            ],
        ]),
    ]);

    $this->actingAs($ctx['patientUser'])
        ->postJson(route('pasien.records.verify', $record))
        ->assertOk()
        ->assertJson([
            'is_valid' => false,
            'status' => 'not_found_onchain',
            'message' => 'Rekam medis belum tercatat di smart contract Sepolia.',
            'record_code' => 'MR004',
            'recomputed_hash' => $canonicalHash,
            'stored_current_hash' => $canonicalHash,
            'onchain_hash' => null,
            'onchain_patient' => null,
            'patient_wallet_matched' => false,
        ]);
});

it('returns a clean 502 JSON error response when Sepolia RPC returns HTML 502 Bad Gateway', function () {
    Http::preventStrayRequests();

    $ctx = setupPatientPortalScenario();
    $record = MedicalRecord::create([
        'record_code' => 'MR005',
        'patient_id' => $ctx['patient']->id,
        'doctor_id' => $ctx['activeDoctor']->id,
        'record_date' => '2026-11-26',
        'complaint' => 'Pusing',
        'diagnosis' => 'Vertigo',
        'treatment' => 'Betahistine',
        'current_hash' => '0x'.str_repeat('5', 64),
    ]);

    Http::fake([
        'https://eth-sepolia.example.test/v2/test-key' => Http::response(
            '<html><body><h1>502 Bad Gateway</h1></body></html>',
            502,
            ['Content-Type' => 'text/html'],
        ),
    ]);

    $this->actingAs($ctx['patientUser'])
        ->postJson(route('pasien.records.verify', $record))
        ->assertStatus(502)
        ->assertJsonStructure(['message']);
});
