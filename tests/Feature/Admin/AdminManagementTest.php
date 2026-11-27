<?php

use App\Models\Authorization;
use App\Models\Doctor;
use App\Models\LogBlockchain;
use App\Models\MedicalRecord;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Config::set('blockchain.chain_id', 11155111);
    Config::set('blockchain.contract_address', '0x6d1c13645e2b1ca4f4dfb6a4bbd22daa8072f4a4');
    Config::set('blockchain.rpc_url', 'https://eth-sepolia.example.test/v2/test-key');
    Config::set('blockchain.admin_wallet_address', '0x42b01a713ea7a8eca09d36a9d8fa177ac301e1c3');
    Config::set('blockchain.receipt_max_attempts', 1);
    Config::set('blockchain.receipt_retry_delay_ms', 0);
});

function makeAdminUser(): User
{
    return User::factory()->admin()->create([
        'username' => 'admin_utama',
        'wallet_address' => '0x42b01a713ea7a8eca09d36a9d8fa177ac301e1c3',
    ]);
}

function adminFakeEventReceipt(
    string $txHash,
    string $from,
    string $topic0,
    string $indexedAddress,
    string $status = '0x1',
): array {
    $contract = '0x6d1c13645e2b1ca4f4dfb6a4bbd22daa8072f4a4';
    $paddedAddress = '0x'.str_pad(substr(strtolower($indexedAddress), 2), 64, '0', STR_PAD_LEFT);

    return [
        'transactionHash' => strtolower($txHash),
        'from' => strtolower($from),
        'to' => $contract,
        'status' => $status,
        'blockNumber' => '0x6ad3c2',
        'logs' => [[
            'address' => $contract,
            'topics' => [$topic0, $paddedAddress],
            'data' => '0x',
        ]],
    ];
}

it('displays analytics counts and recent blockchain logs on the admin dashboard', function () {
    $admin = makeAdminUser();

    $doctorUser = User::factory()->dokter()->create();
    $doctor = Doctor::create([
        'doctor_code' => 'DOC001',
        'user_id' => $doctorUser->id,
        'full_name' => 'dr. Sinta Dewi',
        'specialization' => 'Kardiologi',
        'str_number' => 'STR-2026-001',
        'is_registered_onchain' => true,
        'registration_tx_hash' => '0x'.str_repeat('1', 64),
    ]);

    $patientUser = User::factory()->pasien()->create();
    $patient = Patient::create([
        'patient_code' => 'PAT001',
        'user_id' => $patientUser->id,
        'full_name' => 'Ani Kusuma',
        'date_of_birth' => '1994-03-15',
        'gender' => Patient::GENDER_FEMALE,
        'address' => 'Jl. Anggrek No. 5',
        'is_registered_onchain' => true,
        'registration_tx_hash' => '0x'.str_repeat('2', 64),
    ]);

    $unregisteredPatientUser = User::factory()->pasien()->create();
    Patient::create([
        'patient_code' => 'PAT002',
        'user_id' => $unregisteredPatientUser->id,
        'full_name' => 'Bambang Wijaya',
        'date_of_birth' => '1990-01-20',
        'gender' => Patient::GENDER_MALE,
        'address' => 'Jl. Kenanga No. 8',
        'is_registered_onchain' => false,
    ]);

    $record = MedicalRecord::create([
        'record_code' => 'MR001',
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'record_date' => '2026-11-26',
        'complaint' => 'Nyeri dada ringan',
        'diagnosis' => 'Dispepsia',
        'treatment' => 'Antasida',
        'current_hash' => '0x'.str_repeat('a', 64),
    ]);

    $txHash = '0x'.str_repeat('b', 64);
    LogBlockchain::create([
        'medical_record_id' => $record->id,
        'doctor_id' => $doctor->id,
        'onchain_hash' => '0x'.str_repeat('a', 64),
        'tx_hash' => $txHash,
        'block_number' => 7000101,
        'action_type' => LogBlockchain::ACTION_CREATE,
    ]);

    $response = $this->actingAs($admin)->get(route('admin.dashboard'));

    $response->assertOk()
        ->assertViewHas('stats', fn (array $stats): bool => $stats['total_doctors'] === 1
            && $stats['onchain_doctors'] === 1
            && $stats['total_patients'] === 2
            && $stats['onchain_patients'] === 1
            && $stats['total_records'] === 1)
        ->assertSee('Total Dokter')
        ->assertSee('Dokter Terdaftar On-Chain')
        ->assertSee('Total Pasien')
        ->assertSee('Pasien Terdaftar On-Chain')
        ->assertSee('MR001')
        ->assertSee('CREATE')
        ->assertSee("https://sepolia.etherscan.io/tx/{$txHash}");
});

it('creates, verifies on-chain registration and deactivation with authorization cascade, and deletes a doctor cleanly', function () {
    Http::preventStrayRequests();

    $admin = makeAdminUser();
    $doctorWallet = '0xa1b2c3d4e5f60718293a4b5c6d7e8f9012345678';

    $createResponse = $this->actingAs($admin)->post(route('admin.doctors.store'), [
        'full_name' => 'dr. Hendra <script>alert(1)</script>',
        'specialization' => 'Penyakit Dalam',
        'str_number' => 'STR-2026-0100',
        'username' => 'dr_hendra',
        'email' => 'hendra@klinik.id',
        'password' => 'Password123!',
        'wallet_address' => strtoupper($doctorWallet),
    ]);

    $createResponse->assertRedirect(route('admin.doctors.index'));

    $doctor = Doctor::query()->with('user')->where('str_number', 'STR-2026-0100')->firstOrFail();

    expect($doctor->doctor_code)->toBe('DOC001')
        ->and($doctor->is_registered_onchain)->toBeFalse()
        ->and($doctor->user->role)->toBe(User::ROLE_DOKTER)
        ->and($doctor->user->wallet_address)->toBe($doctorWallet);

    $this->actingAs($admin)
        ->get(route('admin.doctors.index'))
        ->assertOk()
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
        ->assertDontSee('<script>alert(1)</script>', false);

    $registerTx = '0x'.str_repeat('C', 64);
    $deactivateTx = '0x'.str_repeat('D', 64);

    Http::fake([
        'https://eth-sepolia.example.test/v2/test-key' => Http::sequence()
            ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => adminFakeEventReceipt(
                $registerTx,
                $admin->wallet_address,
                '0x69908b2e63170aa89ef89719bbe3b4c92cccc38653968ab25f4464eff7c28ac8',
                $doctorWallet,
            )])
            ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x'.str_repeat('0', 63).'1'])
            ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => adminFakeEventReceipt(
                $deactivateTx,
                $admin->wallet_address,
                '0x493d7cf6a7c880406a55654d6727464ea088bd01d40498e1987a327e68961ab8',
                $doctorWallet,
            )])
            ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x'.str_repeat('0', 64)]),
    ]);

    $this->actingAs($admin)
        ->postJson(route('admin.doctors.onchain-register', $doctor), [
            'registration_tx_hash' => $registerTx,
        ])
        ->assertOk()
        ->assertJsonPath('doctor.is_registered_onchain', true);

    $doctor->refresh();
    expect($doctor->is_registered_onchain)->toBeTrue()
        ->and($doctor->registration_tx_hash)->toBe(strtolower($registerTx));

    $patientUser = User::factory()->pasien()->create();
    $patient = Patient::create([
        'patient_code' => 'PAT001',
        'user_id' => $patientUser->id,
        'full_name' => 'Pasien Terhubung',
        'date_of_birth' => '1995-05-05',
        'gender' => Patient::GENDER_MALE,
        'address' => 'Jl. Merdeka No. 1',
        'is_registered_onchain' => true,
        'registration_tx_hash' => '0x'.str_repeat('8', 64),
    ]);
    $auth = Authorization::create([
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'is_authorized' => true,
        'tx_hash' => '0x'.str_repeat('7', 64),
    ]);

    $this->actingAs($admin)
        ->postJson(route('admin.doctors.onchain-deactivate', $doctor), [
            'tx_hash' => $deactivateTx,
        ])
        ->assertOk()
        ->assertJsonPath('doctor.is_registered_onchain', false)
        ->assertJsonPath('etherscan_url', 'https://sepolia.etherscan.io/tx/'.strtolower($deactivateTx));

    $doctor->refresh();
    $auth->refresh();
    expect($doctor->is_registered_onchain)->toBeFalse()
        ->and($doctor->registration_tx_hash)->toBe(strtolower($registerTx))
        ->and($auth->is_authorized)->toBeFalse()
        ->and($auth->tx_hash)->toBeNull();

    $this->actingAs($admin)->put(route('admin.doctors.update', $doctor), [
        'doctor_code' => 'DOC001',
        'full_name' => 'dr. Hendra Wijaya',
        'specialization' => 'Saraf',
        'str_number' => 'STR-2026-0100',
        'username' => 'dr_hendra',
        'email' => 'hendra@klinik.id',
        'password' => '',
        'wallet_address' => '0x99B2C3D4E5F60718293A4B5C6D7E8F9012345678',
    ])->assertRedirect(route('admin.doctors.index'));

    $doctor->refresh();
    expect($doctor->full_name)->toBe('dr. Hendra Wijaya')
        ->and($doctor->user->wallet_address)->toBe('0x99b2c3d4e5f60718293a4b5c6d7e8f9012345678')
        ->and($doctor->registration_tx_hash)->toBeNull();

    $this->actingAs($admin)
        ->delete(route('admin.doctors.destroy', $doctor))
        ->assertRedirect(route('admin.doctors.index'));

    $this->assertDatabaseMissing('doctors', ['id' => $doctor->id]);
    $this->assertDatabaseMissing('users', ['id' => $doctor->user_id]);
});

it('rejects invalid, empty, or unverified transaction hashes when registering or deactivating doctor and patient on-chain', function () {
    Http::preventStrayRequests();

    $admin = makeAdminUser();
    $doctorUser = User::factory()->dokter()->create([
        'wallet_address' => '0xa1b2c3d4e5f60718293a4b5c6d7e8f9012345678',
    ]);
    $doctor = Doctor::create([
        'doctor_code' => 'DOC001',
        'user_id' => $doctorUser->id,
        'full_name' => 'dr. Uji',
        'specialization' => 'Umum',
        'str_number' => 'STR-999',
        'is_registered_onchain' => false,
    ]);

    $patientUser = User::factory()->pasien()->create([
        'wallet_address' => '0xb2c3d4e5f60718293a4b5c6d7e8f901234567890',
    ]);
    $patient = Patient::create([
        'patient_code' => 'PAT001',
        'user_id' => $patientUser->id,
        'full_name' => 'Pasien Uji',
        'date_of_birth' => '1990-02-10',
        'gender' => Patient::GENDER_MALE,
        'address' => 'Jl. Mawar No. 1',
        'is_registered_onchain' => false,
    ]);

    $this->actingAs($admin)
        ->postJson(route('admin.doctors.onchain-register', $doctor), [
            'registration_tx_hash' => '0xinvalid',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('registration_tx_hash');

    $this->actingAs($admin)
        ->postJson(route('admin.doctors.onchain-deactivate', $doctor), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('tx_hash');

    $this->actingAs($admin)
        ->postJson(route('admin.patients.onchain-register', $patient), [
            'registration_tx_hash' => '0xinvalid',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('registration_tx_hash');

    $this->actingAs($admin)
        ->postJson(route('admin.patients.onchain-deactivate', $patient), [
            'tx_hash' => 'not-a-hash',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('tx_hash');

    $fakeTx = '0x'.str_repeat('f', 64);
    Http::fake([
        'https://eth-sepolia.example.test/v2/test-key' => Http::sequence()
            ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => null])
            ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => adminFakeEventReceipt(
                $fakeTx,
                $admin->wallet_address,
                '0x053a4badf166f01c4b02f6bcb71d2d67734470d0866282e291e73001aa25c769',
                $patientUser->wallet_address,
                '0x0',
            )]),
    ]);

    $this->actingAs($admin)
        ->postJson(route('admin.doctors.onchain-register', $doctor), [
            'registration_tx_hash' => $fakeTx,
        ])
        ->assertUnprocessable()
        ->assertJsonStructure(['message']);

    $patient->update(['is_registered_onchain' => true, 'registration_tx_hash' => '0x'.str_repeat('1', 64)]);

    $this->actingAs($admin)
        ->postJson(route('admin.patients.onchain-deactivate', $patient), [
            'tx_hash' => $fakeTx,
        ])
        ->assertUnprocessable()
        ->assertJsonStructure(['message']);

    expect($doctor->fresh()->is_registered_onchain)->toBeFalse()
        ->and($patient->fresh()->is_registered_onchain)->toBeTrue();
});

it('blocks changing wallet or deleting an active on-chain doctor and patient with a warning while allowing non-wallet edits', function () {
    $admin = makeAdminUser();

    $doctorWallet = '0xa1b2c3d4e5f60718293a4b5c6d7e8f9012345678';
    $doctorUser = User::factory()->dokter()->create([
        'username' => 'dr_aktif',
        'wallet_address' => $doctorWallet,
    ]);
    $doctor = Doctor::create([
        'doctor_code' => 'DOC001',
        'user_id' => $doctorUser->id,
        'full_name' => 'dr. Aktif',
        'specialization' => 'Umum',
        'str_number' => 'STR-01',
        'is_registered_onchain' => true,
        'registration_tx_hash' => '0x'.str_repeat('1', 64),
    ]);

    $patientWallet = '0xb2c3d4e5f60718293a4b5c6d7e8f901234567890';
    $patientUser = User::factory()->pasien()->create([
        'username' => 'pasien_aktif',
        'wallet_address' => $patientWallet,
    ]);
    $patient = Patient::create([
        'patient_code' => 'PAT001',
        'user_id' => $patientUser->id,
        'full_name' => 'Pasien Aktif',
        'date_of_birth' => '1994-05-12',
        'gender' => Patient::GENDER_MALE,
        'address' => 'Jl. Melati No. 1',
        'is_registered_onchain' => true,
        'registration_tx_hash' => '0x'.str_repeat('2', 64),
    ]);

    // Blocked wallet edit on active on-chain doctor
    $this->actingAs($admin)
        ->put(route('admin.doctors.update', $doctor), [
            'doctor_code' => 'DOC001',
            'full_name' => 'dr. Aktif',
            'specialization' => 'Umum',
            'str_number' => 'STR-01',
            'username' => 'dr_aktif',
            'wallet_address' => '0x9999999999999999999999999999999999999999',
        ])
        ->assertRedirect(route('admin.doctors.index'))
        ->assertSessionHas('warning')
        ->assertSessionHasErrors('wallet_address');

    expect($doctor->fresh()->user->wallet_address)->toBe($doctorWallet);

    // Allowed non-wallet edit on active on-chain doctor
    $this->actingAs($admin)
        ->put(route('admin.doctors.update', $doctor), [
            'doctor_code' => 'DOC001',
            'full_name' => 'dr. Aktif Diperbarui',
            'specialization' => 'Penyakit Dalam',
            'str_number' => 'STR-01',
            'username' => 'dr_aktif',
            'wallet_address' => $doctorWallet,
        ])
        ->assertRedirect(route('admin.doctors.index'))
        ->assertSessionHas('status');

    expect($doctor->fresh()->full_name)->toBe('dr. Aktif Diperbarui')
        ->and($doctor->fresh()->is_registered_onchain)->toBeTrue();

    // Blocked deletion on active on-chain doctor
    $this->actingAs($admin)
        ->delete(route('admin.doctors.destroy', $doctor))
        ->assertRedirect(route('admin.doctors.index'))
        ->assertSessionHas('warning');

    $this->assertDatabaseHas('doctors', ['id' => $doctor->id]);

    // Blocked wallet edit and deletion on active on-chain patient
    $this->actingAs($admin)
        ->put(route('admin.patients.update', $patient), [
            'patient_code' => 'PAT001',
            'full_name' => 'Pasien Aktif',
            'date_of_birth' => '1994-05-12',
            'gender' => Patient::GENDER_MALE,
            'address' => 'Jl. Melati No. 1',
            'username' => 'pasien_aktif',
            'wallet_address' => '0x8888888888888888888888888888888888888888',
        ])
        ->assertRedirect(route('admin.patients.index'))
        ->assertSessionHas('warning')
        ->assertSessionHasErrors('wallet_address');

    // Allowed non-wallet edit on active on-chain patient
    $this->actingAs($admin)
        ->put(route('admin.patients.update', $patient), [
            'patient_code' => 'PAT001',
            'full_name' => 'Pasien Aktif Diperbarui',
            'date_of_birth' => '1994-05-12',
            'gender' => Patient::GENDER_MALE,
            'address' => 'Jl. Melati No. 2',
            'username' => 'pasien_aktif',
            'wallet_address' => $patientWallet,
        ])
        ->assertRedirect(route('admin.patients.index'))
        ->assertSessionHas('status');

    expect($patient->fresh()->full_name)->toBe('Pasien Aktif Diperbarui')
        ->and($patient->fresh()->is_registered_onchain)->toBeTrue();

    $this->actingAs($admin)
        ->delete(route('admin.patients.destroy', $patient))
        ->assertRedirect(route('admin.patients.index'))
        ->assertSessionHas('warning');

    $this->assertDatabaseHas('patients', ['id' => $patient->id]);
});

it('creates, updates, syncs on-chain status with authorization cascade, and blocks deletion when records exist for a patient', function () {
    Http::preventStrayRequests();

    $admin = makeAdminUser();
    $patientWallet = '0xb2c3d4e5f60718293a4b5c6d7e8f901234567890';

    $createResponse = $this->actingAs($admin)->post(route('admin.patients.store'), [
        'full_name' => 'Siti Aminah',
        'date_of_birth' => '2001-09-22',
        'gender' => Patient::GENDER_FEMALE,
        'address' => 'Jl. Sudirman No. 45, Bandung',
        'username' => 'siti_aminah',
        'email' => '',
        'password' => 'Password123!',
        'wallet_address' => strtoupper($patientWallet),
    ]);

    $createResponse->assertRedirect(route('admin.patients.index'))
        ->assertSessionHas('status', fn (string $status): bool => str_contains($status, 'blockchain Sepolia'));

    $patient = Patient::query()->with('user')->where('full_name', 'Siti Aminah')->firstOrFail();

    expect($patient->patient_code)->toBe('PAT001')
        ->and($patient->is_registered_onchain)->toBeFalse()
        ->and($patient->registration_tx_hash)->toBeNull()
        ->and($patient->user->wallet_address)->toBe($patientWallet);

    $registerTx = '0x'.str_repeat('D', 64);
    $deactivateTx = '0x'.str_repeat('E', 64);
    $reRegisterTx = '0x'.str_repeat('F', 64);
    $reDeactivateTx = '0x'.str_repeat('9', 64);

    Http::fake([
        'https://eth-sepolia.example.test/v2/test-key' => Http::sequence()
            ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => adminFakeEventReceipt(
                $registerTx,
                $admin->wallet_address,
                '0xa58cff07db0adda3ab7a40bd2f18ab57210bf38391a52ffd2e8eee6b8bdec71a',
                $patientWallet,
            )])
            ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x'.str_repeat('0', 63).'1'])
            ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => adminFakeEventReceipt(
                $deactivateTx,
                $admin->wallet_address,
                '0x053a4badf166f01c4b02f6bcb71d2d67734470d0866282e291e73001aa25c769',
                $patientWallet,
            )])
            ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x'.str_repeat('0', 64)])
            ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => adminFakeEventReceipt(
                $reRegisterTx,
                $admin->wallet_address,
                '0xa58cff07db0adda3ab7a40bd2f18ab57210bf38391a52ffd2e8eee6b8bdec71a',
                $patientWallet,
            )])
            ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x'.str_repeat('0', 63).'1'])
            ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => adminFakeEventReceipt(
                $reDeactivateTx,
                $admin->wallet_address,
                '0x053a4badf166f01c4b02f6bcb71d2d67734470d0866282e291e73001aa25c769',
                $patientWallet,
            )])
            ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x'.str_repeat('0', 64)]),
    ]);

    $this->actingAs($admin)
        ->postJson(route('admin.patients.onchain-register', $patient), [
            'registration_tx_hash' => $registerTx,
        ])
        ->assertOk()
        ->assertJsonPath('patient.is_registered_onchain', true);

    $doctorUser = User::factory()->dokter()->create();
    $doctor = Doctor::create([
        'doctor_code' => 'DOC001',
        'user_id' => $doctorUser->id,
        'full_name' => 'dr. Budi',
        'specialization' => 'Umum',
        'str_number' => 'STR-01',
        'is_registered_onchain' => true,
        'registration_tx_hash' => '0x'.str_repeat('1', 64),
    ]);
    $auth = Authorization::create([
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'is_authorized' => true,
        'tx_hash' => '0x'.str_repeat('2', 64),
    ]);

    $this->actingAs($admin)
        ->postJson(route('admin.patients.onchain-deactivate', $patient), [
            'tx_hash' => $deactivateTx,
        ])
        ->assertOk()
        ->assertJsonPath('patient.is_registered_onchain', false);

    $patient->refresh();
    $auth->refresh();
    expect($patient->is_registered_onchain)->toBeFalse()
        ->and($auth->is_authorized)->toBeFalse()
        ->and($auth->tx_hash)->toBeNull();

    // Re-register then deactivate before updating wallet
    $this->actingAs($admin)
        ->postJson(route('admin.patients.onchain-register', $patient), [
            'tx_hash' => $reRegisterTx,
        ])
        ->assertOk();

    $this->actingAs($admin)
        ->postJson(route('admin.patients.onchain-deactivate', $patient), [
            'tx_hash' => $reDeactivateTx,
        ])
        ->assertOk();

    $this->actingAs($admin)->put(route('admin.patients.update', $patient), [
        'patient_code' => 'PAT001',
        'full_name' => 'Siti Aminah Updated',
        'date_of_birth' => '2001-09-22',
        'gender' => Patient::GENDER_FEMALE,
        'address' => 'Jl. Asia Afrika No. 1',
        'username' => 'siti_aminah',
        'wallet_address' => '0x99C3D4E5F60718293A4B5C6D7E8F901234567890',
    ])->assertRedirect(route('admin.patients.index'));

    $patient->refresh();
    expect($patient->full_name)->toBe('Siti Aminah Updated')
        ->and($patient->user->wallet_address)->toBe('0x99c3d4e5f60718293a4b5c6d7e8f901234567890')
        ->and($patient->is_registered_onchain)->toBeFalse()
        ->and($patient->registration_tx_hash)->toBeNull();

    MedicalRecord::create([
        'record_code' => 'MR001',
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'record_date' => '2026-11-26',
        'complaint' => 'Batuk',
        'diagnosis' => 'ISPA',
        'treatment' => 'Vitamin C',
        'current_hash' => '0x'.str_repeat('a', 64),
    ]);

    $this->actingAs($admin)
        ->delete(route('admin.patients.destroy', $patient))
        ->assertRedirect(route('admin.patients.index'))
        ->assertSessionHas('error');

    $this->assertDatabaseHas('patients', ['id' => $patient->id]);
});

it('rejects registering the smart contract admin wallet address as a doctor or patient', function () {
    $admin = makeAdminUser();
    $adminWallet = '0x42B01A713EA7A8ECA09D36A9D8FA177AC301E1C3';

    $this->actingAs($admin)
        ->from(route('admin.doctors.index'))
        ->post(route('admin.doctors.store'), [
            'full_name' => 'dr. Admin Palsu',
            'specialization' => 'Umum',
            'str_number' => 'STR-ADMIN-01',
            'username' => 'dr_admin_palsu',
            'password' => 'Password123!',
            'wallet_address' => $adminWallet,
        ])
        ->assertRedirect(route('admin.doctors.index'))
        ->assertSessionHasErrors('wallet_address');

    $this->actingAs($admin)
        ->from(route('admin.patients.index'))
        ->post(route('admin.patients.store'), [
            'full_name' => 'Pasien Admin Palsu',
            'date_of_birth' => '1995-01-01',
            'gender' => Patient::GENDER_MALE,
            'address' => 'Jl. Raya No. 1',
            'username' => 'pasien_admin_palsu',
            'password' => 'Password123!',
            'wallet_address' => $adminWallet,
        ])
        ->assertRedirect(route('admin.patients.index'))
        ->assertSessionHasErrors('wallet_address');

    // Also test update path when admin user happens to have a different wallet in DB than config admin_wallet_address
    Config::set('blockchain.admin_wallet_address', '0x7777777777777777777777777777777777777777');

    $doctorUser = User::factory()->dokter()->create();
    $doctor = Doctor::create([
        'doctor_code' => 'DOC009',
        'user_id' => $doctorUser->id,
        'full_name' => 'dr. Uji Update',
        'specialization' => 'Umum',
        'str_number' => 'STR-UPD-09',
    ]);

    $patientUser = User::factory()->pasien()->create();
    $patient = Patient::create([
        'patient_code' => 'PAT009',
        'user_id' => $patientUser->id,
        'full_name' => 'Pasien Uji Update',
        'date_of_birth' => '1992-01-01',
        'gender' => Patient::GENDER_FEMALE,
        'address' => 'Jl. Uji No. 9',
    ]);

    $this->actingAs($admin)
        ->from(route('admin.doctors.index'))
        ->put(route('admin.doctors.update', $doctor), [
            'doctor_code' => 'DOC009',
            'full_name' => 'dr. Uji Update',
            'specialization' => 'Umum',
            'str_number' => 'STR-UPD-09',
            'username' => $doctorUser->username,
            'wallet_address' => '0x7777777777777777777777777777777777777777',
        ])
        ->assertRedirect(route('admin.doctors.index'))
        ->assertSessionHasErrors('wallet_address');

    $this->actingAs($admin)
        ->from(route('admin.patients.index'))
        ->put(route('admin.patients.update', $patient), [
            'patient_code' => 'PAT009',
            'full_name' => 'Pasien Uji Update',
            'date_of_birth' => '1992-01-01',
            'gender' => Patient::GENDER_FEMALE,
            'address' => 'Jl. Uji No. 9',
            'username' => $patientUser->username,
            'wallet_address' => '0x7777777777777777777777777777777777777777',
        ])
        ->assertRedirect(route('admin.patients.index'))
        ->assertSessionHasErrors('wallet_address');
});
