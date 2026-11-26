<?php

namespace App\Services;

use App\Exceptions\BlockchainException;
use App\Models\MedicalRecord;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
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

    private const SELECTOR_ERROR_STRING = '08c379a0';

    /**
     * Create a new blockchain service instance.
     */
    public function __construct(
        private ?string $rpcUrl = null,
        private ?string $contractAddress = null,
        private ?int $chainId = null,
        private ?int $rpcConnectTimeout = null,
        private ?int $rpcTimeout = null,
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
     * Build the deterministic canonical payload array for a medical record.
     *
     * @param  MedicalRecord|array<string, mixed>  $record
     * @return array{
     *     complaint: string,
     *     diagnosis: string,
     *     doctor_id: int,
     *     notes: string|null,
     *     patient_id: int,
     *     record_code: string,
     *     record_date: string,
     *     treatment: string
     * }
     */
    public function buildCanonicalPayload(MedicalRecord|array $record): array
    {
        $data = $record instanceof MedicalRecord ? [
            'record_code' => $record->record_code,
            'patient_id' => $record->patient_id,
            'doctor_id' => $record->doctor_id,
            'record_date' => $record->record_date,
            'complaint' => $record->complaint,
            'diagnosis' => $record->diagnosis,
            'treatment' => $record->treatment,
            'notes' => $record->notes,
        ] : $record;

        $recordCode = trim((string) ($data['record_code'] ?? ''));

        if ($recordCode === '') {
            throw new InvalidArgumentException('record_code wajib diisi untuk canonical hash.');
        }

        // Validate compatibility with bytes32 encoding before generating off-chain hash.
        self::encodeRecordId($recordCode);

        $patientId = (int) ($data['patient_id'] ?? 0);
        $doctorId = (int) ($data['doctor_id'] ?? 0);

        if ($patientId <= 0 || $doctorId <= 0) {
            throw new InvalidArgumentException('patient_id dan doctor_id harus berupa integer positif.');
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
            'doctor_id' => $doctorId,
            'notes' => $notes,
            'patient_id' => $patientId,
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
     * Execute a read-only eth_call against the configured Sepolia JSON-RPC endpoint.
     */
    private function ethCall(string $method, string $data): string
    {
        $rpcUrl = $this->resolveRpcUrl();
        $contractAddress = $this->resolveContractAddress();

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
                    'method' => 'eth_call',
                    'params' => [
                        [
                            'to' => $contractAddress,
                            'data' => $data,
                        ],
                        'latest',
                    ],
                ]);
        } catch (ConnectionException $exception) {
            throw BlockchainException::rpcTransportFailed($method, $exception->getMessage(), $exception);
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            $response->throw();

            throw BlockchainException::invalidRpcResult($method, 'Respons JSON-RPC bukan objek JSON yang valid.');
        }

        if (isset($payload['error']) && is_array($payload['error'])) {
            $errorData = isset($payload['error']['data']) && is_string($payload['error']['data'])
                ? $payload['error']['data']
                : null;

            throw BlockchainException::rpcReturnedError(
                $method,
                $payload['error']['code'] ?? null,
                (string) ($payload['error']['message'] ?? 'Unknown RPC error'),
                $this->decodeRevertReason($errorData),
            );
        }

        if ($response->failed()) {
            $response->throw();
        }

        $result = $payload['result'] ?? null;

        if (! is_string($result) || preg_match('/\A0x[a-f0-9]*\z/i', $result) !== 1) {
            throw BlockchainException::invalidRpcResult($method, 'Field result tidak berupa string hex yang valid.');
        }

        return strtolower($result);
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
        $significant = ltrim($wordHex, '0');

        if ($significant === '') {
            return 0;
        }

        if (strlen($significant) > 16 || (strlen($significant) === 16 && strcmp($significant, '7fffffffffffffff') > 0)) {
            throw BlockchainException::invalidRpcResult($method, 'Nilai uint256 melebihi batas integer 64-bit PHP.');
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
