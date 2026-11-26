<li>
    <a href="{{ route('pasien.dashboard') }}" class="{{ request()->routeIs('pasien.dashboard') ? 'active' : '' }}">
        <i class="bi bi-file-medical"></i> Riwayat &amp; Verifikasi
    </a>
</li>
<li>
    <a href="{{ route('pasien.authorizations.index') }}"
        class="{{ request()->routeIs('pasien.authorizations.*') ? 'active' : '' }}">
        <i class="bi bi-shield-lock"></i> Pengelolaan Izin Dokter
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