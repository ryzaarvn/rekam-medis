<?php

namespace App\Services;

use App\Exceptions\BlockchainException;
use App\Models\MedicalRecord;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use InvalidArgumentException;
use Throwable;

class BlockchainService
{
    /**
     * ABI 4-byte selectors verified against resources/js/abi/HealthcareRecord.json.
     */
    private const SELECTOR_VERIFY_MEDICAL_RECORD = '0x43e73fcd';

    private const SELECTOR_GET_MEDICAL_RECORD_HASH = '0x2f57b23a';

    private const SELECTOR_GET_MEDICAL_RECORD = '0x512404e5';

    private const SELECTOR_HAS_ACCESS = '0x1709ef07';

    private const SELECTOR_IS_DOCTOR_REGISTERED = '0x78550390';

    private const SELECTOR_IS_PATIENT_REGISTERED = '0x80b6b1b8';

    private const SELECTOR_ERROR_STRING = '08c379a0';

    /**
     * Keccak-256 topic0 event signatures verified via ethers.id() against resources/js/abi/HealthcareRecord.json.
     */
    private const TOPIC_DOCTOR_ADDED = '0x69908b2e63170aa89ef89719bbe3b4c92cccc38653968ab25f4464eff7c28ac8';

    private const TOPIC_DOCTOR_REMOVED = '0x493d7cf6a7c880406a55654d6727464ea088bd01d40498e1987a327e68961ab8';

    private const TOPIC_PATIENT_ADDED = '0xa58cff07db0adda3ab7a40bd2f18ab57210bf38391a52ffd2e8eee6b8bdec71a';

    private const TOPIC_PATIENT_REMOVED = '0x053a4badf166f01c4b02f6bcb71d2d67734470d0866282e291e73001aa25c769';

    private const TOPIC_ACCESS_GRANTED = '0x9f520b3bfc56d06f7065d10b4683b3f57ac8895d5026cd176dee95ce1454cb8d';

    private const TOPIC_ACCESS_REVOKED = '0x825c8be24eb0df19500f63e86c29e7d0d951e73056b889b891d85e40938d9b6e';

    private const TOPIC_MEDICAL_RECORD_ADDED = '0x30ce74f24973c3793a1a44ecce360de22f3a981e9e7a4b1aed68c6c3611d54a2';

    private const TOPIC_MEDICAL_RECORD_UPDATED = '0xfdaac535c2479f1b829c9d1d754dd8e4739233afe60a0917d08855ef393fa4d9';

    /**
     * Create a new blockchain service instance.
     */
    public function __construct(
        private ?string $rpcUrl = null,
        private ?string $contractAddress = null,
        private ?int $chainId = null,
        private ?int $rpcConnectTimeout = null,
        private ?int $rpcTimeout = null,
        private ?int $receiptMaxAttempts = null,
        private ?int $receiptRetryDelayMs = null,
    ) {}

    /**
     * Encode a short ASCII record code into right-padded bytes32 hex matching ethers.encodeBytes32String.
     */
    public static function encodeRecordId(string $recordCode): string
    {
        if (preg_match('/\A[\x01-\x7F]{1,31}\z/', $recordCode) !== 1) {
            throw new InvalidArgumentException('record_code harus 1-31 karakter ASCII.');
        }

        return '0x'.str_pad(bin2hex($recordCode), 64, '0', STR_PAD_RIGHT);
    }

    /**
     * Decode a right-padded bytes32 hex string back to the original ASCII record code.
     */
    public static function decodeRecordId(string $bytes32Hex): string
    {
        $hex = preg_replace('/^0x/i', '', trim($bytes32Hex)) ?? '';

        if (preg_match('/\A[a-f0-9]{64}\z/i', $hex) !== 1) {
            throw new InvalidArgumentException('Format bytes32 hex tidak valid (harus 0x + 64 karakter hex).');
        }

        $binary = hex2bin($hex);

        if ($binary === false) {
            throw new InvalidArgumentException('Gagal mengonversi bytes32 hex menjadi string.');
        }

        return rtrim($binary, "\0");
    }

    /**
     * Validate and normalize an Ethereum address to lowercase (0x + 40 hex characters).
     */
    public static function normalizeAddress(string $address): string
    {
        $normalized = strtolower(trim($address));

        if (preg_match('/\A0x[a-f0-9]{40}\z/', $normalized) !== 1) {
            throw new InvalidArgumentException('Format wallet address tidak valid (harus 0x + 40 karakter hex).');
        }

        return $normalized;
    }

    /**
     * Validate and normalize a 32-byte hex string (hash / bytes32 / tx_hash) to lowercase.
     */
    public static function normalizeBytes32(string $bytes32Hex): string
    {
        $normalized = strtolower(trim($bytes32Hex));

        if (preg_match('/\A0x[a-f0-9]{64}\z/', $normalized) !== 1) {
            throw new InvalidArgumentException('Format bytes32 hex tidak valid (harus 0x + 64 karakter hex).');
        }

        return $normalized;
    }

    /**
     * Build the deterministic canonical payload array for a medical record using stable domain codes.
     *
     * @param  MedicalRecord|array<string, mixed>  $record
     * @return array{
     *     complaint: string,
     *     diagnosis: string,
     *     doctor_code: string,
     *     notes: string|null,
     *     patient_code: string,
     *     record_code: string,
     *     record_date: string,
     *     treatment: string
     * }
     */
    public function buildCanonicalPayload(MedicalRecord|array $record): array
    {
        if ($record instanceof MedicalRecord) {
            $record->loadMissing(['patient', 'doctor']);

            $data = [
                'record_code' => $record->record_code,
                'patient_code' => $record->patient?->patient_code,
                'doctor_code' => $record->doctor?->doctor_code,
                'record_date' => $record->record_date,
                'complaint' => $record->complaint,
                'diagnosis' => $record->diagnosis,
                'treatment' => $record->treatment,
                'notes' => $record->notes,
            ];
        } else {
            $data = $record;
        }

        $recordCode = trim((string) ($data['record_code'] ?? ''));

        if ($recordCode === '') {
            throw new InvalidArgumentException('record_code wajib diisi untuk canonical hash.');
        }

        // Validate compatibility with bytes32 encoding before generating off-chain hash.
        self::encodeRecordId($recordCode);

        $patientCode = trim((string) ($data['patient_code'] ?? ''));
        $doctorCode = trim((string) ($data['doctor_code'] ?? ''));

        if ($patientCode === '' || $doctorCode === '') {
            throw new InvalidArgumentException('patient_code dan doctor_code wajib diisi untuk canonical hash.');
        }

        $rawDate = $data['record_date'] ?? null;

        if ($rawDate instanceof DateTimeInterface) {
            $recordDate = $rawDate->format('Y-m-d');
        } elseif (is_string($rawDate) && trim($rawDate) !== '') {
            $recordDate = CarbonImmutable::parse(trim($rawDate))->toDateString();
        } else {
            throw new InvalidArgumentException('record_date wajib diisi untuk canonical hash.');
        }

        $rawNotes = $data['notes'] ?? null;
        $notes = ($rawNotes === null || trim((string) $rawNotes) === '')
            ? null
            : $this->normalizeText((string) $rawNotes);

        $payload = [
            'complaint' => $this->normalizeText((string) ($data['complaint'] ?? '')),
            'diagnosis' => $this->normalizeText((string) ($data['diagnosis'] ?? '')),
            'doctor_code' => $doctorCode,
            'notes' => $notes,
            'patient_code' => $patientCode,
            'record_code' => $recordCode,
            'record_date' => $recordDate,
            'treatment' => $this->normalizeText((string) ($data['treatment'] ?? '')),
        ];

        ksort($payload);

        return $payload;
    }

    /**
     * Serialize the canonical medical record payload into a stable JSON string.
     *
     * @param  MedicalRecord|array<string, mixed>  $record
     */
    public function canonicalizeMedicalRecord(MedicalRecord|array $record): string
    {
        return json_encode(
            $this->buildCanonicalPayload($record),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * Compute the canonical SHA-256 hash in lowercase 0x-prefixed 66-character format.
     *
     * @param  MedicalRecord|array<string, mixed>  $record
     */
    public function hashMedicalRecord(MedicalRecord|array $record): string
    {
        $canonicalJson = $this->canonicalizeMedicalRecord($record);

        return '0x'.strtolower(hash('sha256', $canonicalJson));
    }

    /**
     * Call verifyMedicalRecord(bytes32 _recordId, bytes32 _offchainHash) via Sepolia JSON-RPC.
     */
    public function verifyMedicalRecord(string $recordCode, string $offchainHash): bool
    {
        $callData = self::SELECTOR_VERIFY_MEDICAL_RECORD
            .$this->stripHexPrefix(self::encodeRecordId($recordCode))
            .$this->stripHexPrefix(self::normalizeBytes32($offchainHash));

        $resultHex = $this->ethCall('verifyMedicalRecord', $callData);

        return $this->decodeBool($resultHex, 'verifyMedicalRecord');
    }

    /**
     * Call getMedicalRecordHash(bytes32 _recordId) via Sepolia JSON-RPC.
     */
    public function getMedicalRecordHash(string $recordCode): string
    {
        $callData = self::SELECTOR_GET_MEDICAL_RECORD_HASH
            .$this->stripHexPrefix(self::encodeRecordId($recordCode));

        $resultHex = $this->ethCall('getMedicalRecordHash', $callData);

        return $this->decodeBytes32($resultHex, 'getMedicalRecordHash');
    }

    /**
     * Call getMedicalRecord(bytes32 _recordId) via Sepolia JSON-RPC.
     *
     * @return array{
     *     record_hash: string,
     *     patient: string,
     *     doctor: string,
     *     timestamp: int
     * }
     */
    public function getMedicalRecord(string $recordCode): array
    {
        $callData = self::SELECTOR_GET_MEDICAL_RECORD
            .$this->stripHexPrefix(self::encodeRecordId($recordCode));

        $resultHex = $this->ethCall('getMedicalRecord', $callData);
        $hex = $this->extractAbiBodyHex($resultHex, 4, 'getMedicalRecord');

        return [
            'record_hash' => '0x'.substr($hex, 0, 64),
            'patient' => '0x'.substr($hex, 64 + 24, 40),
            'doctor' => '0x'.substr($hex, 128 + 24, 40),
            'timestamp' => $this->decodeUint256ToInt(substr($hex, 192, 64), 'getMedicalRecord'),
        ];
    }

    /**
     * Call hasAccess(address _patient, address _doctor) via Sepolia JSON-RPC.
     */
    public function hasAccess(string $patientAddress, string $doctorAddress): bool
    {
        $callData = self::SELECTOR_HAS_ACCESS
            .$this->encodeAddressWord($patientAddress)
            .$this->encodeAddressWord($doctorAddress);

        $resultHex = $this->ethCall('hasAccess', $callData);

        return $this->decodeBool($resultHex, 'hasAccess');
    }

    /**
     * Call isDoctorRegistered(address _doctor) via Sepolia JSON-RPC.
     */
    public function isDoctorRegistered(string $doctorAddress): bool
    {
        $callData = self::SELECTOR_IS_DOCTOR_REGISTERED
            .$this->encodeAddressWord($doctorAddress);

        $resultHex = $this->ethCall('isDoctorRegistered', $callData);

        return $this->decodeBool($resultHex, 'isDoctorRegistered');
    }

    /**
     * Call isPatientRegistered(address _patient) via Sepolia JSON-RPC.
     */
    public function isPatientRegistered(string $patientAddress): bool
    {
        $callData = self::SELECTOR_IS_PATIENT_REGISTERED
            .$this->encodeAddressWord($patientAddress);

        $resultHex = $this->ethCall('isPatientRegistered', $callData);

        return $this->decodeBool($resultHex, 'isPatientRegistered');
    }

    /**
     * Fetch and validate an on-chain transaction receipt from Sepolia JSON-RPC.
     *
     * @return array{
     *     tx_hash: string,
     *     block_number: int,
     *     from: string,
     *     to: string,
     *     status: string,
     *     logs: array<int, mixed>
     * }
     */
    public function getTransactionReceipt(string $txHash, ?string $expectedSenderWallet = null): array
    {
        $normalizedTxHash = self::normalizeBytes32($txHash);
        $contractAddress = $this->resolveContractAddress();
        $normalizedExpectedSender = $expectedSenderWallet !== null
            ? self::normalizeAddress($expectedSenderWallet)
            : null;

        $maxAttempts = $this->resolveReceiptMaxAttempts();
        $retryDelayMs = $this->resolveReceiptRetryDelayMs();
        $receipt = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $receipt = $this->rpcRequest(
                'eth_getTransactionReceipt',
                [$normalizedTxHash],
                'eth_getTransactionReceipt',
            );

            if ($receipt !== null) {
                break;
            }

            if ($attempt < $maxAttempts && $retryDelayMs > 0) {
                Sleep::usleep($retryDelayMs * 1000);
            }
        }

        if ($receipt === null) {
            throw BlockchainException::verificationFailed(
                'eth_getTransactionReceipt',
                "Receipt transaksi {$normalizedTxHash} tidak ditemukan atau belum terkonfirmasi di Sepolia.",
            );
        }

        if (! is_array($receipt)) {
            throw BlockchainException::invalidRpcResult(
                'eth_getTransactionReceipt',
                'Field result receipt transaksi harus berupa objek JSON.',
            );
        }

        $status = strtolower(trim((string) ($receipt['status'] ?? '')));

        if ($status !== '0x1') {
            throw BlockchainException::verificationFailed(
                'eth_getTransactionReceipt',
                "Transaksi Sepolia {$normalizedTxHash} gagal atau reverted di smart contract.",
            );
        }

        $to = is_string($receipt['to'] ?? null) ? strtolower(trim($receipt['to'])) : '';

        if ($to !== $contractAddress) {
            throw BlockchainException::verificationFailed(
                'eth_getTransactionReceipt',
                "Alamat tujuan transaksi ({$to}) tidak cocok dengan smart contract HealthcareRecord ({$contractAddress}).",
            );
        }

        $rawFrom = $receipt['from'] ?? null;

        if (! is_string($rawFrom) || preg_match('/\A0x[a-f0-9]{40}\z/i', trim($rawFrom)) !== 1) {
            throw BlockchainException::invalidRpcResult(
                'eth_getTransactionReceipt',
                'Field from pada receipt transaksi tidak valid.',
            );
        }

        $from = strtolower(trim($rawFrom));

        if ($normalizedExpectedSender !== null && $from !== $normalizedExpectedSender) {
            throw BlockchainException::verificationFailed(
                'eth_getTransactionReceipt',
                "Pengirim transaksi ({$from}) tidak cocok dengan wallet akun yang berwenang ({$normalizedExpectedSender}).",
            );
        }

        if (isset($receipt['transactionHash']) && is_string($receipt['transactionHash'])) {
            $receiptTxHash = strtolower(trim($receipt['transactionHash']));

            if ($receiptTxHash !== $normalizedTxHash) {
                throw BlockchainException::verificationFailed(
                    'eth_getTransactionReceipt',
                    "Transaction hash pada receipt ({$receiptTxHash}) tidak cocok dengan {$normalizedTxHash}.",
                );
            }
        }

        $rawBlockNumber = $receipt['blockNumber'] ?? null;

        if (! is_string($rawBlockNumber) || preg_match('/\A0x[a-f0-9]+\z/i', trim($rawBlockNumber)) !== 1) {
            throw BlockchainException::invalidRpcResult(
                'eth_getTransactionReceipt',
                'Field blockNumber pada receipt transaksi tidak berupa hex yang valid.',
            );
        }

        $blockNumber = $this->decodeHexQuantityToInt(trim($rawBlockNumber), 'eth_getTransactionReceipt');
        $logs = is_array($receipt['logs'] ?? null) ? array_values($receipt['logs']) : [];

        return [
            'tx_hash' => $normalizedTxHash,
            'block_number' => $blockNumber,
            'from' => $from,
            'to' => $to,
            'status' => $status,
            'logs' => $logs,
        ];
    }

    /**
     * Verify an on-chain addDoctor() transaction receipt, DoctorAdded event log, and active doctor state.
     *
     * @return array{tx_hash: string, block_number: int, from: string, to: string}
     */
    public function verifyDoctorAddedTransaction(string $txHash, string $adminWallet, string $doctorWallet): array
    {
        $normalizedDoctor = self::normalizeAddress($doctorWallet);
        $receipt = $this->getTransactionReceipt($txHash, $adminWallet);

        $this->findMatchingContractLog(
            $receipt['logs'],
            [
                0 => self::TOPIC_DOCTOR_ADDED,
                1 => '0x'.$this->encodeAddressWord($normalizedDoctor),
            ],
            'verifyDoctorAddedTransaction',
        );

        if (! $this->isDoctorRegistered($normalizedDoctor)) {
            throw BlockchainException::verificationFailed(
                'verifyDoctorAddedTransaction',
                "Status on-chain dokter ({$normalizedDoctor}) tidak aktif di smart contract Sepolia.",
            );
        }

        return [
            'tx_hash' => $receipt['tx_hash'],
            'block_number' => $receipt['block_number'],
            'from' => $receipt['from'],
            'to' => $receipt['to'],
        ];
    }

    /**
     * Verify an on-chain removeDoctor() transaction receipt, DoctorRemoved event log, and inactive doctor state.
     *
     * @return array{tx_hash: string, block_number: int, from: string, to: string}
     */
    public function verifyDoctorRemovedTransaction(string $txHash, string $adminWallet, string $doctorWallet): array
    {
        $normalizedDoctor = self::normalizeAddress($doctorWallet);
        $receipt = $this->getTransactionReceipt($txHash, $adminWallet);

        $this->findMatchingContractLog(
            $receipt['logs'],
            [
                0 => self::TOPIC_DOCTOR_REMOVED,
                1 => '0x'.$this->encodeAddressWord($normalizedDoctor),
            ],
            'verifyDoctorRemovedTransaction',
        );

        if ($this->isDoctorRegistered($normalizedDoctor)) {
            throw BlockchainException::verificationFailed(
                'verifyDoctorRemovedTransaction',
                "Dokter ({$normalizedDoctor}) masih tercatat aktif di smart contract Sepolia.",
            );
        }

        return [
            'tx_hash' => $receipt['tx_hash'],
            'block_number' => $receipt['block_number'],
            'from' => $receipt['from'],
            'to' => $receipt['to'],
        ];
    }

    /**
     * Verify an on-chain addPatient() transaction receipt, PatientAdded event log, and active patient state.
     *
     * @return array{tx_hash: string, block_number: int, from: string, to: string}
     */
    public function verifyPatientAddedTransaction(string $txHash, string $adminWallet, string $patientWallet): array
    {
        $normalizedPatient = self::normalizeAddress($patientWallet);
        $receipt = $this->getTransactionReceipt($txHash, $adminWallet);

        $this->findMatchingContractLog(
            $receipt['logs'],
            [
                0 => self::TOPIC_PATIENT_ADDED,
                1 => '0x'.$this->encodeAddressWord($normalizedPatient),
            ],
            'verifyPatientAddedTransaction',
        );

        if (! $this->isPatientRegistered($normalizedPatient)) {
            throw BlockchainException::verificationFailed(
                'verifyPatientAddedTransaction',
                "Status on-chain pasien ({$normalizedPatient}) tidak aktif di smart contract Sepolia.",
            );
        }

        return [
            'tx_hash' => $receipt['tx_hash'],
            'block_number' => $receipt['block_number'],
            'from' => $receipt['from'],
            'to' => $receipt['to'],
        ];
    }

    /**
     * Verify an on-chain removePatient() transaction receipt, PatientRemoved event log, and inactive patient state.
     *
     * @return array{tx_hash: string, block_number: int, from: string, to: string}
     */
    public function verifyPatientRemovedTransaction(string $txHash, string $adminWallet, string $patientWallet): array
    {
        $normalizedPatient = self::normalizeAddress($patientWallet);
        $receipt = $this->getTransactionReceipt($txHash, $adminWallet);

        $this->findMatchingContractLog(
            $receipt['logs'],
            [
                0 => self::TOPIC_PATIENT_REMOVED,
                1 => '0x'.$this->encodeAddressWord($normalizedPatient),
            ],
            'verifyPatientRemovedTransaction',
        );

        if ($this->isPatientRegistered($normalizedPatient)) {
            throw BlockchainException::verificationFailed(
                'verifyPatientRemovedTransaction',
                "Pasien ({$normalizedPatient}) masih tercatat aktif di smart contract Sepolia.",
            );
        }

        return [
            'tx_hash' => $receipt['tx_hash'],
            'block_number' => $receipt['block_number'],
            'from' => $receipt['from'],
            'to' => $receipt['to'],
        ];
    }

    /**
     * Verify an on-chain grantAccess() transaction receipt, AccessGranted event log, and active access state.
     *
     * @return array{tx_hash: string, block_number: int, from: string, to: string}
     */
    public function verifyAccessGrantedTransaction(string $txHash, string $patientWallet, string $doctorWallet): array
    {
        $normalizedPatient = self::normalizeAddress($patientWallet);
        $normalizedDoctor = self::normalizeAddress($doctorWallet);
        $receipt = $this->getTransactionReceipt($txHash, $normalizedPatient);

        $this->findMatchingContractLog(
            $receipt['logs'],
            [
                0 => self::TOPIC_ACCESS_GRANTED,
                1 => '0x'.$this->encodeAddressWord($normalizedPatient),
                2 => '0x'.$this->encodeAddressWord($normalizedDoctor),
            ],
            'verifyAccessGrantedTransaction',
        );

        if (! $this->hasAccess($normalizedPatient, $normalizedDoctor)) {
            throw BlockchainException::verificationFailed(
                'verifyAccessGrantedTransaction',
                'Izin akses dokter pada smart contract Sepolia saat ini tidak aktif.',
            );
        }

        return [
            'tx_hash' => $receipt['tx_hash'],
            'block_number' => $receipt['block_number'],
            'from' => $receipt['from'],
            'to' => $receipt['to'],
        ];
    }

    /**
     * Verify an on-chain revokeAccess() transaction receipt, AccessRevoked event log, and inactive access state.
     *
     * @return array{tx_hash: string, block_number: int, from: string, to: string}
     */
    public function verifyAccessRevokedTransaction(string $txHash, string $patientWallet, string $doctorWallet): array
    {
        $normalizedPatient = self::normalizeAddress($patientWallet);
        $normalizedDoctor = self::normalizeAddress($doctorWallet);
        $receipt = $this->getTransactionReceipt($txHash, $normalizedPatient);

        $this->findMatchingContractLog(
            $receipt['logs'],
            [
                0 => self::TOPIC_ACCESS_REVOKED,
                1 => '0x'.$this->encodeAddressWord($normalizedPatient),
                2 => '0x'.$this->encodeAddressWord($normalizedDoctor),
            ],
            'verifyAccessRevokedTransaction',
        );

        if ($this->hasAccess($normalizedPatient, $normalizedDoctor)) {
            throw BlockchainException::verificationFailed(
                'verifyAccessRevokedTransaction',
                'Izin akses dokter pada smart contract Sepolia saat ini masih aktif.',
            );
        }

        return [
            'tx_hash' => $receipt['tx_hash'],
            'block_number' => $receipt['block_number'],
            'from' => $receipt['from'],
            'to' => $receipt['to'],
        ];
    }

    /**
     * Verify an on-chain addMedicalRecord() transaction receipt and MedicalRecordAdded event payload.
     *
     * @return array{tx_hash: string, block_number: int, onchain_hash: string}
     */
    public function verifyMedicalRecordAddedTransaction(
        string $txHash,
        string $doctorWallet,
        string $recordCode,
        string $patientWallet,
        string $expectedRecordHash,
    ): array {
        return $this->verifyMedicalRecordEventTransaction(
            self::TOPIC_MEDICAL_RECORD_ADDED,
            'verifyMedicalRecordAddedTransaction',
            $txHash,
            $doctorWallet,
            $recordCode,
            $patientWallet,
            $expectedRecordHash,
        );
    }

    /**
     * Verify an on-chain updateMedicalRecord() transaction receipt and MedicalRecordUpdated event payload.
     *
     * @return array{tx_hash: string, block_number: int, onchain_hash: string}
     */
    public function verifyMedicalRecordUpdatedTransaction(
        string $txHash,
        string $doctorWallet,
        string $recordCode,
        string $patientWallet,
        string $expectedRecordHash,
    ): array {
        return $this->verifyMedicalRecordEventTransaction(
            self::TOPIC_MEDICAL_RECORD_UPDATED,
            'verifyMedicalRecordUpdatedTransaction',
            $txHash,
            $doctorWallet,
            $recordCode,
            $patientWallet,
            $expectedRecordHash,
        );
    }

    /**
     * Verify MedicalRecordAdded or MedicalRecordUpdated receipt, indexed topics, and event hash data.
     *
     * @return array{tx_hash: string, block_number: int, onchain_hash: string}
     */
    private function verifyMedicalRecordEventTransaction(
        string $topic0,
        string $method,
        string $txHash,
        string $doctorWallet,
        string $recordCode,
        string $patientWallet,
        string $expectedRecordHash,
    ): array {
        $normalizedDoctor = self::normalizeAddress($doctorWallet);
        $normalizedPatient = self::normalizeAddress($patientWallet);
        $encodedRecordId = self::encodeRecordId($recordCode);
        $normalizedExpectedHash = self::normalizeBytes32($expectedRecordHash);

        $receipt = $this->getTransactionReceipt($txHash, $normalizedDoctor);

        $matchingLog = $this->findMatchingContractLog(
            $receipt['logs'],
            [
                0 => $topic0,
                1 => $encodedRecordId,
                2 => '0x'.$this->encodeAddressWord($normalizedPatient),
                3 => '0x'.$this->encodeAddressWord($normalizedDoctor),
            ],
            $method,
        );

        $rawData = $matchingLog['data'] ?? null;

        if (! is_string($rawData)) {
            throw BlockchainException::verificationFailed(
                $method,
                'Data event transaksi rekam medis tidak ditemukan pada log receipt.',
            );
        }

        $hexBody = strtolower(preg_replace('/^0x/i', '', trim($rawData)) ?? '');

        if (strlen($hexBody) < 64 || preg_match('/\A[a-f0-9]+\z/', $hexBody) !== 1) {
            throw BlockchainException::verificationFailed(
                $method,
                'Format data event transaksi rekam medis tidak valid.',
            );
        }

        $onchainHash = '0x'.substr($hexBody, 0, 64);

        if (! hash_equals($normalizedExpectedHash, $onchainHash)) {
            throw BlockchainException::verificationFailed(
                $method,
                'Hash rekam medis pada event blockchain tidak cocok dengan hash off-chain yang diharapkan.',
            );
        }

        return [
            'tx_hash' => $receipt['tx_hash'],
            'block_number' => $receipt['block_number'],
            'onchain_hash' => $onchainHash,
        ];
    }

    /**
     * Locate a non-removed contract event log matching all expected topics.
     *
     * @param  array<int, mixed>  $logs
     * @param  array<int, string>  $expectedTopics
     * @return array<string, mixed>
     */
    private function findMatchingContractLog(array $logs, array $expectedTopics, string $method): array
    {
        $contractAddress = $this->resolveContractAddress();

        foreach ($logs as $log) {
            if (! is_array($log)) {
                continue;
            }

            if (($log['removed'] ?? false) === true) {
                continue;
            }

            $logAddress = is_string($log['address'] ?? null) ? strtolower(trim($log['address'])) : '';

            if ($logAddress !== $contractAddress) {
                continue;
            }

            $topics = $log['topics'] ?? null;

            if (! is_array($topics)) {
                continue;
            }

            $allMatch = true;

            foreach ($expectedTopics as $index => $expectedTopic) {
                if (
                    ! isset($topics[$index])
                    || ! is_string($topics[$index])
                    || strtolower(trim($topics[$index])) !== $expectedTopic
                ) {
                    $allMatch = false;

                    break;
                }
            }

            if ($allMatch) {
                return $log;
            }
        }

        throw BlockchainException::verificationFailed(
            $method,
            'Event smart contract yang diharapkan tidak ditemukan pada receipt transaksi Sepolia.',
        );
    }

    /**
     * Execute a read-only eth_call against the configured Sepolia JSON-RPC endpoint.
     */
    private function ethCall(string $method, string $data): string
    {
        $contractAddress = $this->resolveContractAddress();

        $result = $this->rpcRequest(
            'eth_call',
            [
                [
                    'to' => $contractAddress,
                    'data' => $data,
                ],
                'latest',
            ],
            $method,
        );

        if (! is_string($result) || preg_match('/\A0x[a-f0-9]*\z/i', $result) !== 1) {
            throw BlockchainException::invalidRpcResult($method, 'Field result tidak berupa string hex yang valid.');
        }

        return strtolower($result);
    }

    /**
     * Execute a JSON-RPC 2.0 request and normalize all HTTP, transport, and non-JSON errors to BlockchainException.
     *
     * @param  array<int, mixed>  $params
     */
    private function rpcRequest(string $rpcMethod, array $params, string $logicalMethod): mixed
    {
        $rpcUrl = $this->resolveRpcUrl();

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->connectTimeout($this->resolveRpcConnectTimeout())
                ->timeout($this->resolveRpcTimeout())
                ->retry(
                    [50, 150],
                    0,
                    fn (Throwable $exception): bool => $exception instanceof ConnectionException
                        || ($exception instanceof RequestException
                            && ($exception->response->serverError() || $exception->response->status() === 429)),
                    throw: false,
                )
                ->post($rpcUrl, [
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'method' => $rpcMethod,
                    'params' => $params,
                ]);
        } catch (ConnectionException|RequestException $exception) {
            throw BlockchainException::rpcTransportFailed($logicalMethod, $exception->getMessage(), $exception);
        }

        try {
            $payload = $response->json();
        } catch (Throwable $exception) {
            throw BlockchainException::rpcTransportFailed(
                $logicalMethod,
                "Node RPC mengembalikan HTTP {$response->status()} dengan body non-JSON.",
                $exception,
            );
        }

        if (! is_array($payload)) {
            if ($response->failed()) {
                throw BlockchainException::rpcTransportFailed(
                    $logicalMethod,
                    "Node RPC mengembalikan HTTP {$response->status()} dengan respons non-JSON.",
                );
            }

            throw BlockchainException::invalidRpcResult(
                $logicalMethod,
                'Respons JSON-RPC bukan objek JSON yang valid.',
            );
        }

        if (isset($payload['error']) && is_array($payload['error'])) {
            $errorData = isset($payload['error']['data']) && is_string($payload['error']['data'])
                ? $payload['error']['data']
                : null;

            throw BlockchainException::rpcReturnedError(
                $logicalMethod,
                $payload['error']['code'] ?? null,
                (string) ($payload['error']['message'] ?? 'Unknown RPC error'),
                $this->decodeRevertReason($errorData),
            );
        }

        if ($response->failed()) {
            $errorMessage = is_string($payload['message'] ?? null) && trim($payload['message']) !== ''
                ? trim($payload['message'])
                : "HTTP {$response->status()} dari node RPC.";

            throw BlockchainException::rpcTransportFailed($logicalMethod, $errorMessage);
        }

        if (! array_key_exists('result', $payload)) {
            throw BlockchainException::invalidRpcResult(
                $logicalMethod,
                'Respons JSON-RPC tidak memiliki field result maupun error.',
            );
        }

        return $payload['result'];
    }

    /**
     * Resolve and validate the configured Sepolia JSON-RPC URL.
     */
    private function resolveRpcUrl(): string
    {
        $url = trim((string) ($this->rpcUrl ?? config('blockchain.rpc_url')));

        if ($url === '') {
            throw BlockchainException::missingConfiguration('blockchain.rpc_url');
        }

        return $url;
    }

    /**
     * Resolve and validate the configured HealthcareRecord contract address.
     */
    private function resolveContractAddress(): string
    {
        $rawAddress = $this->contractAddress ?? config('blockchain.contract_address');

        if (! is_string($rawAddress) || trim($rawAddress) === '') {
            throw BlockchainException::missingConfiguration('blockchain.contract_address');
        }

        try {
            return self::normalizeAddress($rawAddress);
        } catch (InvalidArgumentException) {
            throw BlockchainException::missingConfiguration('blockchain.contract_address');
        }
    }

    /**
     * Resolve the configured JSON-RPC connection timeout in seconds.
     */
    private function resolveRpcConnectTimeout(): int
    {
        return max(1, (int) ($this->rpcConnectTimeout ?? config('blockchain.rpc_connect_timeout', 3)));
    }

    /**
     * Resolve the configured JSON-RPC request timeout in seconds.
     */
    private function resolveRpcTimeout(): int
    {
        return max(1, (int) ($this->rpcTimeout ?? config('blockchain.rpc_timeout', 10)));
    }

    /**
     * Resolve the maximum number of receipt fetch attempts when eth_getTransactionReceipt returns null.
     */
    private function resolveReceiptMaxAttempts(): int
    {
        return max(1, (int) ($this->receiptMaxAttempts ?? config('blockchain.receipt_max_attempts', 3)));
    }

    /**
     * Resolve the delay in milliseconds between receipt polling attempts.
     */
    private function resolveReceiptRetryDelayMs(): int
    {
        return max(0, (int) ($this->receiptRetryDelayMs ?? config('blockchain.receipt_retry_delay_ms', 500)));
    }

    /**
     * Resolve the configured Ethereum chain ID.
     */
    public function getChainId(): int
    {
        return (int) ($this->chainId ?? config('blockchain.chain_id', 11155111));
    }

    /**
     * Normalize multiline text fields so line endings and outer whitespace do not skew SHA-256 hashes.
     */
    private function normalizeText(string $value): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", $value));
    }

    /**
     * Encode an Ethereum address into a 32-byte left-padded ABI word without 0x prefix.
     */
    private function encodeAddressWord(string $address): string
    {
        return str_pad($this->stripHexPrefix(self::normalizeAddress($address)), 64, '0', STR_PAD_LEFT);
    }

    /**
     * Remove the leading 0x prefix from a hex string.
     */
    private function stripHexPrefix(string $hex): string
    {
        return substr($hex, 2);
    }

    /**
     * Extract and validate the ABI-encoded return words from an eth_call hex result.
     */
    private function extractAbiBodyHex(string $resultHex, int $expectedWords, string $method): string
    {
        $body = substr($resultHex, 2);
        $expectedLength = $expectedWords * 64;

        if (strlen($body) < $expectedLength) {
            throw BlockchainException::invalidRpcResult(
                $method,
                "Panjang data ABI terlalu pendek ({$expectedLength} karakter hex dibutuhkan).",
            );
        }

        return substr($body, 0, $expectedLength);
    }

    /**
     * Decode a single ABI-encoded boolean word.
     */
    private function decodeBool(string $resultHex, string $method): bool
    {
        $word = $this->extractAbiBodyHex($resultHex, 1, $method);

        if ($word === str_repeat('0', 64)) {
            return false;
        }

        if ($word === str_repeat('0', 63).'1') {
            return true;
        }

        throw BlockchainException::invalidRpcResult($method, 'Nilai boolean ABI tidak valid.');
    }

    /**
     * Decode a single ABI-encoded bytes32 word into lowercase 0x-prefixed hex.
     */
    private function decodeBytes32(string $resultHex, string $method): string
    {
        return '0x'.$this->extractAbiBodyHex($resultHex, 1, $method);
    }

    /**
     * Decode a 32-byte ABI uint256 word into a PHP integer when within 64-bit signed integer bounds.
     */
    private function decodeUint256ToInt(string $wordHex, string $method): int
    {
        return $this->decodeHexQuantityToInt('0x'.$wordHex, $method);
    }

    /**
     * Decode a 0x-prefixed hex quantity into a PHP integer within 64-bit signed integer bounds.
     */
    private function decodeHexQuantityToInt(string $hexValue, string $method): int
    {
        $significant = ltrim(strtolower(substr($hexValue, 2)), '0');

        if ($significant === '') {
            return 0;
        }

        if (strlen($significant) > 16 || (strlen($significant) === 16 && strcmp($significant, '7fffffffffffffff') > 0)) {
            throw BlockchainException::invalidRpcResult($method, 'Nilai numerik hex melebihi batas integer 64-bit PHP.');
        }

        return (int) hexdec($significant);
    }

    /**
     * Decode a standard Solidity Error(string) revert payload when present in RPC error data.
     */
    private function decodeRevertReason(?string $dataHex): ?string
    {
        if ($dataHex === null) {
            return null;
        }

        $hex = strtolower(preg_replace('/^0x/i', '', trim($dataHex)) ?? '');

        if (! str_starts_with($hex, self::SELECTOR_ERROR_STRING)) {
            return null;
        }

        $body = substr($hex, 8);

        if (strlen($body) < 128) {
            return null;
        }

        $offsetBytes = (int) hexdec(ltrim(substr($body, 0, 64), '0') ?: '0');
        $offsetHex = $offsetBytes * 2;

        if (strlen($body) < $offsetHex + 64) {
            return null;
        }

        $lengthBytes = (int) hexdec(ltrim(substr($body, $offsetHex, 64), '0') ?: '0');
        $stringHex = substr($body, $offsetHex + 64, $lengthBytes * 2);

        if (strlen($stringHex) !== $lengthBytes * 2) {
            return null;
        }

        $decoded = hex2bin($stringHex);

        return $decoded === false ? null : $decoded;
    }
}
