<?php

namespace App\Scraping;

use App\Exceptions\PriceParseException;

/**
 * Converts a human price string into an integer count of minor currency units.
 *
 *   "EGP 12,999.00" -> 1299900
 *   "12999"         -> 1299900
 *   "$1,299.99"     -> 129999
 *
 * Rounds to the minor unit (half up), never truncates. Unparseable text throws;
 * it never returns 0.
 */
final class PriceParser
{
    public static function toMinorUnits(string $raw): int
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            throw new PriceParseException($raw, 'blank string');
        }

        // Keep only digits, dots and commas — drops currency codes ("EGP"),
        // symbols ("$", "£"), NBSP / thin spaces, and stray labels.
        $digits = preg_replace('/[^\d.,]/u', '', $trimmed) ?? '';

        // Western grouping: comma separates thousands, dot is the decimal point.
        $normalised = str_replace(',', '', $digits);

        if (preg_match('/^(\d+)(?:\.(\d+))?$/', $normalised, $m) !== 1) {
            throw new PriceParseException($raw, "no recognisable number in \"{$digits}\"");
        }

        $whole = $m[1];
        $fraction = $m[2] ?? '';

        // Round to two decimals from the third fractional digit. String work, no
        // floats — money must not go near binary fractions.
        $fraction = str_pad($fraction, 3, '0');
        $minorFraction = (int) substr($fraction, 0, 2);
        if ((int) $fraction[2] >= 5) {
            $minorFraction++;
        }

        return ((int) $whole * 100) + $minorFraction;
    }
}
