<?php

use App\Models\Doctor;
use App\Models\LogBlockchain;
use App\Models\MedicalRecord;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeAdminUser(): User
{
    return User::factory()->admin()->create([
        'username' => 'admin_utama',
        'wallet_address' => '0x42b01a713ea7a8eca09d36a9d8fa177ac301e1c3',
    ]);
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
        ->assertSee('Total Dokter')
        ->assertSee('Dokter Terdaftar On-Chain')
        ->assertSee('MR001')
        ->assertSee('CREATE')
        ->assertSee("https://sepolia.etherscan.io/tx/{$txHash}");
});

it('creates, updates, syncs on-chain status, and deletes a doctor cleanly', function () {
    $admin = makeAdminUser();

    $createResponse = $this->actingAs($admin)->post(route('admin.doctors.store'), [
        'full_name' => 'dr. Hendra <script>alert(1)</script>',
        'specialization' => 'Penyakit Dalam',
        'str_number' => 'STR-2026-0100',
        'username' => 'dr_hendra',
        'email' => 'hendra@klinik.id',
        'password' => 'Password123!',
        'wallet_address' => '0xA1B2C3D4E5F60718293A4B5C6D7E8F9012345678',
    ]);

    $createResponse->assertRedirect(route('admin.doctors.index'));

    $doctor = Doctor::query()->with('user')->where('str_number', 'STR-2026-0100')->firstOrFail();

    expect($doctor->doctor_code)->toBe('DOC001')
        ->and($doctor->is_registered_onchain)->toBeFalse()
        ->and($doctor->user->role)->toBe(User::ROLE_DOKTER)
        ->and($doctor->user->wallet_address)->toBe('0xa1b2c3d4e5f60718293a4b5c6d7e8f9012345678');

    $this->actingAs($admin)
        ->get(route('admin.doctors.index'))
        ->assertOk()
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
        ->assertDontSee('<script>alert(1)</script>', false);

    $registerTx = '0x'.str_repeat('C', 64);
    $this->actingAs($admin)
        ->postJson(route('admin.doctors.onchain-register', $doctor), [
            'registration_tx_hash' => $registerTx,
        ])
        ->assertOk()
        ->assertJsonPath('doctor.is_registered_onchain', true);

    $doctor->refresh();
    expect($doctor->is_registered_onchain)->toBeTrue()
        ->and($doctor->registration_tx_hash)->toBe(strtolower($registerTx));

    $this->actingAs($admin)
        ->postJson(route('admin.doctors.onchain-deactivate', $doctor))
        ->assertOk()
        ->assertJsonPath('doctor.is_registered_onchain', false);

    $doctor->refresh();
    expect($doctor->is_registered_onchain)->toBeFalse()
        ->and($doctor->registration_tx_hash)->toBe(strtolower($registerTx));

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

it('rejects invalid transaction hash when registering a doctor on-chain', function () {
    $admin = makeAdminUser();
    $doctorUser = User::factory()->dokter()->create();
    $doctor = Doctor::create([
        'doctor_code' => 'DOC001',
        'user_id' => $doctorUser->id,
        'full_name' => 'dr. Uji',
        'specialization' => 'Umum',
        'str_number' => 'STR-999',
    ]);

    $this->actingAs($admin)
        ->postJson(route('admin.doctors.onchain-register', $doctor), [
            'registration_tx_hash' => '0xinvalid',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('registration_tx_hash');
});

it('creates, updates, and deletes a patient while blocking deletion when records exist', function () {
    $admin = makeAdminUser();

    $this->actingAs($admin)->post(route('admin.patients.store'), [
        'full_name' => 'Siti Aminah',
        'date_of_birth' => '2001-09-22',
        'gender' => Patient::GENDER_FEMALE,
        'address' => 'Jl. Sudirman No. 45, Bandung',
        'username' => 'siti_aminah',
        'email' => '',
        'password' => 'Password123!',
        'wallet_address' => '0xB2C3D4E5F60718293A4B5C6D7E8F901234567890',
    ])->assertRedirect(route('admin.patients.index'));

    $patient = Patient::query()->with('user')->where('full_name', 'Siti Aminah')->firstOrFail();

    expect($patient->patient_code)->toBe('PAT001')
        ->and($patient->user->wallet_address)->toBe('0xb2c3d4e5f60718293a4b5c6d7e8f901234567890');

    $this->actingAs($admin)
        ->get(route('admin.patients.index'))
        ->assertOk()
        ->assertSee('siti_aminah')
        ->assertSee('0xb2c3d4e5f60718293a4b5c6d7e8f901234567890');

    $this->actingAs($admin)->put(route('admin.patients.update', $patient), [
        'patient_code' => 'PAT001',
        'full_name' => 'Siti Aminah Updated',
        'date_of_birth' => '2001-09-22',
        'gender' => Patient::GENDER_FEMALE,
        'address' => 'Jl. Asia Afrika No. 1',
        'username' => 'siti_aminah',
        'wallet_address' => '0xb2c3d4e5f60718293a4b5c6d7e8f901234567890',
    ])->assertRedirect(route('admin.patients.index'));

    expect($patient->fresh()->full_name)->toBe('Siti Aminah Updated');

    $doctorUser = User::factory()->dokter()->create();
    $doctor = Doctor::create([
        'doctor_code' => 'DOC001',
        'user_id' => $doctorUser->id,
        'full_name' => 'dr. Budi',
        'specialization' => 'Umum',
        'str_number' => 'STR-01',
    ]);
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
