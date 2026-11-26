<?php

namespace App\Support;

use Illuminate\Support\Collection;

class CodeGenerator
{
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

        $nextNumber = $maxNumber + 1;

        return $prefix.str_pad((string) $nextNumber, $padLength, '0', STR_PAD_LEFT);
    }
}
