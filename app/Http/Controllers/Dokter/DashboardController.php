<?php

namespace App\Http\Controllers\Dokter;

use App\Http\Controllers\Controller;
use App\Models\Authorization;
use App\Models\LogBlockchain;
use App\Models\MedicalRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /**
     * Display the doctor's analytics summary cards and recent authorized on-chain activities.
     */
    public function index(Request $request): View
    {
        $doctor = $request->user()->doctor()->with('user')->firstOrFail();

        if (! $doctor->is_registered_onchain) {
            return view('dokter.dashboard', [
                'doctor' => $doctor,
                'authorizedPatientsCount' => 0,
                'accessibleRecordsCount' => 0,
                'myLogsTotalCount' => 0,
                'myCreateLogsCount' => 0,
                'myUpdateLogsCount' => 0,
                'recentAuthorizedPatients' => collect(),
                'recentBlockchainLogs' => collect(),
            ]);
        }

        $authorizedPatientIds = $doctor->authorizedPatients()
            ->registeredOnchain()
            ->pluck('patients.id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $authorizedPatientsCount = count($authorizedPatientIds);

        if ($authorizedPatientsCount === 0) {
            return view('dokter.dashboard', [
                'doctor' => $doctor,
                'authorizedPatientsCount' => 0,
                'accessibleRecordsCount' => 0,
                'myLogsTotalCount' => 0,
                'myCreateLogsCount' => 0,
                'myUpdateLogsCount' => 0,
                'recentAuthorizedPatients' => collect(),
                'recentBlockchainLogs' => collect(),
            ]);
        }

        $accessibleRecordsCount = MedicalRecord::query()
            ->whereIn('patient_id', $authorizedPatientIds)
            ->count();

        $logCounts = LogBlockchain::query()
            ->where('doctor_id', $doctor->id)
            ->whereHas('medicalRecord', fn (Builder $query): Builder => $query->whereIn('patient_id', $authorizedPatientIds))
            ->selectRaw('action_type, COUNT(*) as aggregate')
            ->groupBy('action_type')
            ->pluck('aggregate', 'action_type');

        $myCreateLogsCount = (int) ($logCounts[LogBlockchain::ACTION_CREATE] ?? 0);
        $myUpdateLogsCount = (int) ($logCounts[LogBlockchain::ACTION_UPDATE] ?? 0);
        $myLogsTotalCount = $myCreateLogsCount + $myUpdateLogsCount;

        $recentAuthorizedPatients = Authorization::query()
            ->with(['patient.user'])
            ->authorized()
            ->where('doctor_id', $doctor->id)
            ->whereIn('patient_id', $authorizedPatientIds)
            ->latest('updated_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        $recentBlockchainLogs = LogBlockchain::query()
            ->with(['medicalRecord.patient'])
            ->where('doctor_id', $doctor->id)
            ->whereHas('medicalRecord', fn (Builder $query): Builder => $query->whereIn('patient_id', $authorizedPatientIds))
            ->latest('created_at')
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        return view('dokter.dashboard', [
            'doctor' => $doctor,
            'authorizedPatientsCount' => $authorizedPatientsCount,
            'accessibleRecordsCount' => $accessibleRecordsCount,
            'myLogsTotalCount' => $myLogsTotalCount,
            'myCreateLogsCount' => $myCreateLogsCount,
            'myUpdateLogsCount' => $myUpdateLogsCount,
            'recentAuthorizedPatients' => $recentAuthorizedPatients,
            'recentBlockchainLogs' => $recentBlockchainLogs,
        ]);
    }
}
