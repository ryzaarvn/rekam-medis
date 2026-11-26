<li>
    <a href="{{ route('admin.dashboard') }}" class="{{ request()->routeIs('admin.dashboard') ? 'active' : '' }}">
        <i class="bi bi-speedometer2"></i> Dashboard
    </a>
</li>
<li>
    <a href="{{ route('admin.doctors.index') }}" class="{{ request()->routeIs('admin.doctors.*') ? 'active' : '' }}">
        <i class="bi bi-person-badge"></i> Data Dokter
    </a>
</li>
<li>
    <a href="{{ route('admin.patients.index') }}" class="{{ request()->routeIs('admin.patients.*') ? 'active' : '' }}">
        <i class="bi bi-people"></i> Data Pasien
    </a>
</li>
<li>
    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit" class="sidebar-logout-btn">
            <i class="bi bi-box-arrow-left"></i> Logout
        </button>
    </form>
</li>
