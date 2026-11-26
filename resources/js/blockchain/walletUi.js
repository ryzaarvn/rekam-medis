import {
    SEPOLIA_CHAIN_ID,
    normalizeAddress,
    switchToSepoliaNetwork,
} from './healthcareRecord';

export const STAGE_LABELS = {
    prepare: 'Menyiapkan data & hash...',
    metamask: 'Menunggu konfirmasi MetaMask...',
    sepolia: 'Menunggu konfirmasi blok Sepolia...',
    database: 'Menyimpan ke database...',
    verify: 'Memverifikasi hash ke Sepolia...',
};

export function shortenHex(value, start = 6, end = 4) {
    if (typeof value !== 'string' || value.length <= start + end + 3) {
        return value ?? '-';
    }

    return `${value.slice(0, start)}...${value.slice(-end)}`;
}

export function formatBlockchainError(error) {
    if (!error) {
        return 'Terjadi kesalahan yang tidak diketahui.';
    }

    const code = error.code ?? error?.info?.error?.code;

    if (code === 'ACTION_REJECTED' || code === 4001) {
        return 'Transaksi atau permintaan akun dibatalkan di MetaMask.';
    }

    if (code === 'INSUFFICIENT_FUNDS') {
        return 'Saldo Sepolia ETH pada wallet Anda tidak mencukupi untuk biaya gas.';
    }

    if (code === 'CALL_EXCEPTION') {
        const reason = error.reason || error.shortMessage || 'Transaksi ditolak oleh smart contract.';
        return `Transaksi smart contract gagal: ${reason}`;
    }

    if (error.message) {
        return error.message;
    }

    return 'Terjadi kesalahan saat berkomunikasi dengan MetaMask atau server.';
}

export function setButtonStage(button, stageKey) {
    if (!button) {
        return;
    }

    if (!button.dataset.originalHtml) {
        button.dataset.originalHtml = button.innerHTML;
    }

    button.disabled = true;
    const label = STAGE_LABELS[stageKey] ?? stageKey;

    button.replaceChildren();
    const spinner = document.createElement('span');
    spinner.className = 'spinner-border spinner-border-sm me-2';
    spinner.setAttribute('role', 'status');
    spinner.setAttribute('aria-hidden', 'true');
    button.appendChild(spinner);
    button.appendChild(document.createTextNode(label));
}

export function restoreButton(button) {
    if (!button) {
        return;
    }

    button.disabled = false;

    if (button.dataset.originalHtml) {
        button.innerHTML = button.dataset.originalHtml;
        delete button.dataset.originalHtml;
    }
}

export function getCsrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

export async function sendJsonRequest(url, method = 'POST', payload = {}) {
    const response = await fetch(url, {
        method,
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': getCsrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify(payload),
    });

    const data = await response.json().catch(() => ({}));

    if (!response.ok) {
        const validationErrors = data?.errors
            ? Object.values(data.errors).flat().join(' ')
            : '';
        const message = validationErrors || data?.message || `Permintaan gagal (HTTP ${response.status}).`;
        throw new Error(message);
    }

    return data;
}

export function showAlertBox(container, type, message, etherscanUrl = null) {
    if (!container) {
        return;
    }

    container.className = `alert alert-${type}`;
    container.replaceChildren();

    const textNode = document.createElement('span');
    textNode.textContent = message;
    container.appendChild(textNode);

    if (etherscanUrl && /^https:\/\/sepolia\.etherscan\.io\/tx\/0x[a-f0-9]{64}$/i.test(etherscanUrl)) {
        const link = document.createElement('a');
        link.href = etherscanUrl;
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
        link.className = 'alert-link ms-2';
        link.textContent = 'Lihat di Sepolia Etherscan';
        container.appendChild(link);
    }
}

export function renderLogsTableRows(tbody, logs) {
    if (!tbody) {
        return;
    }

    tbody.replaceChildren();

    if (!Array.isArray(logs) || logs.length === 0) {
        const tr = document.createElement('tr');
        const td = document.createElement('td');
        td.colSpan = 5;
        td.className = 'text-center text-muted';
        td.textContent = 'Belum ada log transaksi.';
        tr.appendChild(td);
        tbody.appendChild(tr);
        return;
    }

    logs.forEach((log) => {
        const tr = document.createElement('tr');

        const tdAction = document.createElement('td');
        const badge = document.createElement('span');
        badge.className = `badge ${log.action_type === 'CREATE' ? 'bg-success' : 'bg-warning text-dark'}`;
        badge.textContent = log.action_type;
        tdAction.appendChild(badge);

        const tdDoctor = document.createElement('td');
        tdDoctor.textContent = log.doctor_name ?? '-';

        const tdBlock = document.createElement('td');
        tdBlock.textContent = String(log.block_number ?? '-');

        const tdTime = document.createElement('td');
        tdTime.textContent = log.created_at ?? '-';

        const tdLink = document.createElement('td');
        if (log.tx_hash && /^0x[a-f0-9]{64}$/i.test(log.tx_hash)) {
            const link = document.createElement('a');
            link.href = `https://sepolia.etherscan.io/tx/${log.tx_hash}`;
            link.target = '_blank';
            link.rel = 'noopener noreferrer';
            link.textContent = shortenHex(log.tx_hash, 8, 6);
            tdLink.appendChild(link);
        } else {
            tdLink.textContent = '-';
        }

        tr.append(tdAction, tdDoctor, tdBlock, tdTime, tdLink);
        tbody.appendChild(tr);
    });
}

export function getExpectedWalletAddress() {
    const raw = document.body?.dataset?.expectedWallet ?? '';

    return raw.trim() !== '' ? raw.trim().toLowerCase() : null;
}

export function getConfiguredChainId() {
    const raw = document.body?.dataset?.chainId;

    return raw ? BigInt(raw) : SEPOLIA_CHAIN_ID;
}

export function initTopbarWallet() {
    const btnConnect = document.getElementById('btnConnectMetaMask');
    const connectedInfo = document.getElementById('walletConnectedInfo');
    const activeAddressEl = document.getElementById('walletActiveAddress');
    const networkBadge = document.getElementById('walletNetworkBadge');
    const btnSwitchSepolia = document.getElementById('btnSwitchSepolia');
    const mismatchBanner = document.getElementById('walletMismatchBanner');
    const mismatchActive = document.getElementById('mismatchActiveWallet');
    const globalAlert = document.getElementById('globalBlockchainAlert');

    if (!btnConnect || !connectedInfo) {
        return;
    }

    const expectedWallet = getExpectedWalletAddress();
    const targetChainId = getConfiguredChainId();

    const updateWalletUI = async () => {
        if (!window.ethereum) {
            btnConnect.classList.remove('d-none');
            connectedInfo.classList.add('d-none');
            mismatchBanner?.classList.add('d-none');
            return;
        }

        try {
            const [accounts, chainHex] = await Promise.all([
                window.ethereum.request({ method: 'eth_accounts' }),
                window.ethereum.request({ method: 'eth_chainId' }),
            ]);

            if (!Array.isArray(accounts) || accounts.length === 0) {
                btnConnect.classList.remove('d-none');
                connectedInfo.classList.add('d-none');
                mismatchBanner?.classList.add('d-none');
                return;
            }

            const activeAddress = normalizeAddress(accounts[0]);
            const currentChainId = BigInt(chainHex);
            const isSepolia = currentChainId === targetChainId;

            btnConnect.classList.add('d-none');
            connectedInfo.classList.remove('d-none');
            activeAddressEl.textContent = shortenHex(activeAddress, 6, 4);
            activeAddressEl.title = activeAddress;

            if (isSepolia) {
                networkBadge.className = 'badge bg-success';
                networkBadge.textContent = 'Sepolia';
                btnSwitchSepolia?.classList.add('d-none');
            } else {
                networkBadge.className = 'badge bg-danger';
                networkBadge.textContent = 'Salah Jaringan';
                btnSwitchSepolia?.classList.remove('d-none');
            }

            if (expectedWallet && activeAddress !== expectedWallet) {
                if (mismatchActive) {
                    mismatchActive.textContent = activeAddress;
                }
                mismatchBanner?.classList.remove('d-none');
                mismatchBanner?.classList.add('d-flex');
            } else {
                mismatchBanner?.classList.add('d-none');
                mismatchBanner?.classList.remove('d-flex');
            }
        } catch {
            // Keep UI clean if wallet provider temporarily refuses passive read.
        }
    };

    btnConnect.addEventListener('click', async () => {
        if (!window.ethereum) {
            showAlertBox(
                globalAlert,
                'danger',
                'MetaMask tidak terdeteksi di browser Anda. Silakan instal ekstensi MetaMask terlebih dahulu.',
            );
            return;
        }

        try {
            await window.ethereum.request({ method: 'eth_requestAccounts' });
            globalAlert?.classList.add('d-none');
            await updateWalletUI();
        } catch (error) {
            showAlertBox(globalAlert, 'danger', formatBlockchainError(error));
        }
    });

    btnSwitchSepolia?.addEventListener('click', async () => {
        try {
            await switchToSepoliaNetwork({ expectedChainId: targetChainId });
            await updateWalletUI();
        } catch (error) {
            showAlertBox(globalAlert, 'danger', formatBlockchainError(error));
        }
    });

    if (window.ethereum?.on) {
        window.ethereum.on('accountsChanged', updateWalletUI);
        window.ethereum.on('chainChanged', updateWalletUI);
    }

    updateWalletUI();
}
