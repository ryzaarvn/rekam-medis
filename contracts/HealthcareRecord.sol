// SPDX-License-Identifier: MIT
pragma solidity ^0.8.20;

contract HealthcareRecord {
    // ===== State Variable =====
    address public admin;

    // ===== Struct =====
    struct Doctor {
        bool isRegistered;
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
    mapping(bytes32 => MedicalRecord) public medicalRecords;
    // accessPermissions[pasien][dokter] = true/false
    mapping(address => mapping(address => bool)) public accessPermissions;

    // ===== Events =====
    event DoctorAdded(address indexed doctor);
    event DoctorRemoved(address indexed doctor);
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

    // ===== Modifier =====
    modifier onlyAdmin() {
        require(msg.sender == admin, "Hanya admin");
        _;
    }

    modifier onlyRegisteredDoctor() {
        require(doctors[msg.sender].isRegistered, "Bukan dokter terdaftar");
        _;
    }

    // ===== Constructor =====
    constructor() {
        admin = msg.sender;
    }

    // ===== Fungsi Admin =====
    function addDoctor(address _doctor) external onlyAdmin {
        require(_doctor != address(0), "Alamat dokter tidak valid");
        require(!doctors[_doctor].isRegistered, "Dokter sudah terdaftar");

        doctors[_doctor].isRegistered = true;
        emit DoctorAdded(_doctor);
    }

    function removeDoctor(address _doctor) external onlyAdmin {
        require(doctors[_doctor].isRegistered, "Dokter belum terdaftar");

        doctors[_doctor].isRegistered = false;
        emit DoctorRemoved(_doctor);
    }

    // ===== Fungsi Pasien =====
    function grantAccess(address _doctor) external {
        require(doctors[_doctor].isRegistered, "Dokter belum terdaftar");
        require(
            _doctor != msg.sender,
            "Tidak dapat memberi izin ke diri sendiri"
        );
        require(
            !accessPermissions[msg.sender][_doctor],
            "Izin sudah diberikan"
        );

        accessPermissions[msg.sender][_doctor] = true;
        emit AccessGranted(msg.sender, _doctor);
    }

    function revokeAccess(address _doctor) external {
        require(accessPermissions[msg.sender][_doctor], "Izin belum ada");

        accessPermissions[msg.sender][_doctor] = false;
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
        require(
            accessPermissions[_patient][msg.sender],
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
        require(
            accessPermissions[patient][msg.sender],
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
        return accessPermissions[_patient][_doctor];
    }

    function isDoctorRegistered(address _doctor) external view returns (bool) {
        return doctors[_doctor].isRegistered;
    }
}