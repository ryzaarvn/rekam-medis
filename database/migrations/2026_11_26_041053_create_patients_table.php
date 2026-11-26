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
        Schema::create('patients', function (Blueprint $table) {
            $table->id();
            $table->string('patient_code', 20)->unique(); // Contoh: PAT001
            $table->foreignId('user_id')
                ->unique()
                ->constrained('users')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->string('full_name', 100);
            $table->date('date_of_birth');
            $table->string('gender', 20);
            $table->text('address');
            $table->timestampsTz();
        });

        if (DB::getDriverName() === 'pgsql') {
            // Validasi nilai jenis kelamin agar konsisten di form Blade & database
            DB::statement("ALTER TABLE patients ADD CONSTRAINT chk_patients_gender CHECK (gender IN ('Laki-laki', 'Perempuan'))");

            // Aktifkan RLS agar aman dari akses publik Supabase Data API
            DB::statement('ALTER TABLE patients ENABLE ROW LEVEL SECURITY');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('patients');
    }
};