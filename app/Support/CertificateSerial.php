<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Certificate;
use Random\RandomException;
use RuntimeException;

/**
 * The number printed on a certificate, and the one an auditor types to check it.
 *
 * Two requirements pulling opposite ways. It has to be **unguessable**, because
 * §8 puts it behind a public verification URL and a sequential serial would let
 * anybody walk the register of who is certified in what. And it has to be
 * **transcribable**, because somebody will read it off a printed page or a PDF
 * and type it into a form.
 *
 * Hence a random serial in groups of four from an alphabet with the ambiguous
 * characters removed: no O against 0, no I or L against 1. `CI-7K2M-9QXP-3DVH`
 * survives being read aloud down a phone line, and at 31^12 there is nothing to
 * enumerate.
 */
final class CertificateSerial
{
    /** No 0/O, 1/I/L — the pairs that get mistyped off a printed page. */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    private const GROUPS = 3;

    private const GROUP_SIZE = 4;

    /**
     * Enough attempts to step over a collision without spinning if something is
     * badly wrong — the same shape of guard as GenerateOrderNumber.
     */
    private const MAX_ATTEMPTS = 20;

    /**
     * A serial nothing else holds.
     *
     * Checked against the table as well as being random, because "practically
     * never collides" is not the same as "cannot", and `serial` is UNIQUE — an
     * unchecked collision would surface as a failed issue rather than a retried
     * one. The index is the backstop; this is the polite path.
     */
    public static function generate(): string
    {
        foreach (range(1, self::MAX_ATTEMPTS) as $ignored) {
            $serial = self::compose();

            if (! Certificate::query()->where('serial', $serial)->exists()) {
                return $serial;
            }
        }

        throw new RuntimeException('Could not find an unused certificate serial.');
    }

    /** "CI-7K2M-9QXP-3DVH". */
    private static function compose(): string
    {
        $groups = [];

        foreach (range(1, self::GROUPS) as $ignored) {
            $groups[] = self::randomGroup();
        }

        return 'CI-'.implode('-', $groups);
    }

    private static function randomGroup(): string
    {
        $group = '';
        $length = strlen(self::ALPHABET);

        foreach (range(1, self::GROUP_SIZE) as $ignored) {
            try {
                // random_int, not rand: this is the only thing standing between
                // a verification URL and somebody guessing their way to one.
                $group .= self::ALPHABET[random_int(0, $length - 1)];
            } catch (RandomException $exception) {
                throw new RuntimeException('No source of randomness for a certificate serial.', previous: $exception);
            }
        }

        return $group;
    }

    /** Whether a string could be one of ours, for a verification form. */
    public static function looksValid(string $serial): bool
    {
        $pattern = sprintf(
            '/^CI(-[%s]{%d}){%d}$/',
            preg_quote(self::ALPHABET, '/'),
            self::GROUP_SIZE,
            self::GROUPS,
        );

        return preg_match($pattern, strtoupper(trim($serial))) === 1;
    }
}
