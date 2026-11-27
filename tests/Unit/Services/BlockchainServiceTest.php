<?php

use App\Services\BlockchainService;

it('encodes MR001 into the exact right-padded bytes32 hex vector', function () {
    $encoded = BlockchainService::encodeRecordId('MR001');

    expect($encoded)
        ->toBe('0x4d52303031000000000000000000000000000000000000000000000000000000')
        ->toHaveLength(66)
        ->toMatch('/^0x[a-f0-9]{64}$/');
});

it('decodes right-padded bytes32 hex strings back to the original record code', function (string $bytes32Hex, string $expected) {
    $decoded = BlockchainService::decodeRecordId($bytes32Hex);

    expect($decoded)->toBe($expected);
})->with([
    'MR001 lowercase' => ['0x4d52303031000000000000000000000000000000000000000000000000000000', 'MR001'],
    'MR001 uppercase prefix and hex' => ['0X4D52303031000000000000000000000000000000000000000000000000000000', 'MR001'],
    'max 31 ascii characters' => [BlockchainService::encodeRecordId(str_repeat('A', 31)), str_repeat('A', 31)],
]);

it('rejects invalid record codes for bytes32 encoding', function (string $invalidRecordCode) {
    expect(fn () => BlockchainService::encodeRecordId($invalidRecordCode))
        ->toThrow(InvalidArgumentException::class, 'record_code harus 1-31 karakter ASCII.');
})->with([
    'empty string' => [''],
    '32 ascii characters' => [str_repeat('A', 32)],
    'embedded null byte' => ["MR\x00".'01'],
    'non-ascii utf8 character' => ['MR-é01'],
]);

it('rejects malformed bytes32 hex values when decoding record codes', function (string $invalidBytes32) {
    expect(fn () => BlockchainService::decodeRecordId($invalidBytes32))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'too short' => ['0x4d52303031'],
    'non-hex characters' => ['0x'.str_repeat('z', 64)],
    'missing bytes' => [''],
]);

it('normalizes mixed-case addresses and bytes32 hex strings to lowercase', function () {
    $address = BlockchainService::normalizeAddress('  0x6d1c13645e2B1CA4F4DFB6a4BBD22daA8072f4a4  ');
    $bytes32 = BlockchainService::normalizeBytes32('  0x'.str_repeat('A1B2C3D4', 8).'  ');

    expect($address)->toBe('0x6d1c13645e2b1ca4f4dfb6a4bbd22daa8072f4a4');
    expect($bytes32)->toBe('0x'.str_repeat('a1b2c3d4', 8));
});

it('rejects invalid ethereum addresses and bytes32 hashes', function () {
    expect(fn () => BlockchainService::normalizeAddress('0x1234'))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => BlockchainService::normalizeBytes32('0x'.str_repeat('a', 63)))
        ->toThrow(InvalidArgumentException::class);
});

it('produces a deterministic lowercase canonical SHA-256 hash using domain codes across whitespace and null-note variations', function () {
    $service = new BlockchainService;

    $basePayload = [
        'record_code' => 'MR001',
        'patient_code' => 'PAT001',
        'doctor_code' => 'DOC001',
        'record_date' => '2026-11-26',
        'complaint' => 'Demam tinggi',
        'diagnosis' => 'Infeksi virus',
        'treatment' => 'Paracetamol',
        'notes' => null,
    ];

    $equivalentPayload = [
        'treatment' => "  Paracetamol  \r\n",
        'notes' => '   ',
        'diagnosis' => 'Infeksi virus',
        'complaint' => "Demam tinggi\r\n",
        'record_date' => '2026-11-26',
        'doctor_code' => ' DOC001 ',
        'patient_code' => ' PAT001 ',
        'record_code' => ' MR001 ',
    ];

    $canonicalJson = $service->canonicalizeMedicalRecord($basePayload);
    $hashFromBase = $service->hashMedicalRecord($basePayload);
    $hashFromEquivalent = $service->hashMedicalRecord($equivalentPayload);

    expect($canonicalJson)
        ->toBe('{"complaint":"Demam tinggi","diagnosis":"Infeksi virus","doctor_code":"DOC001","notes":null,"patient_code":"PAT001","record_code":"MR001","record_date":"2026-11-26","treatment":"Paracetamol"}');
    expect($hashFromBase)
        ->toBe('0x042f0ec47b830fb0865b11c660753704eed7d836350205409ec19cee287d41fd')
        ->toMatch('/^0x[a-f0-9]{64}$/')
        ->toBe($hashFromEquivalent);
});

it('requires patient_code and doctor_code when building canonical payload', function () {
    $service = new BlockchainService;

    expect(fn () => $service->buildCanonicalPayload([
        'record_code' => 'MR001',
        'patient_code' => '',
        'doctor_code' => 'DOC001',
        'record_date' => '2026-11-26',
        'complaint' => 'Demam tinggi',
        'diagnosis' => 'Infeksi virus',
        'treatment' => 'Paracetamol',
    ]))->toThrow(InvalidArgumentException::class, 'patient_code dan doctor_code wajib diisi');
});

it('changes the canonical SHA-256 hash whenever any medical record field changes', function () {
    $service = new BlockchainService;

    $original = [
        'record_code' => 'MR001',
        'patient_code' => 'PAT001',
        'doctor_code' => 'DOC001',
        'record_date' => '2026-11-26',
        'complaint' => 'Demam tinggi',
        'diagnosis' => 'Infeksi virus',
        'treatment' => 'Paracetamol',
        'notes' => 'Kontrol 3 hari',
    ];

    $updated = [...$original, 'treatment' => 'Paracetamol 500mg'];

    expect($service->hashMedicalRecord($updated))
        ->not->toBe($service->hashMedicalRecord($original))
        ->toMatch('/^0x[a-f0-9]{64}$/');
});
