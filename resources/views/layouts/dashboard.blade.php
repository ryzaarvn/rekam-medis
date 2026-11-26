<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') - E-REKAM MEDIS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/style.css') }}">
    @vite(['resources/js/app.js'])
</head>

<body
    data-page="@yield('page', 'dashboard')"
    data-expected-wallet="{{ auth()->user()?->wallet_address }}"
    data-chain-id="{{ config('blockchain.chain_id') }}"
>
    <div class="app-wrapper">
        <aside class="sidebar" id="appSidebar">
            <div class="sidebar-brand">E-REKAM MEDIS</div>
            <ul class="sidebar-menu">
                @yield('sidebar')
            </ul>
        </aside>

        <div class="main-panel">
            <header class="topbar">
                <div class="d-flex align-items-center gap-2">
                    <button type="button" class="btn btn-sm text-white border-0 p-1" id="sidebarToggleBtn" aria-label="Toggle Sidebar">
                        <i class="bi bi-list fs-5"></i>
                    </button>
                    <span class="small fw-semibold d-none d-sm-inline">@yield('topbar_title', 'Portal Rekam Medis Sepolia')</span>
                </div>

                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <div id="walletBar" class="d-flex align-items-center gap-2 flex-wrap">
                        <button type="button" id="btnConnectMetaMask" class="btn btn-light btn-sm fw-semibold text-dark">
                            <i class="bi bi-wallet2 me-1"></i> Hubungkan MetaMask
                        </button>

                        <div id="walletConnectedInfo" class="wallet-status-pill d-none">
                            <i class="bi bi-wallet2"></i>
                            <span id="walletActiveAddress" class="font-monospace" title="">0x...</span>
                            <span id="walletNetworkBadge" class="badge bg-secondary">Memeriksa...</span>
                            <button type="button" id="btnSwitchSepolia" class="btn btn-warning btn-sm py-0 px-2 fw-semibold d-none" style="font-size: 0.72rem;">
                                Switch ke Sepolia
                            </button>
                        </div>
                    </div>

                    <div class="small fw-semibold d-flex align-items-center gap-2 ps-2 border-start border-light border-opacity-25">
                        <i class="bi bi-person-circle"></i>
                        <span>@yield('user_display', auth()->user()->username)</span>
                        <span class="badge bg-dark bg-opacity-50 font-monospace" title="{{ auth()->user()->wallet_address }}">
                            {{ substr(auth()->user()->wallet_address, 0, 6) }}...{{ substr(auth()->user()->wallet_address, -4) }}
                        </span>
                    </div>
                </div>
            </header>

            <main class="content-area">
                <div id="walletMismatchBanner" class="alert alert-warning d-none align-items-center justify-content-between gap-2" role="alert">
                    <div class="small">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <strong>Akun MetaMask Tidak Cocok:</strong>
                        Wallet MetaMask aktif (<code id="mismatchActiveWallet">-</code>) berbeda dengan wallet akun login (<code id="mismatchExpectedWallet">{{ auth()->user()->wallet_address }}</code>).
                        Silakan ganti akun di ekstensi MetaMask sebelum mengirim transaksi blockchain.
                    </div>
                </div>

                <div id="globalBlockchainAlert" class="alert d-none" role="alert"></div>

                @if (session('status'))
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="bi bi-check-circle-fill me-2"></i>{{ session('status') }}
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
                    </div>
                @endif

                @if (session('error'))
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="bi bi-exclamation-octagon-fill me-2"></i>{{ session('error') }}
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
                    </div>
                @endif

                @if ($errors->any())
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <div class="fw-semibold mb-1"><i class="bi bi-exclamation-triangle-fill me-1"></i> Terdapat kesalahan input:</div>
                        <ul class="mb-0 small">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
                    </div>
                @endif

                @yield('content')
            </main>
        </div>
    </div>

    @stack('modals')
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    @stack('scripts')
</body>

</html>
