<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\DoctorController as AdminDoctorController;
use App\Http\Controllers\Admin\PatientController as AdminPatientController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Dokter\DashboardController as DokterDashboardController;
use App\Http\Controllers\Dokter\MedicalRecordController as DokterMedicalRecordController;
use App\Http\Controllers\Pasien\AuthorizationController as PasienAuthorizationController;
use App\Http\Controllers\Pasien\DashboardController as PasienDashboardController;
use App\Http\Controllers\Pasien\RecordController as PasienRecordController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', [AuthController::class, 'showLogin'])->name('home');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:login')
        ->name('login.store');
});

Route::middleware('auth')->group(function (): void {
    Route::get('/dashboard', function (Request $request) {
        return redirect()->route($request->user()->dashboardRoute());
    })->name('dashboard');

    Route::get('/account', [AccountController::class, 'edit'])->name('account.edit');
    Route::put('/account/credentials', [AccountController::class, 'updateCredentials'])->name('account.credentials.update');
    Route::put('/account/password', [AccountController::class, 'updatePassword'])->name('account.password.update');

    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
});

Route::middleware(['auth', 'role:admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function (): void {
        Route::get('/dashboard', [AdminDashboardController::class, 'index'])->name('dashboard');

        Route::get('/doctors', [AdminDoctorController::class, 'index'])->name('doctors.index');
        Route::post('/doctors', [AdminDoctorController::class, 'store'])->name('doctors.store');
        Route::put('/doctors/{doctor}', [AdminDoctorController::class, 'update'])->name('doctors.update');
        Route::delete('/doctors/{doctor}', [AdminDoctorController::class, 'destroy'])->name('doctors.destroy');
        Route::post('/doctors/{doctor}/onchain-register', [AdminDoctorController::class, 'registerOnchain'])->name('doctors.onchain-register');
        Route::post('/doctors/{doctor}/onchain-deactivate', [AdminDoctorController::class, 'deactivateOnchain'])->name('doctors.onchain-deactivate');

        Route::get('/patients', [AdminPatientController::class, 'index'])->name('patients.index');
        Route::post('/patients', [AdminPatientController::class, 'store'])->name('patients.store');
        Route::put('/patients/{patient}', [AdminPatientController::class, 'update'])->name('patients.update');
        Route::delete('/patients/{patient}', [AdminPatientController::class, 'destroy'])->name('patients.destroy');
        Route::post('/patients/{patient}/onchain-register', [AdminPatientController::class, 'registerOnchain'])->name('patients.onchain-register');
        Route::post('/patients/{patient}/onchain-deactivate', [AdminPatientController::class, 'deactivateOnchain'])->name('patients.onchain-deactivate');
    });

Route::middleware(['auth', 'role:dokter'])
    ->prefix('dokter')
    ->name('dokter.')
    ->group(function (): void {
        Route::get('/dashboard', [DokterDashboardController::class, 'index'])->name('dashboard');
        Route::get('/records', [DokterMedicalRecordController::class, 'index'])->name('records.index');
        Route::get('/records/create', [DokterMedicalRecordController::class, 'create'])->name('records.create');
        Route::post('/records/prepare', [DokterMedicalRecordController::class, 'prepareStore'])->name('records.prepare');
        Route::post('/records', [DokterMedicalRecordController::class, 'store'])->name('records.store');
        Route::get('/records/{medicalRecord}/edit', [DokterMedicalRecordController::class, 'edit'])->name('records.edit');
        Route::post('/records/{medicalRecord}/prepare-update', [DokterMedicalRecordController::class, 'prepareUpdate'])->name('records.prepare-update');
        Route::put('/records/{medicalRecord}', [DokterMedicalRecordController::class, 'update'])->name('records.update');
    });

Route::middleware(['auth', 'role:pasien'])
    ->prefix('pasien')
    ->name('pasien.')
    ->group(function (): void {
        Route::get('/dashboard', [PasienDashboardController::class, 'index'])->name('dashboard');
        Route::get('/records', [PasienRecordController::class, 'index'])->name('records.index');
        Route::post('/records/{medicalRecord}/verify', [PasienRecordController::class, 'verify'])->name('records.verify');

        Route::get('/authorizations', [PasienAuthorizationController::class, 'index'])->name('authorizations.index');
        Route::post('/authorizations/{doctor}/grant', [PasienAuthorizationController::class, 'grant'])->name('authorizations.grant');
        Route::post('/authorizations/{doctor}/revoke', [PasienAuthorizationController::class, 'revoke'])->name('authorizations.revoke');
    });
