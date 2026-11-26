import { ethers } from 'ethers';
import healthcareRecordAbi from '../abi/HealthcareRecord.json' with { type: 'json' };

const viteEnv = import.meta.env ?? {};

export const SEPOLIA_CHAIN_ID = BigInt(viteEnv.VITE_SEPOLIA_CHAIN_ID || 11155111);
export const CONTRACT_ADDRESS = normalizeOptionalAddress(viteEnv.VITE_CONTRACT_ADDRESS);
export { healthcareRecordAbi };

function normalizeOptionalAddress(address) {
    if (typeof address !== 'string' || address.trim() === '') {
        return null;
    }

    return normalizeAddress(address);
}

/**
 * Convert a short ASCII medical record code (1-31 chars) to right-padded bytes32 hex.
 */
export function encodeRecordId(recordCode) {
    if (
        typeof recordCode !== 'string' ||
        recordCode.length === 0 ||
        recordCode.length > 31 ||
        !/^[\x01-\x7F]+$/.test(recordCode)
    ) {
        throw new Error('record_code tidak valid untuk bytes32 (maksimal 31 karakter ASCII).');
    }

    return ethers.encodeBytes32String(recordCode).toLowerCase();
}

/**
 * Decode a right-padded bytes32 hex string back to the original ASCII record code.
 */
export function decodeRecordId(bytes32Hex) {
    return ethers.decodeBytes32String(normalizeBytes32(bytes32Hex));
}

/**
 * Validate and normalize an Ethereum wallet or contract address to lowercase.
 */
export function normalizeAddress(address) {
    if (typeof address !== 'string') {
        throw new Error('Wallet address harus berupa string.');
    }

    const trimmed = address.trim();

    if (!ethers.isAddress(trimmed)) {
        throw new Error('Format wallet address Ethereum tidak valid.');
    }

    return trimmed.toLowerCase();
}

/**
 * Validate and normalize a 32-byte hex value (hash / bytes32 / tx_hash) to lowercase.
 */
export function normalizeBytes32(bytes32Hex) {
    if (typeof bytes32Hex !== 'string') {
        throw new Error('Nilai bytes32 harus berupa string hex.');
    }

    const normalized = bytes32Hex.trim().toLowerCase();

    if (!/^0x[a-f0-9]{64}$/.test(normalized)) {
        throw new Error('Format bytes32 tidak valid (harus 0x + 64 karakter hex).');
    }

    return normalized;
}

function resolveContractAddress(contractAddress = CONTRACT_ADDRESS) {
    if (!contractAddress) {
        throw new Error('VITE_CONTRACT_ADDRESS belum dikonfigurasi pada environment.');
    }

    return normalizeAddress(contractAddress);
}

function resolveEthereumProvider(ethereum) {
    const injected = ethereum ?? (typeof window !== 'undefined' ? window.ethereum : null);

    if (!injected) {
        throw new Error('MetaMask atau wallet Web3 tidak terdeteksi di browser.');
    }

    return injected;
}

function normalizeTransactionReceipt(receipt) {
    if (!receipt || !receipt.hash) {
        throw new Error('Transaction receipt tidak valid atau tx hash tidak ditemukan.');
    }

    return {
        txHash: normalizeBytes32(receipt.hash),
        blockNumber:
            receipt.blockNumber !== null && receipt.blockNumber !== undefined
                ? Number(receipt.blockNumber)
                : null,
    };
}

/**
 * Validate active MetaMask Sepolia chainId (11155111n) and wallet ownership against logged-in user.
 */
export async function validateWalletAndSepolia({
    expectedWalletAddress = null,
    ethereum = null,
    expectedChainId = SEPOLIA_CHAIN_ID,
} = {}) {
    const injected = resolveEthereumProvider(ethereum);
    const provider = new ethers.BrowserProvider(injected);

    await provider.send('eth_requestAccounts', []);

    const network = await provider.getNetwork();
    const targetChainId = BigInt(expectedChainId);

    if (network.chainId !== targetChainId) {
        throw new Error(
            `Jaringan MetaMask tidak sesuai. Harap beralih ke Ethereum Sepolia (Chain ID ${targetChainId.toString()}).`,
        );
    }

    const signer = await provider.getSigner();
    const activeAddress = normalizeAddress(await signer.getAddress());

    if (expectedWalletAddress !== null && expectedWalletAddress !== undefined) {
        const expected = normalizeAddress(expectedWalletAddress);

        if (activeAddress !== expected) {
            throw new Error(
                `Akun MetaMask aktif (${activeAddress}) tidak cocok dengan wallet terdaftar (${expected}).`,
            );
        }
    }

    return {
        provider,
        signer,
        activeAddress,
        chainId: network.chainId,
    };
}

/**
 * Prompt MetaMask to switch to Ethereum Sepolia (chainId 11155111).
 */
export async function switchToSepoliaNetwork({ ethereum = null, expectedChainId = SEPOLIA_CHAIN_ID } = {}) {
    const injected = resolveEthereumProvider(ethereum);
    const chainIdHex = `0x${BigInt(expectedChainId).toString(16)}`;

    await injected.request({
        method: 'wallet_switchEthereumChain',
        params: [{ chainId: chainIdHex }],
    });
}

/**
 * Instantiate a writable HealthcareRecord contract bound to a validated Sepolia MetaMask signer.
 */
export async function getWritableContract(options = {}) {
    const { signer, activeAddress, chainId } = await validateWalletAndSepolia(options);
    const contractAddress = resolveContractAddress(options.contractAddress);
    const contract = new ethers.Contract(contractAddress, healthcareRecordAbi, signer);

    return { contract, signer, activeAddress, chainId, contractAddress };
}

/**
 * Instantiate a read-only HealthcareRecord contract using an injected or custom ethers provider.
 */
export async function getReadOnlyContract({
    provider = null,
    ethereum = null,
    contractAddress = CONTRACT_ADDRESS,
    expectedChainId = SEPOLIA_CHAIN_ID,
} = {}) {
    const resolvedAddress = resolveContractAddress(contractAddress);
    const activeProvider = provider ?? new ethers.BrowserProvider(resolveEthereumProvider(ethereum));
    const network = await activeProvider.getNetwork();
    const targetChainId = BigInt(expectedChainId);

    if (network.chainId !== targetChainId) {
        throw new Error(
            `Jaringan RPC/MetaMask tidak sesuai. Harap gunakan Ethereum Sepolia (Chain ID ${targetChainId.toString()}).`,
        );
    }

    return new ethers.Contract(resolvedAddress, healthcareRecordAbi, activeProvider);
}

// ===== Contract Execution Helper =====

function normalizeWriteOptions(optionsOrExpectedWallet = {}) {
    if (typeof optionsOrExpectedWallet === 'string') {
        return { expectedWalletAddress: optionsOrExpectedWallet };
    }

    return optionsOrExpectedWallet ?? {};
}

async function executeContractWrite(invoker, options = {}) {
    options.onStage?.('metamask');
    const { contract } = await getWritableContract(options);
    const tx = await invoker(contract);
    options.onStage?.('sepolia', tx.hash);
    const receipt = await tx.wait();

    return normalizeTransactionReceipt(receipt);
}

// ===== Admin Functions =====

export async function addDoctor(doctorAddress, options = {}) {
    const normalizedDoctor = normalizeAddress(doctorAddress);
    const receipt = await executeContractWrite(
        (contract) => contract.addDoctor(normalizedDoctor),
        normalizeWriteOptions(options),
    );

    return {
        ...receipt,
        doctorAddress: normalizedDoctor,
    };
}

export async function removeDoctor(doctorAddress, options = {}) {
    const normalizedDoctor = normalizeAddress(doctorAddress);
    const receipt = await executeContractWrite(
        (contract) => contract.removeDoctor(normalizedDoctor),
        normalizeWriteOptions(options),
    );

    return {
        ...receipt,
        doctorAddress: normalizedDoctor,
    };
}

export async function addPatient(patientAddress, optionsOrExpectedAdminWallet = {}) {
    const normalizedPatient = normalizeAddress(patientAddress);
    const receipt = await executeContractWrite(
        (contract) => contract.addPatient(normalizedPatient),
        normalizeWriteOptions(optionsOrExpectedAdminWallet),
    );

    return {
        ...receipt,
        patientAddress: normalizedPatient,
    };
}

export async function removePatient(patientAddress, optionsOrExpectedAdminWallet = {}) {
    const normalizedPatient = normalizeAddress(patientAddress);
    const receipt = await executeContractWrite(
        (contract) => contract.removePatient(normalizedPatient),
        normalizeWriteOptions(optionsOrExpectedAdminWallet),
    );

    return {
        ...receipt,
        patientAddress: normalizedPatient,
    };
}

// ===== Patient Functions =====

export async function grantAccess(doctorAddress, options = {}) {
    const normalizedDoctor = normalizeAddress(doctorAddress);
    const receipt = await executeContractWrite(
        (contract) => contract.grantAccess(normalizedDoctor),
        options,
    );

    return {
        ...receipt,
        doctorAddress: normalizedDoctor,
    };
}

export async function revokeAccess(doctorAddress, options = {}) {
    const normalizedDoctor = normalizeAddress(doctorAddress);
    const receipt = await executeContractWrite(
        (contract) => contract.revokeAccess(normalizedDoctor),
        options,
    );

    return {
        ...receipt,
        doctorAddress: normalizedDoctor,
    };
}

// ===== Doctor Functions =====

export async function addMedicalRecord(recordCode, patientAddress, recordHash, options = {}) {
    const recordId = encodeRecordId(recordCode);
    const normalizedPatient = normalizeAddress(patientAddress);
    const normalizedHash = normalizeBytes32(recordHash);
    const receipt = await executeContractWrite(
        (contract) => contract.addMedicalRecord(recordId, normalizedPatient, normalizedHash),
        options,
    );

    return {
        ...receipt,
        recordId,
        patientAddress: normalizedPatient,
        recordHash: normalizedHash,
        actionType: 'CREATE',
    };
}

export async function updateMedicalRecord(recordCode, newRecordHash, options = {}) {
    const recordId = encodeRecordId(recordCode);
    const normalizedHash = normalizeBytes32(newRecordHash);
    const receipt = await executeContractWrite(
        (contract) => contract.updateMedicalRecord(recordId, normalizedHash),
        options,
    );

    return {
        ...receipt,
        recordId,
        recordHash: normalizedHash,
        actionType: 'UPDATE',
    };
}

// ===== Read-Only View Functions =====

export async function getMedicalRecordHash(recordCode, options = {}) {
    const contract = await getReadOnlyContract(options);
    const hash = await contract.getMedicalRecordHash(encodeRecordId(recordCode));

    return normalizeBytes32(hash);
}

export async function getMedicalRecord(recordCode, options = {}) {
    const contract = await getReadOnlyContract(options);
    const record = await contract.getMedicalRecord(encodeRecordId(recordCode));

    return {
        recordHash: normalizeBytes32(record.recordHash ?? record[0]),
        patient: normalizeAddress(record.patient ?? record[1]),
        doctor: normalizeAddress(record.doctor ?? record[2]),
        timestamp: Number(record.timestamp ?? record[3]),
    };
}

export async function verifyMedicalRecord(recordCode, offchainHash, options = {}) {
    const contract = await getReadOnlyContract(options);
    const isValid = await contract.verifyMedicalRecord(
        encodeRecordId(recordCode),
        normalizeBytes32(offchainHash),
    );

    return Boolean(isValid);
}

export async function hasAccess(patientAddress, doctorAddress, options = {}) {
    const contract = await getReadOnlyContract(options);
    const allowed = await contract.hasAccess(
        normalizeAddress(patientAddress),
        normalizeAddress(doctorAddress),
    );

    return Boolean(allowed);
}

export async function isDoctorRegistered(doctorAddress, options = {}) {
    const contract = await getReadOnlyContract(options);
    const registered = await contract.isDoctorRegistered(normalizeAddress(doctorAddress));

    return Boolean(registered);
}

export async function isPatientRegistered(patientAddress, options = {}) {
    const contract = await getReadOnlyContract(options);
    const registered = await contract.isPatientRegistered(normalizeAddress(patientAddress));

    return Boolean(registered);
}
