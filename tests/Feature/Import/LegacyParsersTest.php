<?php

declare(strict_types=1);

use App\Import\Support\LegacyMoney;
use App\Import\Support\LegacySchedule;

/* The pure legacy value parsers — money and the date/time schedule. */

it('parses WooCommerce prices to exact cents without floats', function (string $raw, int $cents): void {
    expect(LegacyMoney::toCents($raw))->toBe($cents);
})->with([
    ['149', 14900],
    ['149.5', 14950],
    ['149.50', 14950],
    ['99.99', 9999],
    ['0', 0],
    ['', 0],
    ['free', 0],
]);

it('builds a Pacific instant from the ACF date and time', function (): void {
    $result = LegacySchedule::parse('20230119', '10 AM PT | 01 PM ET');

    expect($result['timezone'])->toBe('America/Los_Angeles')
        ->and($result['at'])->not->toBeNull()
        ->and($result['at']->setTimezone('America/Los_Angeles')->format('Y-m-d H:i'))->toBe('2023-01-19 10:00')
        // The same instant reads as 1 PM Eastern — what the public page shows.
        ->and($result['at']->setTimezone('America/New_York')->format('H:i'))->toBe('13:00');
});

it('falls back to 10 AM PT when no time is stated', function (): void {
    $result = LegacySchedule::parse('20240705', 'Available All Day');

    expect($result['at']->setTimezone('America/Los_Angeles')->format('Y-m-d H:i'))->toBe('2024-07-05 10:00');
});

it('returns null for an unparseable date', function (): void {
    expect(LegacySchedule::parse('', '10 AM PT')['at'])->toBeNull()
        ->and(LegacySchedule::parse('not-a-date', null)['at'])->toBeNull();
});

it('reads a PM time correctly', function (): void {
    $result = LegacySchedule::parse('20250310', '02 PM PT');

    expect($result['at']->setTimezone('America/Los_Angeles')->format('H:i'))->toBe('14:00');
});
