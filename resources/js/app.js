import * as healthcareBlockchain from './blockchain/healthcareRecord';
import {
    formatBlockchainError,
    getConfiguredChainId,
    getExpectedWalletAddress,
    initTopbarWallet,
    renderLogsTableRows,
    restoreButton,
    sendJsonRequest,
    setButtonStage,
    showAlertBox,
} from './blockchain/walletUi';

if (typeof window !== 'undefined') {
    window.HealthcareBlockchain = healthcareBlockchain;
}

function initSidebarToggle() {
    const toggleBtn = document.getElementById('sidebarToggleBtn');
    const sidebar = document.getElementById('appSidebar');

    if (!toggleBtn || !sidebar) {
        return;
    }

    toggleBtn.addEventListener('click', () => {
        if (window.innerWidth < 992) {
            sidebar.classList.toggle('mobile-open');
        } else {
            sidebar.classList.toggle('collapsed');
        }
    });
}

function bindAdminOnchainButtons({ walletDatasetKey, nameDatasetKey, registerFn, deactivateFn }) {
    const alertBox = document.getElementById('globalBlockchainAlert');
    const expectedWalletAddress = getExpectedWalletAddress();
    const expectedChainId = getConfiguredChainId();

    const handleOnchainAction = async (button, isRegister) => {
        const targetWallet = button.dataset[walletDatasetKey];
        const targetName = button.dataset[nameDatasetKey];
        const url = button.dataset.url;

        try {
            setButtonStage(button, 'metamask');
            const options = {
                expectedWalletAddress,
                expectedChainId,
                onStage: (stage) => setButtonStage(button, stage),
            };

            const receipt = isRegister
                ? await registerFn(targetWallet, options)
                : await deactivateFn(targetWallet, options);

            setButtonStage(button, 'database');
            const result = await sendJsonRequest(
                url,
                'POST',
                isRegister ? { registration_tx_hash: receipt.txHash } : { tx_hash: receipt.txHash },
            );

            showAlertBox(
                alertBox,
                'success',
                result.message ?? `Status on-chain ${targetName} berhasil diperbarui.`,
                result.etherscan_url ?? null,
            );
            window.location.reload();
        } catch (error) {
            restoreButton(button);
            showAlertBox(alertBox, 'danger', formatBlockchainError(error));
        }
    };

    document.querySelectorAll('.btn-register-onchain').forEach((btn) => {
        btn.addEventListener('click', () => handleOnchainAction(btn, true));
    });

    document.querySelectorAll('.btn-deactivate-onchain').forEach((btn) => {
        btn.addEventListener('click', () => handleOnchainAction(btn, false));
    });
}

function initAdminDoctorsPage() {
    document.querySelectorAll('.btn-edit-doctor').forEach((button) => {
        button.addEventListener('click', () => {
            const form = document.getElementById('formDoctorEdit');
            form.action = button.dataset.updateUrl;
            document.getElementById('editDocActionInput').value = button.dataset.updateUrl;
            document.getElementById('editDocCode').value = button.dataset.doctorCode ?? '';
            document.getElementById('editDocFullName').value = button.dataset.fullName ?? '';
            document.getElementById('editDocSpecialization').value = button.dataset.specialization ?? '';
            document.getElementById('editDocStrNumber').value = button.dataset.strNumber ?? '';
            document.getElementById('editDocUsername').value = button.dataset.username ?? '';
            document.getElementById('editDocEmail').value = button.dataset.email ?? '';
            document.getElementById('editDocWallet').value = button.dataset.walletAddress ?? '';

            window.bootstrap.Modal.getOrCreateInstance(document.getElementById('modalDoctorEdit')).show();
        });
    });

    bindAdminOnchainButtons({
        walletDatasetKey: 'doctorWallet',
        nameDatasetKey: 'doctorName',
        registerFn: healthcareBlockchain.addDoctor,
        deactivateFn: healthcareBlockchain.removeDoctor,
    });
}

function initAdminPatientsPage() {
    document.querySelectorAll('.btn-edit-patient').forEach((button) => {
        button.addEventListener('click', () => {
            const form = document.getElementById('formPatientEdit');
            form.action = button.dataset.updateUrl;
            document.getElementById('editPatActionInput').value = button.dataset.updateUrl;
            document.getElementById('editPatCode').value = button.dataset.patientCode ?? '';
            document.getElementById('editPatFullName').value = button.dataset.fullName ?? '';
            document.getElementById('editPatDob').value = button.dataset.dateOfBirth ?? '';
            document.getElementById('editPatGender').value = button.dataset.gender ?? 'Laki-laki';
            document.getElementById('editPatAddress').value = button.dataset.address ?? '';
            document.getElementById('editPatUsername').value = button.dataset.username ?? '';
            document.getElementById('editPatEmail').value = button.dataset.email ?? '';
            document.getElementById('editPatWallet').value = button.dataset.walletAddress ?? '';

            window.bootstrap.Modal.getOrCreateInstance(document.getElementById('modalPatientEdit')).show();
        });
    });

    bindAdminOnchainButtons({
        walletDatasetKey: 'patientWallet',
        nameDatasetKey: 'patientName',
        registerFn: healthcareBlockchain.addPatient,
        deactivateFn: healthcareBlockchain.removePatient,
    });
}

function initQuickChips() {
    document.querySelectorAll('.quick-chip').forEach((chip) => {
        chip.addEventListener('click', () => {
            const targetId = chip.dataset.quickTarget;
            const text = chip.dataset.quickText ?? '';
            const input = document.getElementById(targetId);

            if (input) {
                input.value = text;
                input.focus();
            }
        });
    });
}

function initDokterCreateForm() {
    const form = document.getElementById('dokterCreateRecordForm');
    const submitBtn = document.getElementById('btnSubmitCreateRecord');
    const alertBox = document.getElementById('dokterRecordAlert');

    if (!form || !submitBtn) {
        return;
    }

    initQuickChips();

    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        const formData = new FormData(form);
        const payload = {
            patient_id: Number(formData.get('patient_id')),
            record_date: String(formData.get('record_date') ?? ''),
            complaint: String(formData.get('complaint') ?? ''),
            diagnosis: String(formData.get('diagnosis') ?? ''),
            treatment: String(formData.get('treatment') ?? ''),
            notes: String(formData.get('notes') ?? '') || null,
        };

        try {
            setButtonStage(submitBtn, 'prepare');
            const prepared = await sendJsonRequest(form.dataset.prepareUrl, 'POST', payload);

            const receipt = await healthcareBlockchain.addMedicalRecord(
                prepared.record_code,
                prepared.patient_wallet,
                prepared.current_hash,
                {
                    expectedWalletAddress: getExpectedWalletAddress(),
                    expectedChainId: getConfiguredChainId(),
                    onStage: (stage) => setButtonStage(submitBtn, stage),
                },
            );

            setButtonStage(submitBtn, 'database');
            const stored = await sendJsonRequest(form.dataset.storeUrl, 'POST', {
                ...payload,
                record_code: prepared.record_code,
                current_hash: prepared.current_hash,
                tx_hash: receipt.txHash,
                block_number: receipt.blockNumber,
            });

            showAlertBox(alertBox, 'success', stored.message, stored.etherscan_url);
            form.reset();
            const dateInput = document.getElementById('record_date');
            if (dateInput) {
                dateInput.value = new Date().toISOString().slice(0, 10);
            }
        } catch (error) {
            showAlertBox(alertBox, 'danger', formatBlockchainError(error));
        } finally {
            restoreButton(submitBtn);
        }
    });
}

function initDokterEditForm() {
    const form = document.getElementById('dokterEditRecordForm');
    const submitBtn = document.getElementById('btnSubmitUpdateRecord');
    const alertBox = document.getElementById('dokterRecordAlert');

    if (!form || !submitBtn) {
        return;
    }

    initQuickChips();

    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        const formData = new FormData(form);
        const payload = {
            record_date: String(formData.get('record_date') ?? ''),
            complaint: String(formData.get('complaint') ?? ''),
            diagnosis: String(formData.get('diagnosis') ?? ''),
            treatment: String(formData.get('treatment') ?? ''),
            notes: String(formData.get('notes') ?? '') || null,
        };

        try {
            setButtonStage(submitBtn, 'prepare');
            const prepared = await sendJsonRequest(form.dataset.prepareUrl, 'POST', payload);

            const receipt = await healthcareBlockchain.updateMedicalRecord(
                prepared.record_code,
                prepared.current_hash,
                {
                    expectedWalletAddress: getExpectedWalletAddress(),
                    expectedChainId: getConfiguredChainId(),
                    onStage: (stage) => setButtonStage(submitBtn, stage),
                },
            );

            setButtonStage(submitBtn, 'database');
            const updated = await sendJsonRequest(form.dataset.updateUrl, 'PUT', {
                ...payload,
                current_hash: prepared.current_hash,
                tx_hash: receipt.txHash,
                block_number: receipt.blockNumber,
            });

            showAlertBox(alertBox, 'success', updated.message, updated.etherscan_url);
        } catch (error) {
            showAlertBox(alertBox, 'danger', formatBlockchainError(error));
        } finally {
            restoreButton(submitBtn);
        }
    });
}

function initDokterRecordsIndex() {
    const jsonEl = document.getElementById('dokterRecordsJson');
    if (!jsonEl) {
        return;
    }

    const recordsMap = JSON.parse(jsonEl.textContent || '{}');

    document.querySelectorAll('.btn-dokter-detail').forEach((button) => {
        button.addEventListener('click', () => {
            const record = recordsMap[button.dataset.recordId];
            if (!record) {
                return;
            }

            document.getElementById('dokterDetailModalTitle').textContent = `Detail Rekam Medis (${record.record_code})`;
            document.getElementById('dokterDetailDate').textContent = record.record_date ?? '-';
            document.getElementById('dokterDetailPatient').textContent = record.patient_name ?? '-';
            document.getElementById('dokterDetailDoctor').textContent = record.doctor_name ?? '-';
            document.getElementById('dokterDetailComplaint').textContent = record.complaint ?? '-';
            document.getElementById('dokterDetailDiagnosis').textContent = record.diagnosis ?? '-';
            document.getElementById('dokterDetailTreatment').textContent = record.treatment ?? '-';
            document.getElementById('dokterDetailNotes').textContent = record.notes ?? '-';
            document.getElementById('dokterDetailHash').textContent = record.current_hash ?? '-';
            renderLogsTableRows(document.getElementById('dokterDetailLogsBody'), record.logs);

            window.bootstrap.Modal.getOrCreateInstance(document.getElementById('modalDokterRecordDetail')).show();
        });
    });
}

function setVerificationTxLink(wrapId, linkId, etherscanUrl, txHash) {
    const wrap = document.getElementById(wrapId);
    const link = document.getElementById(linkId);

    if (!wrap || !link) {
        return;
    }

    if (etherscanUrl && /^https:\/\/sepolia\.etherscan\.io\/tx\/0x[a-f0-9]{64}$/i.test(etherscanUrl)) {
        link.href = etherscanUrl;
        link.textContent = txHash ? `${txHash.slice(0, 10)}...${txHash.slice(-8)} (Sepolia Etherscan)` : 'Lihat di Sepolia Etherscan';
        wrap.classList.remove('d-none');
    } else {
        wrap.classList.add('d-none');
    }
}

function initPasienDashboard() {
    const jsonEl = document.getElementById('pasienRecordsJson');
    const recordsMap = jsonEl ? JSON.parse(jsonEl.textContent || '{}') : {};

    document.querySelectorAll('.btn-pasien-detail').forEach((button) => {
        button.addEventListener('click', () => {
            const record = recordsMap[button.dataset.recordId];
            if (!record) {
                return;
            }

            document.getElementById('pasienDetailModalTitle').textContent = `Detail Rekam Medis (${record.record_code})`;
            document.getElementById('pasienDetailDate').textContent = record.record_date ?? '-';
            document.getElementById('pasienDetailDoctor').textContent = record.doctor_name ?? '-';
            document.getElementById('pasienDetailComplaint').textContent = record.complaint ?? '-';
            document.getElementById('pasienDetailDiagnosis').textContent = record.diagnosis ?? '-';
            document.getElementById('pasienDetailTreatment').textContent = record.treatment ?? '-';
            document.getElementById('pasienDetailNotes').textContent = record.notes ?? '-';
            document.getElementById('pasienDetailHash').textContent = record.current_hash ?? '-';
            renderLogsTableRows(document.getElementById('pasienDetailLogsBody'), record.logs);

            window.bootstrap.Modal.getOrCreateInstance(document.getElementById('modalDetailRekamMedis')).show();
        });
    });

    const verifValid = document.getElementById('verif-valid');
    const verifInvalid = document.getElementById('verif-invalid');
    const verifError = document.getElementById('verif-error');

    document.querySelectorAll('.btn-verify-record').forEach((button) => {
        button.addEventListener('click', async () => {
            verifValid?.classList.add('d-none');
            verifInvalid?.classList.add('d-none');
            verifError?.classList.add('d-none');

            try {
                setButtonStage(button, 'verify');
                const result = await sendJsonRequest(button.dataset.verifyUrl, 'POST', {});

                if (result.is_valid) {
                    document.getElementById('verifValidCode').textContent = result.record_code;
                    document.getElementById('verifValidRecomputed').textContent = result.recomputed_hash;
                    document.getElementById('verifValidStored').textContent = result.stored_current_hash;
                    document.getElementById('verifValidOnchain').textContent = result.onchain_hash;
                    document.getElementById('verifValidDoctor').textContent = result.last_doctor_name;
                    document.getElementById('verifValidOnchainDoctor').textContent = result.onchain_doctor;
                    document.getElementById('verifValidTimestamp').textContent = result.onchain_timestamp_formatted;
                    setVerificationTxLink('verifValidTxWrap', 'verifValidTxLink', result.etherscan_url, result.latest_tx_hash);
                    verifValid?.classList.remove('d-none');
                } else {
                    document.getElementById('verifInvalidCode').textContent = result.record_code;
                    document.getElementById('verifInvalidOnchain').textContent = result.onchain_hash;
                    document.getElementById('verifInvalidRecomputed').textContent = result.recomputed_hash;
                    document.getElementById('verifInvalidStored').textContent = result.stored_current_hash;
                    document.getElementById('verifInvalidDoctor').textContent = result.last_doctor_name;
                    document.getElementById('verifInvalidOnchainDoctor').textContent = result.onchain_doctor;
                    document.getElementById('verifInvalidTimestamp').textContent = result.onchain_timestamp_formatted;
                    setVerificationTxLink('verifInvalidTxWrap', 'verifInvalidTxLink', result.etherscan_url, result.latest_tx_hash);
                    verifInvalid?.classList.remove('d-none');
                }
            } catch (error) {
                showAlertBox(verifError, 'danger', formatBlockchainError(error));
            } finally {
                restoreButton(button);
            }
        });
    });
}

function initPasienAuthorizations() {
    const alertBox = document.getElementById('authActionAlert');
    const expectedWalletAddress = getExpectedWalletAddress();
    const expectedChainId = getConfiguredChainId();

    document.querySelectorAll('.btn-auth-action').forEach((button) => {
        button.addEventListener('click', async () => {
            if (button.disabled) {
                return;
            }

            const action = button.dataset.action;
            const doctorWallet = button.dataset.doctorWallet;
            const url = button.dataset.url;

            try {
                setButtonStage(button, 'metamask');
                const options = {
                    expectedWalletAddress,
                    expectedChainId,
                    onStage: (stage) => setButtonStage(button, stage),
                };

                const receipt = action === 'grant'
                    ? await healthcareBlockchain.grantAccess(doctorWallet, options)
                    : await healthcareBlockchain.revokeAccess(doctorWallet, options);

                setButtonStage(button, 'database');
                const result = await sendJsonRequest(url, 'POST', { tx_hash: receipt.txHash });

                showAlertBox(alertBox, 'success', result.message, result.etherscan_url);
                window.location.reload();
            } catch (error) {
                restoreButton(button);
                showAlertBox(alertBox, 'danger', formatBlockchainError(error));
            }
        });
    });
}

function initConfirmForms() {
    document.querySelectorAll('form[data-confirm]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            const message = form.dataset.confirm;
            if (message && !window.confirm(message)) {
                event.preventDefault();
            }
        });
    });
}

document.addEventListener('DOMContentLoaded', () => {
    const page = document.body?.dataset?.page;

    initSidebarToggle();
    initTopbarWallet();
    initConfirmForms();

    if (page === 'admin-doctors') {
        initAdminDoctorsPage();
    } else if (page === 'admin-patients') {
        initAdminPatientsPage();
    } else if (page === 'dokter-record-create') {
        initDokterCreateForm();
    } else if (page === 'dokter-record-edit') {
        initDokterEditForm();
    } else if (page === 'dokter-records-index') {
        initDokterRecordsIndex();
    } else if (page === 'pasien-dashboard') {
        initPasienDashboard();
    } else if (page === 'pasien-authorizations') {
        initPasienAuthorizations();
    }
});

export * from './blockchain/healthcareRecord';
