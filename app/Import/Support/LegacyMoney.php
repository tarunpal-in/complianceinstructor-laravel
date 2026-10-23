<?php

declare(strict_types=1);

namespace App\Import\Support;

/**
 * A WooCommerce price string ("149", "149.5", "149.50") → integer cents.
 *
 * Done by splitting on the decimal point and padding, never by multiplying a
 * float: §4 rule 6, and §14 #1's hidden `final_amount` lesson. `(int)(149.5 *
 * 100)` is 14949 on some platforms; string handling is exact.
 */
final class LegacyMoney
{
    public static function toCents(?string $raw): int
    {
        $value = trim((string) $raw);

        if ($value === '' || ! is_numeric($value)) {
            return 0;
        }

        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '+-');

        [$whole, $frac] = array_pad(explode('.', $value, 2), 2, '0');

        // Two decimal places: pad "5" → "50", truncate "509" → "50".
        $frac = substr(str_pad($frac, 2, '0'), 0, 2);

        $cents = (int) $whole * 100 + (int) $frac;

        return $negative ? -$cents : $cents;
    }
}
