<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CodeGenerator
{
    private const MEDICAL_RECORD_SEQUENCE_NAME = 'medical_record_code';

    /**
     * Generate the next zero-padded sequential ASCII code (e.g. DOC001, PAT001, MR001).
     *
     * @param  Collection<int, string>|iterable<int, string>  $existingCodes
     */
    public static function nextCode(string $prefix, iterable $existingCodes, int $padLength = 3): string
    {
        $maxNumber = 0;
        $pattern = '/\A'.preg_quote($prefix, '/').'(\d+)\z/';

        foreach ($existingCodes as $code) {
            if (preg_match($pattern, trim((string) $code), $matches) === 1) {
                $maxNumber = max($maxNumber, (int) $matches[1]);
            }
        }

        return self::formatCode($prefix, $maxNumber + 1, $padLength);
    }

    /**
     * Atomically generate the next unique medical record code using a PostgreSQL sequence or code_sequences fallback.
     */
    public static function nextMedicalRecordCode(int $padLength = 3): string
    {
        if (DB::getDriverName() === 'pgsql') {
            do {
                $row = DB::selectOne("SELECT nextval('medical_record_code_seq') AS seq");
                $code = self::formatCode('MR', (int) $row->seq, $padLength);
            } while (DB::table('medical_records')->where('record_code', $code)->exists());

            return $code;
        }

        return DB::transaction(function () use ($padLength): string {
            DB::table('code_sequences')->insertOrIgnore([
                'name' => self::MEDICAL_RECORD_SEQUENCE_NAME,
                'current_value' => 0,
            ]);

            do {
                DB::table('code_sequences')
                    ->where('name', self::MEDICAL_RECORD_SEQUENCE_NAME)
                    ->lockForUpdate()
                    ->increment('current_value');

                $seq = (int) DB::table('code_sequences')
                    ->where('name', self::MEDICAL_RECORD_SEQUENCE_NAME)
                    ->value('current_value');

                $code = self::formatCode('MR', $seq, $padLength);
            } while (DB::table('medical_records')->where('record_code', $code)->exists());

            return $code;
        });
    }

    /**
     * Format a sequential integer with prefix and zero-padding.
     */
    private static function formatCode(string $prefix, int $number, int $padLength = 3): string
    {
        return $prefix.str_pad((string) $number, $padLength, '0', STR_PAD_LEFT);
    }
}
