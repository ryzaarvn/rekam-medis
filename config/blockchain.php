<?php

$contractAddress = env('VITE_CONTRACT_ADDRESS');

return [

    /*
    |--------------------------------------------------------------------------
    | Ethereum Network Chain ID
    |--------------------------------------------------------------------------
    |
    | Target EVM chain ID used by the frontend and backend validation.
    | Defaults to Ethereum Sepolia testnet (11155111).
    |
    */

    'chain_id' => (int) env('VITE_SEPOLIA_CHAIN_ID', 11155111),

    /*
    |--------------------------------------------------------------------------
    | HealthcareRecord Smart Contract Address
    |--------------------------------------------------------------------------
    |
    | Deployed HealthcareRecord.sol contract address normalized to lowercase
    | to stay consistent with PostgreSQL address check constraints.
    |
    */

    'contract_address' => is_string($contractAddress) && trim($contractAddress) !== ''
        ? strtolower(trim($contractAddress))
        : null,

    /*
    |--------------------------------------------------------------------------
    | Admin Wallet Address
    |--------------------------------------------------------------------------
    |
    | Ethereum Sepolia wallet address of the smart contract administrator
    | normalized to lowercase to match PostgreSQL check constraints.
    |
    */

    'admin_wallet_address' => strtolower(trim((string) env('ADMIN_WALLET_ADDRESS', ''))),

    /*
    |--------------------------------------------------------------------------
    | Sepolia JSON-RPC Endpoint
    |--------------------------------------------------------------------------
    |
    | Server-side JSON-RPC URL (e.g. Alchemy Sepolia) used for read-only
    | eth_call queries such as verifyMedicalRecord and getMedicalRecord.
    |
    */

    'rpc_url' => env('SEPOLIA_RPC_URL'),

    /*
    |--------------------------------------------------------------------------
    | HTTP Client Timeouts (Seconds)
    |--------------------------------------------------------------------------
    |
    | Explicit connection and response timeouts for JSON-RPC requests.
    |
    */

    'rpc_connect_timeout' => (int) env('BLOCKCHAIN_RPC_CONNECT_TIMEOUT', 3),

    'rpc_timeout' => (int) env('BLOCKCHAIN_RPC_TIMEOUT', 10),

    /*
    |--------------------------------------------------------------------------
    | Transaction Receipt Polling Settings
    |--------------------------------------------------------------------------
    |
    | Short retry configuration when eth_getTransactionReceipt temporarily
    | returns null right after MetaMask transaction mining.
    |
    */

    'receipt_max_attempts' => (int) env('BLOCKCHAIN_RECEIPT_MAX_ATTEMPTS', 3),

    'receipt_retry_delay_ms' => (int) env('BLOCKCHAIN_RECEIPT_RETRY_DELAY_MS', 500),

];
