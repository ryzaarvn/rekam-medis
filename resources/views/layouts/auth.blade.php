<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Autentikasi') - E-REKAM MEDIS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/style.css') }}">
    @vite(['resources/js/app.js'])
</head>

<body data-page="@yield('page', 'auth')" data-chain-id="{{ config('blockchain.chain_id') }}">
    <div class="auth-container">
        <div class="auth-banner">
            <div class="auth-banner-content">
                <h1>Mengamankan data medis, memberdayakan pasien.</h1>
                <p>Sistem Rekam Medis Elektronik terverifikasi Blockchain Ethereum Sepolia.</p>
            </div>
        </div>

        <div class="auth-panel">
            <div class="auth-panel-body @yield('panel_body_class')">
                @yield('content')
            </div>

            <footer class="auth-footer">
                Tentang &bull; Bantuan &bull; Privasi &bull; Syarat &amp; Ketentuan &bull; 2026 E-Rekam Medis
            </footer>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>
