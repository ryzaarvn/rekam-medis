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
        Schema::create('authorizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')
                ->constrained('patients')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->foreignId('doctor_id')
                ->constrained('doctors')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->boolean('is_authorized')->default(true);
            $table->string('tx_hash', 66)->nullable();
            $table->timestampsTz();

            $table->unique(['patient_id', 'doctor_id'], 'uk_authorizations_patient_doctor');
            $table->index('doctor_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            // Validasi format tx_hash Sepolia lowercase (0x + 64 karakter hex)
            DB::statement("ALTER TABLE authorizations ADD CONSTRAINT chk_authorizations_tx_hash CHECK (tx_hash IS NULL OR tx_hash ~ '^0x[a-f0-9]{64}$')");

            // Aktifkan RLS agar aman dari akses publik Supabase Data API
            DB::statement('ALTER TABLE authorizations ENABLE ROW LEVEL SECURITY');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('authorizations');
    }
};