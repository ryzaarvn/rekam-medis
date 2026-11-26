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
        Schema::table('patients', function (Blueprint $table) {
            $table->boolean('is_registered_onchain')->default(false)->after('address');
            $table->string('registration_tx_hash', 66)->nullable()->unique()->after('is_registered_onchain');
        });

        if (DB::getDriverName() === 'pgsql') {
            // Validasi format tx_hash Sepolia lowercase (0x + 64 karakter hex)
            DB::statement("ALTER TABLE patients ADD CONSTRAINT chk_patients_registration_tx_hash CHECK (registration_tx_hash IS NULL OR registration_tx_hash ~ '^0x[a-f0-9]{64}$')");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE patients DROP CONSTRAINT IF EXISTS chk_patients_registration_tx_hash');
        }

        Schema::table('patients', function (Blueprint $table) {
            $table->dropUnique(['registration_tx_hash']);
            $table->dropColumn(['is_registered_onchain', 'registration_tx_hash']);
        });
    }
};
