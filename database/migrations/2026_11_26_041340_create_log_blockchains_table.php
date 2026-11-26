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
        Schema::create('log_blockchains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medical_record_id')
                ->constrained('medical_records')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->foreignId('doctor_id')
                ->constrained('doctors')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->string('onchain_hash', 66);
            $table->string('tx_hash', 66)->unique();
            $table->unsignedBigInteger('block_number')->nullable();
            $table->string('action_type', 20);
            $table->timestampTz('created_at')->useCurrent();

            $table->index('medical_record_id');
            $table->index('doctor_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            // Validasi format hash & tx_hash lowercase (0x + 64 karakter hex) serta tipe aksi
            DB::statement("ALTER TABLE log_blockchains ADD CONSTRAINT chk_log_blockchains_onchain_hash CHECK (onchain_hash ~ '^0x[a-f0-9]{64}$')");
            DB::statement("ALTER TABLE log_blockchains ADD CONSTRAINT chk_log_blockchains_tx_hash CHECK (tx_hash ~ '^0x[a-f0-9]{64}$')");
            DB::statement("ALTER TABLE log_blockchains ADD CONSTRAINT chk_log_blockchains_action_type CHECK (action_type IN ('CREATE', 'UPDATE'))");

            // Aktifkan RLS agar log transaksi aman dari akses publik Supabase Data API
            DB::statement('ALTER TABLE log_blockchains ENABLE ROW LEVEL SECURITY');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('log_blockchains');
    }
};