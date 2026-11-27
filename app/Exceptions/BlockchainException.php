<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class BlockchainException extends RuntimeException
{
    /**
     * Create a new blockchain exception instance.
     *
     * @param  array<string, mixed>  $errorContext
     */
    public function __construct(
        string $message,
        private readonly array $errorContext = [],
        int $code = 0,
        ?Throwable $previous = null,
        private readonly int $httpStatus = 502,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Create an exception when required blockchain configuration is missing or invalid.
     */
    public static function missingConfiguration(string $configKey): self
    {
        return new self(
            "Konfigurasi blockchain [{$configKey}] belum diatur atau tidak valid.",
            ['config_key' => $configKey],
            0,
            null,
            502,
        );
    }

    /**
     * Create an exception when an outbound JSON-RPC request fails at the transport or HTTP layer.
     */
    public static function rpcTransportFailed(string $method, string $reason, ?Throwable $previous = null): self
    {
        return new self(
            "Gagal terhubung ke node RPC Sepolia saat memanggil {$method}: {$reason}",
            ['method' => $method],
            0,
            $previous,
            502,
        );
    }

    /**
     * Create an exception when the JSON-RPC node returns an error object or contract revert.
     */
    public static function rpcReturnedError(
        string $method,
        int|string|null $rpcCode,
        string $rpcMessage,
        ?string $revertReason = null,
    ): self {
        $detail = $revertReason !== null && $revertReason !== ''
            ? "{$rpcMessage} ({$revertReason})"
            : $rpcMessage;

        return new self(
            "Pemanggilan smart contract {$method} gagal: {$detail}",
            [
                'method' => $method,
                'rpc_code' => $rpcCode,
                'rpc_message' => $rpcMessage,
                'revert_reason' => $revertReason,
            ],
            0,
            null,
            502,
        );
    }

    /**
     * Create an exception when the JSON-RPC response payload cannot be decoded as expected.
     */
    public static function invalidRpcResult(string $method, string $reason): self
    {
        return new self(
            "Respons RPC tidak valid untuk {$method}: {$reason}",
            ['method' => $method],
            0,
            null,
            502,
        );
    }

    /**
     * Create an exception when on-chain transaction receipt, event log, or state verification fails.
     *
     * @param  array<string, mixed>  $context
     */
    public static function verificationFailed(string $method, string $reason, array $context = []): self
    {
        return new self(
            $reason,
            ['method' => $method, ...$context],
            0,
            null,
            422,
        );
    }

    /**
     * Get the recommended HTTP status code for JSON error responses.
     */
    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    /**
     * Get the decoded Solidity revert reason when available.
     */
    public function revertReason(): ?string
    {
        $reason = $this->errorContext['revert_reason'] ?? null;

        return is_string($reason) && $reason !== '' ? $reason : null;
    }

    /**
     * Get structured context merged into Laravel logs when reported.
     *
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->errorContext;
    }
}
