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
        Schema::create('doctors', function (Blueprint $table) {
            $table->id();
            $table->string('doctor_code', 20)->unique(); // Contoh: DOC001
            $table->foreignId('user_id')
                ->unique()
                ->constrained('users')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->string('full_name', 100);
            $table->string('specialization', 100);
            $table->string('str_number', 50)->unique();
            $table->boolean('is_registered_onchain')->default(false);
            $table->string('registration_tx_hash', 66)->nullable()->unique();
            $table->timestampsTz();
        });

        if (DB::getDriverName() === 'pgsql') {
            // Validasi format tx_hash Sepolia lowercase (0x + 64 karakter hex)
            DB::statement("ALTER TABLE doctors ADD CONSTRAINT chk_doctors_registration_tx_hash CHECK (registration_tx_hash IS NULL OR registration_tx_hash ~ '^0x[a-f0-9]{64}$')");

            // Aktifkan RLS agar aman dari akses publik Supabase Data API
            DB::statement('ALTER TABLE doctors ENABLE ROW LEVEL SECURITY');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('doctors');
    }
};