<li>
    <a href="{{ route('dokter.dashboard') }}" class="{{ request()->routeIs('dokter.dashboard') ? 'active' : '' }}">
        <i class="bi bi-file-earmark-medical"></i> Catat Pemeriksaan
    </a>
</li>
<li>
    <a href="{{ route('dokter.records.index') }}" class="{{ request()->routeIs('dokter.records.*') ? 'active' : '' }}">
        <i class="bi bi-journal-medical"></i> Riwayat Pemeriksaan
    </a>
</li>
<li>
    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit" class="sidebar-logout-btn">
            <i class="bi bi-box-arrow-left"></i> Keluar
        </button>
    </form>
</li>
