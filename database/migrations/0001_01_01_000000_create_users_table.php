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
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('username', 50)->unique();
            $table->string('email', 100)->nullable()->unique();
            $table->string('password');
            $table->string('role', 20);
            $table->string('wallet_address', 42)->unique();
            $table->rememberToken();
            $table->timestampsTz();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestampTz('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        if (DB::getDriverName() === 'pgsql') {
            // Validasi nilai role dan format alamat wallet lowercase (0x + 40 karakter hex)
            DB::statement("ALTER TABLE users ADD CONSTRAINT chk_users_role CHECK (role IN ('admin', 'dokter', 'pasien'))");
            DB::statement("ALTER TABLE users ADD CONSTRAINT chk_users_wallet_address CHECK (wallet_address ~ '^0x[a-f0-9]{40}$')");

            // Aktifkan RLS agar ketiga tabel aman dari akses publik Supabase Data API
            DB::statement('ALTER TABLE users ENABLE ROW LEVEL SECURITY');
            DB::statement('ALTER TABLE password_reset_tokens ENABLE ROW LEVEL SECURITY');
            DB::statement('ALTER TABLE sessions ENABLE ROW LEVEL SECURITY');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};