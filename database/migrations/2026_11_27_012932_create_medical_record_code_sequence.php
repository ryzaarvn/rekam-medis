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
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE SEQUENCE IF NOT EXISTS medical_record_code_seq START WITH 1 INCREMENT BY 1');

            $row = DB::selectOne(
                "SELECT COALESCE(MAX(CAST(SUBSTRING(record_code FROM '^MR([0-9]+)$') AS BIGINT)), 0) AS max_num FROM medical_records WHERE record_code ~ '^MR[0-9]+$'",
            );
            $maxNumber = (int) ($row->max_num ?? 0);

            if ($maxNumber > 0) {
                DB::statement("SELECT setval('medical_record_code_seq', ?, true)", [$maxNumber]);
            } else {
                DB::statement("SELECT setval('medical_record_code_seq', 1, false)");
            }

            return;
        }

        Schema::create('code_sequences', function (Blueprint $table): void {
            $table->string('name', 100)->primary();
            $table->unsignedBigInteger('current_value')->default(0);
        });

        $maxNumber = 0;
        $existingCodes = DB::table('medical_records')->pluck('record_code');

        foreach ($existingCodes as $code) {
            if (preg_match('/\AMR([0-9]+)\z/', trim((string) $code), $matches) === 1) {
                $maxNumber = max($maxNumber, (int) $matches[1]);
            }
        }

        DB::table('code_sequences')->insert([
            'name' => 'medical_record_code',
            'current_value' => $maxNumber,
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP SEQUENCE IF EXISTS medical_record_code_seq');

            return;
        }

        Schema::dropIfExists('code_sequences');
    }
};
