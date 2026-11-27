// SPDX-License-Identifier: MIT
pragma solidity ^0.8.20;

contract HealthcareRecord {
    // ===== State Variable =====
    address public admin;

    // ===== Struct =====
    struct Doctor {
        bool isRegistered;
        uint256 accessVersion;
    }

    struct Patient {
        bool isRegistered;
        uint256 accessVersion;
    }

    struct MedicalRecord {
        bytes32 recordHash;
        address patient;
        address doctor;
        uint256 timestamp;
        bool exists;
    }

    // ===== Mapping =====
    mapping(address => Doctor) public doctors;
    mapping(address => Patient) public patients;
    mapping(bytes32 => MedicalRecord) public medicalRecords;

    // _accessPermissions[patient][patientVersion][doctor][doctorVersion] = true/false
    mapping(address => mapping(uint256 => mapping(address => mapping(uint256 => bool))))
        private _accessPermissions;

    // ===== Events =====
    event AdminTransferred(
        address indexed previousAdmin,
        address indexed newAdmin
    );
    event DoctorAdded(address indexed doctor);
    event DoctorRemoved(address indexed doctor);
    event PatientAdded(address indexed patient);
    event PatientRemoved(address indexed patient);
    event AccessGranted(address indexed patient, address indexed doctor);
    event AccessRevoked(address indexed patient, address indexed doctor);
    event MedicalRecordAdded(
        bytes32 indexed recordId,
        address indexed patient,
        address indexed doctor,
        bytes32 recordHash,
        uint256 timestamp
    );
    event MedicalRecordUpdated(
        bytes32 indexed recordId,
        address indexed patient,
        address indexed doctor,
        bytes32 newRecordHash,
        uint256 timestamp
    );

    // ===== Modifiers =====
    modifier onlyAdmin() {
        require(msg.sender == admin, "Hanya admin");
        _;
    }

    modifier onlyRegisteredDoctor() {
        require(doctors[msg.sender].isRegistered, "Bukan dokter terdaftar");
        _;
    }

    modifier onlyRegisteredPatient() {
        require(patients[msg.sender].isRegistered, "Bukan pasien terdaftar");
        _;
    }

    // ===== Constructor =====
    constructor() {
        admin = msg.sender;
    }

    // ===== Fungsi Internal =====
    function _hasActiveAccess(
        address _patient,
        address _doctor
    ) internal view returns (bool) {
        if (
            !patients[_patient].isRegistered || !doctors[_doctor].isRegistered
        ) {
            return false;
        }

        return
            _accessPermissions[_patient][patients[_patient].accessVersion][
                _doctor
            ][doctors[_doctor].accessVersion];
    }

    // ===== Fungsi Admin: Manajemen Admin =====
    function transferAdmin(address _newAdmin) external onlyAdmin {
        require(_newAdmin != address(0), "Alamat admin baru tidak valid");
        require(_newAdmin != admin, "Alamat sudah menjadi admin");
        require(
            !doctors[_newAdmin].isRegistered,
            "Alamat sedang terdaftar sebagai dokter"
        );
        require(
            !patients[_newAdmin].isRegistered,
            "Alamat sedang terdaftar sebagai pasien"
        );

        address previousAdmin = admin;
        admin = _newAdmin;
        emit AdminTransferred(previousAdmin, _newAdmin);
    }

    // ===== Fungsi Admin: Dokter =====
    function addDoctor(address _doctor) external onlyAdmin {
        require(_doctor != address(0), "Alamat dokter tidak valid");
        require(
            _doctor != admin,
            "Alamat admin tidak dapat didaftarkan sebagai dokter"
        );
        require(!doctors[_doctor].isRegistered, "Dokter sudah terdaftar");
        require(
            !patients[_doctor].isRegistered,
            "Alamat sudah terdaftar sebagai pasien"
        );

        doctors[_doctor].isRegistered = true;
        doctors[_doctor].accessVersion += 1;
        emit DoctorAdded(_doctor);
    }

    function removeDoctor(address _doctor) external onlyAdmin {
        require(doctors[_doctor].isRegistered, "Dokter belum terdaftar");

        doctors[_doctor].isRegistered = false;
        doctors[_doctor].accessVersion += 1;
        emit DoctorRemoved(_doctor);
    }

    // ===== Fungsi Admin: Pasien =====
    function addPatient(address _patient) external onlyAdmin {
        require(_patient != address(0), "Alamat pasien tidak valid");
        require(
            _patient != admin,
            "Alamat admin tidak dapat didaftarkan sebagai pasien"
        );
        require(!patients[_patient].isRegistered, "Pasien sudah terdaftar");
        require(
            !doctors[_patient].isRegistered,
            "Alamat sudah terdaftar sebagai dokter"
        );

        patients[_patient].isRegistered = true;
        patients[_patient].accessVersion += 1;
        emit PatientAdded(_patient);
    }

    function removePatient(address _patient) external onlyAdmin {
        require(patients[_patient].isRegistered, "Pasien belum terdaftar");

        patients[_patient].isRegistered = false;
        patients[_patient].accessVersion += 1;
        emit PatientRemoved(_patient);
    }

    // ===== Fungsi Pasien =====
    function grantAccess(address _doctor) external onlyRegisteredPatient {
        require(doctors[_doctor].isRegistered, "Dokter belum terdaftar");
        require(
            _doctor != msg.sender,
            "Tidak dapat memberi izin ke diri sendiri"
        );

        uint256 patientVer = patients[msg.sender].accessVersion;
        uint256 doctorVer = doctors[_doctor].accessVersion;
        require(
            !_accessPermissions[msg.sender][patientVer][_doctor][doctorVer],
            "Izin sudah diberikan"
        );

        _accessPermissions[msg.sender][patientVer][_doctor][doctorVer] = true;
        emit AccessGranted(msg.sender, _doctor);
    }

    function revokeAccess(address _doctor) external onlyRegisteredPatient {
        require(_hasActiveAccess(msg.sender, _doctor), "Izin belum ada");

        uint256 patientVer = patients[msg.sender].accessVersion;
        uint256 doctorVer = doctors[_doctor].accessVersion;
        _accessPermissions[msg.sender][patientVer][_doctor][doctorVer] = false;
        emit AccessRevoked(msg.sender, _doctor);
    }

    // ===== Fungsi Dokter =====
    function addMedicalRecord(
        bytes32 _recordId,
        address _patient,
        bytes32 _recordHash
    ) external onlyRegisteredDoctor {
        require(_recordId != bytes32(0), "recordId tidak boleh kosong");
        require(_patient != address(0), "Alamat pasien tidak valid");
        require(patients[_patient].isRegistered, "Pasien belum terdaftar");
        require(
            _hasActiveAccess(_patient, msg.sender),
            "Dokter belum diberi izin"
        );
        require(!medicalRecords[_recordId].exists, "recordId sudah dipakai");
        require(_recordHash != bytes32(0), "Hash tidak boleh kosong");

        medicalRecords[_recordId] = MedicalRecord(
            _recordHash,
            _patient,
            msg.sender,
            block.timestamp,
            true
        );

        emit MedicalRecordAdded(
            _recordId,
            _patient,
            msg.sender,
            _recordHash,
            block.timestamp
        );
    }

    function updateMedicalRecord(
        bytes32 _recordId,
        bytes32 _newRecordHash
    ) external onlyRegisteredDoctor {
        require(
            medicalRecords[_recordId].exists,
            "Rekam medis tidak ditemukan"
        );

        address patient = medicalRecords[_recordId].patient;
        require(patients[patient].isRegistered, "Pasien belum terdaftar");
        require(
            _hasActiveAccess(patient, msg.sender),
            "Dokter belum diberi izin"
        );
        require(_newRecordHash != bytes32(0), "Hash tidak boleh kosong");

        medicalRecords[_recordId].recordHash = _newRecordHash;
        medicalRecords[_recordId].doctor = msg.sender;
        medicalRecords[_recordId].timestamp = block.timestamp;

        emit MedicalRecordUpdated(
            _recordId,
            patient,
            msg.sender,
            _newRecordHash,
            block.timestamp
        );
    }

    // ===== Fungsi Pendukung & Verifikasi (Read-Only / Gratis Gas) =====
    function getMedicalRecordHash(
        bytes32 _recordId
    ) external view returns (bytes32) {
        require(
            medicalRecords[_recordId].exists,
            "Rekam medis tidak ditemukan"
        );
        return medicalRecords[_recordId].recordHash;
    }

    function getMedicalRecord(
        bytes32 _recordId
    )
        external
        view
        returns (
            bytes32 recordHash,
            address patient,
            address doctor,
            uint256 timestamp
        )
    {
        require(
            medicalRecords[_recordId].exists,
            "Rekam medis tidak ditemukan"
        );
        MedicalRecord storage rec = medicalRecords[_recordId];
        return (rec.recordHash, rec.patient, rec.doctor, rec.timestamp);
    }

    function verifyMedicalRecord(
        bytes32 _recordId,
        bytes32 _offchainHash
    ) external view returns (bool) {
        if (!medicalRecords[_recordId].exists) {
            return false;
        }
        return medicalRecords[_recordId].recordHash == _offchainHash;
    }

    function hasAccess(
        address _patient,
        address _doctor
    ) external view returns (bool) {
        return _hasActiveAccess(_patient, _doctor);
    }

    function isDoctorRegistered(address _doctor) external view returns (bool) {
        return doctors[_doctor].isRegistered;
    }

    function isPatientRegistered(
        address _patient
    ) external view returns (bool) {
        return patients[_patient].isRegistered;
    }
}
