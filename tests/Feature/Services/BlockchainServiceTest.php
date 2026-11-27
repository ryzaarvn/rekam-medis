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
    Config::set('blockchain.receipt_max_attempts', 3);
    Config::set('blockchain.receipt_retry_delay_ms', 0);
});

function servicePadAddressTopic(string $address): string
{
    return '0x'.str_pad(substr(strtolower($address), 2), 64, '0', STR_PAD_LEFT);
}

function serviceMakeReceipt(
    string $txHash,
    string $from,
    string $to,
    string $status,
    array $logs,
    string $blockNumberHex = '0x6ad3c2',
): array {
    return [
        'transactionHash' => strtolower($txHash),
        'from' => strtolower($from),
        'to' => strtolower($to),
        'status' => $status,
        'blockNumber' => $blockNumberHex,
        'logs' => $logs,
    ];
}

it('reads chain id and normalized contract address from blockchain config', function () {
    $service = app(BlockchainService::class);

    expect($service->getChainId())->toBe(11155111);
    expect(config('blockchain.contract_address'))->toBe('0x6d1c13645e2b1ca4f4dfb6a4bbd22daa8072f4a4');
});

it('computes identical canonical SHA-256 hashes using domain codes across create array, update array, and persisted model', function () {
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
        'patient_code' => $patient->patient_code,
        'doctor_code' => $doctor->doctor_code,
        'record_date' => '2026-11-26',
        'complaint' => 'Demam tinggi',
        'diagnosis' => 'Infeksi virus',
        'treatment' => 'Paracetamol',
        'notes' => null,
    ];
    $createHash = $service->hashMedicalRecord($createPayload);

    $record = MedicalRecord::create([
        'record_code' => 'MR001',
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'record_date' => '2026-11-26',
        'complaint' => 'Demam tinggi',
        'diagnosis' => 'Infeksi virus',
        'treatment' => 'Paracetamol',
        'notes' => null,
        'current_hash' => $createHash,
    ]);

    expect($service->hashMedicalRecord($record))
        ->toBe($createHash)
        ->toBe('0x042f0ec47b830fb0865b11c660753704eed7d836350205409ec19cee287d41fd');

    $updatePayload = [
        'record_code' => $record->record_code,
        'patient_code' => $patient->patient_code,
        'doctor_code' => $doctor->doctor_code,
        'record_date' => '2026-11-26',
        'complaint' => 'Demam tinggi',
        'diagnosis' => 'Infeksi virus membaik',
        'treatment' => 'Paracetamol 500mg',
        'notes' => 'Kontrol ulang 3 hari',
    ];
    $updateHash = $service->hashMedicalRecord($updatePayload);

    $record->update([
        'record_date' => '2026-11-26',
        'complaint' => 'Demam tinggi',
        'diagnosis' => 'Infeksi virus membaik',
        'treatment' => 'Paracetamol 500mg',
        'notes' => 'Kontrol ulang 3 hari',
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

    expect($service->hasAccess($patientAddress, $doctorAddress))->toBeTrue();
    expect($service->isDoctorRegistered($doctorAddress))->toBeFalse();

    Http::assertSent(fn (Request $request): bool => $request['params'][0]['data'] === $expectedHasAccessData);
    Http::assertSent(fn (Request $request): bool => $request['params'][0]['data'] === $expectedDoctorRegisteredData);
});

it('calls isPatientRegistered via eth_call with padded lowercase address and handles errors', function () {
    Http::preventStrayRequests();

    $patientAddress = '0xB2C3D4E5F60718293A4B5C6D7E8F901234567890';
    $expectedPatientRegisteredData = '0x80b6b1b8'
        .'000000000000000000000000b2c3d4e5f60718293a4b5c6d7e8f901234567890';

    $revertData = '0x08c379a0'
        .'0000000000000000000000000000000000000000000000000000000000000020'
        .'0000000000000000000000000000000000000000000000000000000000000016'
        .'50617369656e2062656c756d2074657264616674617200000000000000000000';

    Http::fake([
        'https://eth-sepolia.example.test/v2/test-key' => Http::sequence()
            ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x'.str_repeat('0', 63).'1'])
            ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x'.str_repeat('0', 64)])
            ->push([
                'jsonrpc' => '2.0',
                'id' => 1,
                'error' => [
                    'code' => 3,
                    'message' => 'execution reverted',
                    'data' => $revertData,
                ],
            ]),
    ]);

    $service = app(BlockchainService::class);

    expect($service->isPatientRegistered($patientAddress))->toBeTrue();
    expect($service->isPatientRegistered($patientAddress))->toBeFalse();
    expect(fn () => $service->isPatientRegistered($patientAddress))
        ->toThrow(BlockchainException::class, 'Pasien belum terdaftar');

    Http::assertSent(fn (Request $request): bool => $request['params'][0]['data'] === $expectedPatientRegisteredData);
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

    try {
        app(BlockchainService::class)->getMedicalRecord('MR404');
        $this->fail('Expected BlockchainException was not thrown.');
    } catch (BlockchainException $exception) {
        expect($exception->getMessage())->toContain('Rekam medis tidak ditemukan')
            ->and($exception->revertReason())->toBe('Rekam medis tidak ditemukan')
            ->and($exception->getHttpStatus())->toBe(502);
    }
});

it('wraps non-json html 502 and 503 rpc responses into BlockchainException instead of RequestException', function (int $statusCode, string $htmlBody) {
    Http::preventStrayRequests();

    Http::fake([
        'https://eth-sepolia.example.test/v2/test-key' => Http::response($htmlBody, $statusCode, [
            'Content-Type' => 'text/html',
        ]),
    ]);

    try {
        app(BlockchainService::class)->getMedicalRecord('MR001');
        $this->fail('Expected BlockchainException was not thrown.');
    } catch (BlockchainException $exception) {
        expect($exception->getMessage())->toContain("HTTP {$statusCode}")
            ->and($exception->getHttpStatus())->toBe(502);
    }
})->with([
    '502 Bad Gateway' => [502, '<html><body><h1>502 Bad Gateway</h1></body></html>'],
    '503 Service Unavailable' => [503, '<html><body><h1>503 Service Unavailable</h1></body></html>'],
]);

it('throws BlockchainException when rpc returns json missing both result and error or empty abi data', function () {
    Http::preventStrayRequests();

    Http::fake([
        'https://eth-sepolia.example.test/v2/test-key' => Http::sequence()
            ->push(['jsonrpc' => '2.0', 'id' => 1])
            ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x']),
    ]);

    $service = app(BlockchainService::class);

    expect(fn () => $service->isDoctorRegistered('0xa1b2c3d4e5f60718293a4b5c6d7e8f9012345678'))
        ->toThrow(BlockchainException::class, 'Respons RPC tidak valid');

    expect(fn () => $service->isDoctorRegistered('0xa1b2c3d4e5f60718293a4b5c6d7e8f9012345678'))
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

it('retries eth_getTransactionReceipt when result is initially null and parses hex blockNumber', function () {
    Http::preventStrayRequests();

    $txHash = '0x'.str_repeat('a', 64);
    $sender = '0x42b01a713ea7a8eca09d36a9d8fa177ac301e1c3';
    $contract = '0x6d1c13645e2b1ca4f4dfb6a4bbd22daa8072f4a4';

    Http::fake([
        'https://eth-sepolia.example.test/v2/test-key' => Http::sequence()
            ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => null])
            ->push([
                'jsonrpc' => '2.0',
                'id' => 1,
                'result' => serviceMakeReceipt($txHash, $sender, $contract, '0x1', [], '0x6ad3c2'),
            ]),
    ]);

    $receipt = app(BlockchainService::class)->getTransactionReceipt($txHash, $sender);

    expect($receipt['tx_hash'])->toBe($txHash)
        ->and($receipt['block_number'])->toBe(7001026)
        ->and($receipt['from'])->toBe($sender)
        ->and($receipt['to'])->toBe($contract)
        ->and($receipt['status'])->toBe('0x1');

    Http::assertSentCount(2);
});

it('throws 422 verification BlockchainException for missing, reverted, wrong-contract, or wrong-sender receipts', function (array $rpcResults, string $expectedError) {
    Http::preventStrayRequests();

    $txHash = '0x'.str_repeat('b', 64);
    $expectedSender = '0x42b01a713ea7a8eca09d36a9d8fa177ac301e1c3';

    $sequence = Http::sequence();
    foreach ($rpcResults as $result) {
        $sequence->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => $result]);
    }

    Http::fake([
        'https://eth-sepolia.example.test/v2/test-key' => $sequence,
    ]);

    try {
        app(BlockchainService::class)->getTransactionReceipt($txHash, $expectedSender);
        $this->fail('Expected BlockchainException was not thrown.');
    } catch (BlockchainException $exception) {
        expect($exception->getHttpStatus())->toBe(422)
            ->and($exception->getMessage())->toContain($expectedError);
    }
})->with([
    'null after retries' => [[null, null, null], 'tidak ditemukan'],
    'reverted status 0x0' => [[serviceMakeReceipt('0x'.str_repeat('b', 64), '0x42b01a713ea7a8eca09d36a9d8fa177ac301e1c3', '0x6d1c13645e2b1ca4f4dfb6a4bbd22daa8072f4a4', '0x0', [])], 'reverted'],
    'wrong contract to address' => [[serviceMakeReceipt('0x'.str_repeat('b', 64), '0x42b01a713ea7a8eca09d36a9d8fa177ac301e1c3', '0x9999999999999999999999999999999999999999', '0x1', [])], 'tidak cocok dengan smart contract'],
    'wrong sender from address' => [[serviceMakeReceipt('0x'.str_repeat('b', 64), '0x1111111111111111111111111111111111111111', '0x6d1c13645e2b1ca4f4dfb6a4bbd22daa8072f4a4', '0x1', [])], 'Pengirim transaksi'],
]);

it('verifies all admin and patient role lifecycle transactions with matching events and current on-chain state', function () {
    Http::preventStrayRequests();

    $contract = '0x6d1c13645e2b1ca4f4dfb6a4bbd22daa8072f4a4';
    $admin = '0x42b01a713ea7a8eca09d36a9d8fa177ac301e1c3';
    $doctor = '0xa1b2c3d4e5f60718293a4b5c6d7e8f9012345678';
    $patient = '0xb2c3d4e5f60718293a4b5c6d7e8f901234567890';
    $tx = fn (string $c): string => '0x'.str_repeat($c, 64);

    $boolTrue = '0x'.str_repeat('0', 63).'1';
    $boolFalse = '0x'.str_repeat('0', 64);

    Http::fake([
        'https://eth-sepolia.example.test/v2/test-key' => Http::sequence()
            // 1. DoctorAdded
            ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => serviceMakeReceipt($tx('1'), $admin, $contract, '0x1', [[
                'address' => $contract,
                'topics' => ['0x69908b2e63170aa89ef89719bbe3b4c92cccc38653968ab25f4464eff7c28ac8', servicePadAddressTopic($doctor)],
                'data' => '0x',
            ]])])
            ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => $boolTrue])
            // 2. DoctorRemoved
            ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => serviceMakeReceipt($tx('2'), $admin, $contract, '0x1', [[
                'address' => $contract,
                'topics' => ['0x493d7cf6a7c880406a55654d6727464ea088bd01d40498e1987a327e68961ab8', servicePadAddressTopic($doctor)],
                'data' => '0x',
            ]])])
            ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => $boolFalse])
            // 3. PatientAdded
            ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => serviceMakeReceipt($tx('3'), $admin, $contract, '0x1', [[
                'address' => $contract,
                'topics' => ['0xa58cff07db0adda3ab7a40bd2f18ab57210bf38391a52ffd2e8eee6b8bdec71a', servicePadAddressTopic($patient)],
                'data' => '0x',
            ]])])
            ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => $boolTrue])
            // 4. PatientRemoved
            ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => serviceMakeReceipt($tx('4'), $admin, $contract, '0x1', [[
                'address' => $contract,
                'topics' => ['0x053a4badf166f01c4b02f6bcb71d2d67734470d0866282e291e73001aa25c769', servicePadAddressTopic($patient)],
                'data' => '0x',
            ]])])
            ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => $boolFalse])
            // 5. AccessGranted
            ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => serviceMakeReceipt($tx('5'), $patient, $contract, '0x1', [[
                'address' => $contract,
                'topics' => [
                    '0x9f520b3bfc56d06f7065d10b4683b3f57ac8895d5026cd176dee95ce1454cb8d',
                    servicePadAddressTopic($patient),
                    servicePadAddressTopic($doctor),
                ],
                'data' => '0x',
            ]])])
            ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => $boolTrue])
            // 6. AccessRevoked
            ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => serviceMakeReceipt($tx('6'), $patient, $contract, '0x1', [[
                'address' => $contract,
                'topics' => [
                    '0x825c8be24eb0df19500f63e86c29e7d0d951e73056b889b891d85e40938d9b6e',
                    servicePadAddressTopic($patient),
                    servicePadAddressTopic($doctor),
                ],
                'data' => '0x',
            ]])])
            ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => $boolFalse]),
    ]);

    $service = app(BlockchainService::class);

    expect($service->verifyDoctorAddedTransaction($tx('1'), $admin, $doctor)['tx_hash'])->toBe($tx('1'));
    expect($service->verifyDoctorRemovedTransaction($tx('2'), $admin, $doctor)['tx_hash'])->toBe($tx('2'));
    expect($service->verifyPatientAddedTransaction($tx('3'), $admin, $patient)['tx_hash'])->toBe($tx('3'));
    expect($service->verifyPatientRemovedTransaction($tx('4'), $admin, $patient)['tx_hash'])->toBe($tx('4'));
    expect($service->verifyAccessGrantedTransaction($tx('5'), $patient, $doctor)['tx_hash'])->toBe($tx('5'));
    expect($service->verifyAccessRevokedTransaction($tx('6'), $patient, $doctor)['tx_hash'])->toBe($tx('6'));
});

it('rejects verifyDoctorAddedTransaction when event topic mismatches or current on-chain state is inactive', function () {
    Http::preventStrayRequests();

    $contract = '0x6d1c13645e2b1ca4f4dfb6a4bbd22daa8072f4a4';
    $admin = '0x42b01a713ea7a8eca09d36a9d8fa177ac301e1c3';
    $doctor = '0xa1b2c3d4e5f60718293a4b5c6d7e8f9012345678';
    $tx = '0x'.str_repeat('7', 64);

    Http::fake([
        'https://eth-sepolia.example.test/v2/test-key' => Http::sequence()
            // Wrong event topic0
            ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => serviceMakeReceipt($tx, $admin, $contract, '0x1', [[
                'address' => $contract,
                'topics' => ['0x'.str_repeat('f', 64), servicePadAddressTopic($doctor)],
                'data' => '0x',
            ]])])
            // Valid event, but isDoctorRegistered returns false (e.g., replay after removal)
            ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => serviceMakeReceipt($tx, $admin, $contract, '0x1', [[
                'address' => $contract,
                'topics' => ['0x69908b2e63170aa89ef89719bbe3b4c92cccc38653968ab25f4464eff7c28ac8', servicePadAddressTopic($doctor)],
                'data' => '0x',
            ]])])
            ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x'.str_repeat('0', 64)]),
    ]);

    $service = app(BlockchainService::class);

    expect(fn () => $service->verifyDoctorAddedTransaction($tx, $admin, $doctor))
        ->toThrow(BlockchainException::class, 'Event smart contract yang diharapkan tidak ditemukan');

    expect(fn () => $service->verifyDoctorAddedTransaction($tx, $admin, $doctor))
        ->toThrow(BlockchainException::class, 'tidak aktif di smart contract Sepolia');
});

it('verifies MedicalRecordAdded and MedicalRecordUpdated receipts and rejects mismatched hash data', function () {
    Http::preventStrayRequests();

    $contract = '0x6d1c13645e2b1ca4f4dfb6a4bbd22daa8072f4a4';
    $doctor = '0xa1b2c3d4e5f60718293a4b5c6d7e8f9012345678';
    $patient = '0xb2c3d4e5f60718293a4b5c6d7e8f901234567890';
    $recordId = BlockchainService::encodeRecordId('MR001');
    $expectedHash = '0x8c3abcfaf0dfde354421c3695e0dfd83255a18e3c48eea5a8fa5379d053e518e';
    $wrongHash = '0x'.str_repeat('9', 64);
    $timestampWord = str_pad(dechex(1795680000), 64, '0', STR_PAD_LEFT);
    $txAdd = '0x'.str_repeat('a', 64);
    $txUpdate = '0x'.str_repeat('b', 64);

    Http::fake([
        'https://eth-sepolia.example.test/v2/test-key' => Http::sequence()
            ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => serviceMakeReceipt($txAdd, $doctor, $contract, '0x1', [[
                'address' => $contract,
                'topics' => [
                    '0x30ce74f24973c3793a1a44ecce360de22f3a981e9e7a4b1aed68c6c3611d54a2',
                    $recordId,
                    servicePadAddressTopic($patient),
                    servicePadAddressTopic($doctor),
                ],
                'data' => $expectedHash.$timestampWord,
            ]], '0x6ad4aa')])
            ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => serviceMakeReceipt($txUpdate, $doctor, $contract, '0x1', [[
                'address' => $contract,
                'topics' => [
                    '0xfdaac535c2479f1b829c9d1d754dd8e4739233afe60a0917d08855ef393fa4d9',
                    $recordId,
                    servicePadAddressTopic($patient),
                    servicePadAddressTopic($doctor),
                ],
                'data' => $expectedHash.$timestampWord,
            ]], '0x6ad892')])
            ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => serviceMakeReceipt($txUpdate, $doctor, $contract, '0x1', [[
                'address' => $contract,
                'topics' => [
                    '0xfdaac535c2479f1b829c9d1d754dd8e4739233afe60a0917d08855ef393fa4d9',
                    $recordId,
                    servicePadAddressTopic($patient),
                    servicePadAddressTopic($doctor),
                ],
                'data' => $wrongHash.$timestampWord,
            ]], '0x6ad892')]),
    ]);

    $service = app(BlockchainService::class);

    $added = $service->verifyMedicalRecordAddedTransaction($txAdd, $doctor, 'MR001', $patient, $expectedHash);
    expect($added)->toBe([
        'tx_hash' => $txAdd,
        'block_number' => 7001258,
        'onchain_hash' => $expectedHash,
    ]);

    $updated = $service->verifyMedicalRecordUpdatedTransaction($txUpdate, $doctor, 'MR001', $patient, $expectedHash);
    expect($updated)->toBe([
        'tx_hash' => $txUpdate,
        'block_number' => 7002258,
        'onchain_hash' => $expectedHash,
    ]);

    expect(fn () => $service->verifyMedicalRecordUpdatedTransaction($txUpdate, $doctor, 'MR001', $patient, $expectedHash))
        ->toThrow(BlockchainException::class, 'Hash rekam medis pada event blockchain tidak cocok');
});
