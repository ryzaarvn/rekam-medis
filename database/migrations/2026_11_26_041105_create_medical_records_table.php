<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('medical_records', function (Blueprint $table) {
            $table->id();
            $table->string('record_code', 20)->unique(); // Contoh: MR001 (juga dipakai di Smart Contract)
            $table->foreignId('patient_id')
                ->constrained('patients')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->foreignId('doctor_id')
                ->constrained('doctors')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->date('record_date');
            $table->text('complaint');
            $table->text('diagnosis');
            $table->text('treatment');
            $table->text('notes')->nullable();
            $table->string('current_hash', 66);
            $table->timestampsTz();

            $table->index('patient_id');
            $table->index('doctor_id');
            $table->index('record_date');
        });

        if (DB::getDriverName() === 'pgsql') {
            // Validasi format SHA-256 hash lowercase dengan awalan 0x (66 karakter)
            DB::statement("ALTER TABLE medical_records ADD CONSTRAINT chk_medical_records_current_hash CHECK (current_hash ~ '^0x[a-f0-9]{64}$')");

            // Aktifkan RLS agar data medis aman dari akses publik Supabase Data API
            DB::statement('ALTER TABLE medical_records ENABLE ROW LEVEL SECURITY');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('medical_records');
    }
};