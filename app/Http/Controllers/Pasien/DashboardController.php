<?php

namespace App\Http\Controllers\Pasien;

use App\Http\Controllers\Controller;
use App\Models\Authorization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /**
     * Display the patient's analytics summary cards, active doctor authorizations, and recent medical records.
     */
    public function index(Request $request): View
    {
        $patient = $request->user()->patient()->with('user')->firstOrFail();

        $totalRecordsCount = $patient->medicalRecords()->count();

        $activeAuthorizations = $patient->is_registered_onchain
            ? Authorization::query()
                ->with(['doctor.user'])
                ->authorized()
                ->where('patient_id', $patient->id)
                ->whereHas('doctor', fn (Builder $query): Builder => $query->registeredOnchain())
                ->latest('updated_at')
                ->orderByDesc('id')
                ->get()
            : collect();

        $authorizedDoctorsCount = $activeAuthorizations->count();

        $recentRecords = $patient->medicalRecords()
            ->with(['doctor', 'latestLogBlockchain'])
            ->orderByDesc('record_date')
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        $latestRecord = $recentRecords->first();

        return view('pasien.dashboard', [
            'patient' => $patient,
            'totalRecordsCount' => $totalRecordsCount,
            'authorizedDoctorsCount' => $authorizedDoctorsCount,
            'activeAuthorizations' => $activeAuthorizations,
            'recentRecords' => $recentRecords,
            'latestRecord' => $latestRecord,
        ]);
    }
}
