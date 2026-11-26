<?php

use App\Exceptions\BlockchainException;
use App\Models\Doctor;
use App\Models\MedicalRecord;
use App\Models\Patient;
use App\Models\User;
use App\Services\BlockchainService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    Config::set('blockchain.chain_id', 11155111);
    Config::set('blockchain.contract_address', '0x6d1c13645e2b1ca4f4dfb6a4bbd22daa8072f4a4');
    Config::set('blockchain.rpc_url', 'https://eth-sepolia.example.test/v2/test-key');
    Config::set('blockchain.rpc_connect_timeout', 2);
    Config::set('blockchain.rpc_timeout', 5);
});

it('reads chain id and normalized contract address from blockchain config', function () {
    $service = app(BlockchainService::class);

    expect($service->getChainId())->toBe(11155111);
    expect(config('blockchain.contract_address'))->toBe('0x6d1c13645e2b1ca4f4dfb6a4bbd22daa8072f4a4');
});

it('computes identical canonical SHA-256 hashes across create array, update array, and persisted model', function () {
    $service = app(BlockchainService::class);

    $doctorUser = User::create([
        'username' => 'dr_budi',
        'password' => 'secret-password',
        'role' => User::ROLE_DOKTER,
        'wallet_address' => '0xa1b2c3d4e5f60718293a4b5c6d7e8f9012345678',
    ]);
    $patientUser = User::create([
        'username' => 'pasien_ani',
        'password' => 'secret-password',
        'role' => User::ROLE_PASIEN,
        'wallet_address' => '0xb2c3d4e5f60718293a4b5c6d7e8f901234567890',
    ]);

    $doctor = Doctor::create([
        'doctor_code' => 'DOC001',
        'user_id' => $doctorUser->id,
        'full_name' => 'dr. Budi Santoso',
        'specialization' => 'Umum',
        'str_number' => 'STR-2026-0101',
    ]);
    $patient = Patient::create([
        'patient_code' => 'PAT001',
        'user_id' => $patientUser->id,
        'full_name' => 'Ani Kusuma',
        'date_of_birth' => '1994-04-12',
        'gender' => Patient::GENDER_FEMALE,
        'address' => 'Jl. Melati No. 4',
    ]);

    $createPayload = [
        'record_code' => 'MR001',
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'record_date' => '2026-11-26',
        'complaint' => 'Demam tinggi',
        'diagnosis' => 'Infeksi virus',
        'treatment' => 'Paracetamol',
        'notes' => null,
    ];
    $createHash = $service->hashMedicalRecord($createPayload);

    $record = MedicalRecord::create([
        ...$createPayload,
        'current_hash' => $createHash,
    ]);

    expect($service->hashMedicalRecord($record))
        ->toBe($createHash)
        ->toBe('0x94c0ea9d45919e34fae201bd5cbdd9252359e5e0d756344f49f7f88b1c7af48b');

    $updatePayload = [
        'record_code' => $record->record_code,
        'patient_id' => $record->patient_id,
        'doctor_id' => $record->doctor_id,
        'record_date' => '2026-11-26',
        'complaint' => 'Demam tinggi',
        'diagnosis' => 'Infeksi virus membaik',
        'treatment' => 'Paracetamol 500mg',
        'notes' => 'Kontrol ulang 3 hari',
    ];
    $updateHash = $service->hashMedicalRecord($updatePayload);

    $record->update([
        ...$updatePayload,
        'current_hash' => $updateHash,
    ]);
    $record->refresh();

    expect($service->hashMedicalRecord($record))
        ->toBe($updateHash)
        ->not->toBe($createHash)
        ->toMatch('/^0x[a-f0-9]{64}$/');
});

it('calls verifyMedicalRecord via eth_call with right-padded MR001 and returns boolean', function () {
    Http::preventStrayRequests();

    $offchainHash = '0x45b31eeb39bc3ade8c3450ce0a9eafb505570fd9e394414098173b9b1ebdb276';
    $expectedCallData = '0x43e73fcd'
        .'4d52303031000000000000000000000000000000000000000000000000000000'
        .'45b31eeb39bc3ade8c3450ce0a9eafb505570fd9e394414098173b9b1ebdb276';

    Http::fake([
        'https://eth-sepolia.example.test/v2/test-key' => Http::sequence()
            ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x'.str_repeat('0', 63).'1'])
            ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x'.str_repeat('0', 64)]),
    ]);

    $service = app(BlockchainService::class);

    $matched = $service->verifyMedicalRecord('MR001', strtoupper($offchainHash));
    $mismatched = $service->verifyMedicalRecord('MR001', $offchainHash);

    expect($matched)->toBeTrue();
    expect($mismatched)->toBeFalse();

    Http::assertSent(function (Request $request) use ($expectedCallData): bool {
        return $request->url() === 'https://eth-sepolia.example.test/v2/test-key'
            && $request['method'] === 'eth_call'
            && $request['params'][0]['to'] === '0x6d1c13645e2b1ca4f4dfb6a4bbd22daa8072f4a4'
            && $request['params'][0]['data'] === $expectedCallData
            && $request['params'][1] === 'latest';
    });
});

it('calls getMedicalRecordHash via eth_call and returns lowercase bytes32 hash', function () {
    Http::preventStrayRequests();

    $expectedCallData = '0x2f57b23a4d52303031000000000000000000000000000000000000000000000000000000';
    $returnedHash = '0x45B31EEB39BC3ADE8C3450CE0A9EAFB505570FD9E394414098173B9B1EBDB276';

    Http::fake([
        'https://eth-sepolia.example.test/v2/test-key' => Http::response([
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => $returnedHash,
        ]),
    ]);

    $hash = app(BlockchainService::class)->getMedicalRecordHash('MR001');

    expect($hash)->toBe('0x45b31eeb39bc3ade8c3450ce0a9eafb505570fd9e394414098173b9b1ebdb276');

    Http::assertSent(fn (Request $request): bool => $request['params'][0]['data'] === $expectedCallData);
});

it('calls getMedicalRecord via eth_call and decodes hash, addresses, and timestamp', function () {
    Http::preventStrayRequests();

    $expectedCallData = '0x512404e54d52303031000000000000000000000000000000000000000000000000000000';
    $abiTupleHex = '0x'
        .'45b31eeb39bc3ade8c3450ce0a9eafb505570fd9e394414098173b9b1ebdb276'
        .'000000000000000000000000b2c3d4e5f60718293a4b5c6d7e8f901234567890'
        .'000000000000000000000000a1b2c3d4e5f60718293a4b5c6d7e8f9012345678'
        .'000000000000000000000000000000000000000000000000000000006b07e700';

    Http::fake([
        'https://eth-sepolia.example.test/v2/test-key' => Http::response([
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => $abiTupleHex,
        ]),
    ]);

    $record = app(BlockchainService::class)->getMedicalRecord('MR001');

    expect($record)->toBe([
        'record_hash' => '0x45b31eeb39bc3ade8c3450ce0a9eafb505570fd9e394414098173b9b1ebdb276',
        'patient' => '0xb2c3d4e5f60718293a4b5c6d7e8f901234567890',
        'doctor' => '0xa1b2c3d4e5f60718293a4b5c6d7e8f9012345678',
        'timestamp' => 1795680000,
    ]);

    Http::assertSent(fn (Request $request): bool => $request['params'][0]['data'] === $expectedCallData);
});

it('calls hasAccess and isDoctorRegistered via eth_call with padded lowercase addresses', function () {
    Http::preventStrayRequests();

    $patientAddress = '0xB2C3D4E5F60718293A4B5C6D7E8F901234567890';
    $doctorAddress = '0xA1B2C3D4E5F60718293A4B5C6D7E8F9012345678';

    $expectedHasAccessData = '0x1709ef07'
        .'000000000000000000000000b2c3d4e5f60718293a4b5c6d7e8f901234567890'
        .'000000000000000000000000a1b2c3d4e5f60718293a4b5c6d7e8f9012345678';
    $expectedDoctorRegisteredData = '0x78550390'
        .'000000000000000000000000a1b2c3d4e5f60718293a4b5c6d7e8f9012345678';

    Http::fake([
        'https://eth-sepolia.example.test/v2/test-key' => Http::sequence()
            ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x'.str_repeat('0', 63).'1'])
            ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x'.str_repeat('0', 64)]),
    ]);

    $service = app(BlockchainService::class);

    $hasAccess = $service->hasAccess($patientAddress, $doctorAddress);
    $isRegistered = $service->isDoctorRegistered($doctorAddress);

    expect($hasAccess)->toBeTrue();
    expect($isRegistered)->toBeFalse();

    Http::assertSent(fn (Request $request): bool => $request['params'][0]['data'] === $expectedHasAccessData);
    Http::assertSent(fn (Request $request): bool => $request['params'][0]['data'] === $expectedDoctorRegisteredData);
});

it('decodes solidity revert reason when rpc returns an execution reverted error', function () {
    Http::preventStrayRequests();

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

    expect(fn () => app(BlockchainService::class)->getMedicalRecordHash('MR404'))
        ->toThrow(BlockchainException::class, 'Rekam medis tidak ditemukan');
});

it('throws BlockchainException when rpc returns empty abi data', function () {
    Http::preventStrayRequests();

    Http::fake([
        'https://eth-sepolia.example.test/v2/test-key' => Http::response([
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => '0x',
        ]),
    ]);

    expect(fn () => app(BlockchainService::class)->isDoctorRegistered('0xa1b2c3d4e5f60718293a4b5c6d7e8f9012345678'))
        ->toThrow(BlockchainException::class, 'Respons RPC tidak valid');
});

it('throws BlockchainException when rpc connection fails after retries', function () {
    Http::preventStrayRequests();

    Http::fake([
        'https://eth-sepolia.example.test/v2/test-key' => Http::failedConnection('Connection timed out'),
    ]);

    expect(fn () => app(BlockchainService::class)->isDoctorRegistered('0xa1b2c3d4e5f60718293a4b5c6d7e8f9012345678'))
        ->toThrow(BlockchainException::class, 'Gagal terhubung ke node RPC Sepolia');
});

it('throws BlockchainException when rpc url or contract address configuration is missing', function () {
    Config::set('blockchain.rpc_url', null);

    expect(fn () => app(BlockchainService::class)->isDoctorRegistered('0xa1b2c3d4e5f60718293a4b5c6d7e8f9012345678'))
        ->toThrow(BlockchainException::class, 'blockchain.rpc_url');

    Config::set('blockchain.rpc_url', 'https://eth-sepolia.example.test/v2/test-key');
    Config::set('blockchain.contract_address', null);

    expect(fn () => app(BlockchainService::class)->isDoctorRegistered('0xa1b2c3d4e5f60718293a4b5c6d7e8f9012345678'))
        ->toThrow(BlockchainException::class, 'blockchain.contract_address');
});
