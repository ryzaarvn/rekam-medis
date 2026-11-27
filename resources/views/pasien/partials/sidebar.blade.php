<li>
    <a href="{{ route('pasien.dashboard') }}" class="{{ request()->routeIs('pasien.dashboard') ? 'active' : '' }}">
        <i class="bi bi-speedometer2"></i> Dashboard
    </a>
</li>
<li>
    <a href="{{ route('pasien.records.index') }}" class="{{ request()->routeIs('pasien.records.*') ? 'active' : '' }}">
        <i class="bi bi-file-medical"></i> Riwayat &amp; Verifikasi
    </a>
</li>
<li>
    <a href="{{ route('pasien.authorizations.index') }}"
        class="{{ request()->routeIs('pasien.authorizations.*') ? 'active' : '' }}">
        <i class="bi bi-shield-check"></i> Otorisasi Dokter
    </a>
</li>
<li>
    <a href="{{ route('account.edit') }}" class="{{ request()->routeIs('account.*') ? 'active' : '' }}">
        <i class="bi bi-person-circle"></i> Akun
    </a>
</li>
