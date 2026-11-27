<li>
    <a href="{{ route('dokter.dashboard') }}" class="{{ request()->routeIs('dokter.dashboard') ? 'active' : '' }}">
        <i class="bi bi-speedometer2"></i> Dashboard
    </a>
</li>
<li>
    <a href="{{ route('dokter.records.create') }}" class="{{ request()->routeIs('dokter.records.create') ? 'active' : '' }}">
        <i class="bi bi-file-earmark-plus"></i> Catat Pemeriksaan
    </a>
</li>
<li>
    <a href="{{ route('dokter.records.index') }}" class="{{ request()->routeIs('dokter.records.index', 'dokter.records.edit') ? 'active' : '' }}">
        <i class="bi bi-journal-medical"></i> Riwayat Rekam Medis
    </a>
</li>
<li>
    <a href="{{ route('account.edit') }}" class="{{ request()->routeIs('account.*') ? 'active' : '' }}">
        <i class="bi bi-person-circle"></i> Akun
    </a>
</li>
