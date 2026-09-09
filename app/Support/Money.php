<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Converting between integer cents and the decimals a person types.
 *
 * One implementation, because §4 rule 6 makes this the place a rounding error
 * becomes a wrong invoice, and it was already written twice — once for offering
 * prices and once for refunds — before this existed.
 *
 * Never float arithmetic: `(float) '19.99' * 100` is 1998.9999999999998, and
 * casting that to int truncates to 1998. A customer is then charged a penny
 * less, forever, and nothing ever fails loudly.
 */
class Money
{
    /** Cents to a decimal string — 27900 becomes "279.00". */
    public static function toDecimal(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return $sign.intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    /** As above, but an empty string for null — for an optional form field. */
    public static function toOptionalDecimal(?int $cents): string
    {
        return $cents === null ? '' : self::toDecimal($cents);
    }

    /**
     * A typed decimal back to cents — "279.5" becomes 27950.
     *
     * Parsed as a string, digit by digit. Anything that is not a digit is
     * dropped, so "$1,299.50" and "1299.5" both arrive as 129950.
     */
    public static function toCents(mixed $value): int
    {
        [$whole, $fraction] = array_pad(explode('.', trim((string) $value), 2), 2, '0');

        $negative = str_starts_with(trim((string) $value), '-');

        $whole = (int) preg_replace('/\D/', '', $whole);

        // Padded then truncated, so ".5" is fifty cents and ".567" is
        // fifty-six — never five, and never rounded up into money nobody typed.
        $fraction = (int) str_pad(
            substr((string) preg_replace('/\D/', '', $fraction), 0, 2),
            2,
            '0',
        );

        $cents = $whole * 100 + $fraction;

        return $negative ? -$cents : $cents;
    }

    /** Null in, null out — for an optional field that may be cleared. */
    public static function toOptionalCents(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : self::toCents($value);
    }

    /** For display outside a Filament money column — "$279.00". */
    public static function format(int $cents, string $currency = 'USD'): string
    {
        $symbol = match (strtoupper($currency)) {
            'USD' => '$',
            'GBP' => '£',
            'EUR' => '€',
            default => '',
        };

        return $symbol.number_format($cents / 100, 2);
    }
}
