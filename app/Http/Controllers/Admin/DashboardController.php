<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Doctor;
use App\Models\LogBlockchain;
use App\Models\MedicalRecord;
use App\Models\Patient;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /**
     * Display admin analytics summary cards and recent Sepolia blockchain logs.
     */
    public function index(): View
    {
        $stats = [
            'total_doctors' => Doctor::query()->count(),
            'onchain_doctors' => Doctor::query()->registeredOnchain()->count(),
            'total_patients' => Patient::query()->count(),
            'total_records' => MedicalRecord::query()->count(),
        ];

        $recentLogs = LogBlockchain::query()
            ->with(['medicalRecord.patient', 'doctor.user'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        return view('admin.dashboard', [
            'stats' => $stats,
            'recentLogs' => $recentLogs,
        ]);
    }
}
