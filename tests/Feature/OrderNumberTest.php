<?php

declare(strict_types=1);

use App\Actions\Orders\GenerateOrderNumber;
use App\Enums\OrderNumberReset;
use App\Enums\UserRole;
use App\Filament\Pages\Settings;
use App\Models\Order;
use App\Models\OrderNumberSequence;
use App\Models\Setting;
use App\Support\OrderNumberFormat;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->seed(SettingSeeder::class);
});

/** Point the settings at a format without going through the form. */
function useFormat(
    string $pattern,
    int $padding,
    OrderNumberReset $reset,
    int $yearStartMonth = 1,
): void {
    Setting::set('orders.number_format', $pattern, 'string');
    Setting::set('orders.number_padding', $padding, 'integer');
    Setting::set('orders.number_reset', $reset->value, 'string');
    Setting::set('orders.year_start_month', $yearStartMonth, 'integer');
}

// Rendering -------------------------------------------------------------------

it('renders the format the client asked for', function (): void {
    $format = new OrderNumberFormat('{YYYY}-{MM}-{SEQ}', 3, OrderNumberReset::Monthly);

    expect($format->render(1, Carbon::parse('2024-10-05')))->toBe('2024-10-001')
        ->and($format->render(42, Carbon::parse('2024-10-05')))->toBe('2024-10-042');
});

it('treats anything outside a token as literal, so a prefix needs no field', function (): void {
    $format = new OrderNumberFormat('CI-{YY}/{SEQ}', 4, OrderNumberReset::Yearly);

    expect($format->render(7, Carbon::parse('2026-03-01')))->toBe('CI-26/0007');
});

// The accounting year ---------------------------------------------------------

/**
 * The point of the setting. With a 1 April start the sequence restarts in
 * April, so every order from April to the following March has to carry the year
 * that began in April — otherwise January to March would repeat the numbers
 * already issued the previous spring.
 */
it('gives every month of an April-to-March year the same year token', function (
    string $date,
    string $expected,
): void {
    $format = new OrderNumberFormat('CI-{YYYY}-{SEQ}', 4, OrderNumberReset::Yearly, yearStartMonth: 4);

    expect($format->render(1, Carbon::parse($date)))->toBe($expected);
})->with([
    'the day it opens' => ['2024-04-01', 'CI-2024-0001'],
    'mid-year' => ['2024-12-31', 'CI-2024-0001'],
    'january still belongs to it' => ['2025-01-15', 'CI-2024-0001'],
    'the day it closes' => ['2025-03-31', 'CI-2024-0001'],
    'the next one opens' => ['2025-04-01', 'CI-2025-0001'],
]);

it('writes the span with {FY}', function (): void {
    $april = new OrderNumberFormat('CI-{FY}/{SEQ}', 3, OrderNumberReset::Yearly, yearStartMonth: 4);

    expect($april->render(1, Carbon::parse('2024-06-01')))->toBe('CI-2024-25/001')
        ->and($april->render(1, Carbon::parse('2025-02-01')))->toBe('CI-2024-25/001');

    // A calendar year has no span to write, so it collapses to the year.
    $calendar = new OrderNumberFormat('CI-{FY}/{SEQ}', 3, OrderNumberReset::Yearly);

    expect($calendar->render(1, Carbon::parse('2024-06-01')))->toBe('CI-2024/001');
});

it('restarts the sequence in April rather than January', function (): void {
    useFormat('CI-{YYYY}-{SEQ}', 4, OrderNumberReset::Yearly, yearStartMonth: 4);

    Carbon::setTestNow('2025-03-31 18:00');
    expect(Order::nextNumber())->toBe('CI-2024-0001')
        ->and(Order::nextNumber())->toBe('CI-2024-0002');

    Carbon::setTestNow('2025-04-01 09:00');
    expect(Order::nextNumber())->toBe('CI-2025-0001');
});

it('keeps a calendar year when the accounting year starts in January', function (): void {
    useFormat('CI-{YYYY}-{SEQ}', 4, OrderNumberReset::Yearly);

    Carbon::setTestNow('2025-12-31');
    expect(Order::nextNumber())->toBe('CI-2025-0001');

    Carbon::setTestNow('2026-01-01');
    expect(Order::nextNumber())->toBe('CI-2026-0001');
});

// The guards ------------------------------------------------------------------

/**
 * A restart period the format cannot express produces a duplicate, and
 * `orders.number` is UNIQUE — so it would surface as a failed checkout rather
 * than a cosmetic problem.
 */
it('refuses a restart period the format cannot express', function (
    string $pattern,
    OrderNumberReset $reset,
    bool $valid,
): void {
    expect((new OrderNumberFormat($pattern, 3, $reset))->isValid())->toBe($valid);
})->with([
    'monthly with year and month' => ['{YYYY}{MM}-{SEQ}', OrderNumberReset::Monthly, true],
    'monthly with the span and month' => ['{FY}-{MM}-{SEQ}', OrderNumberReset::Monthly, true],
    'monthly without the year' => ['{MM}-{SEQ}', OrderNumberReset::Monthly, false],
    'monthly without the month' => ['{YYYY}-{SEQ}', OrderNumberReset::Monthly, false],
    'yearly with a two-digit year' => ['CI-{YY}-{SEQ}', OrderNumberReset::Yearly, true],
    'yearly without any year' => ['CI-{SEQ}', OrderNumberReset::Yearly, false],
    'never resetting needs no date' => ['INV-{SEQ}', OrderNumberReset::Never, true],
]);

it('refuses a format with no sequence', function (): void {
    $problems = (new OrderNumberFormat('{YYYY}-{MM}', 3, OrderNumberReset::Monthly))->problems();

    expect($problems)->toContain('The format must include {SEQ}, or every order would get the same number.');
});

it('names an unknown token rather than rendering it literally', function (): void {
    $problems = (new OrderNumberFormat('{YEAR}-{SEQ}', 3, OrderNumberReset::Never))->problems();

    expect($problems[0])->toContain('{YEAR} is not a token');
});

it('refuses padding outside a sane range', function (): void {
    expect((new OrderNumberFormat('{SEQ}', 0, OrderNumberReset::Never))->isValid())->toBeFalse()
        ->and((new OrderNumberFormat('{SEQ}', 99, OrderNumberReset::Never))->isValid())->toBeFalse()
        ->and((new OrderNumberFormat('{SEQ}', 4, OrderNumberReset::Never))->isValid())->toBeTrue();
});

it('offers no daily restart', function (): void {
    expect(OrderNumberReset::tryFrom('daily'))->toBeNull()
        ->and(array_keys(OrderNumberReset::options()))->toBe(['never', 'monthly', 'yearly']);
});

// Issuing ---------------------------------------------------------------------

it('hands out consecutive numbers', function (): void {
    useFormat('{YYYY}-{MM}-{SEQ}', 3, OrderNumberReset::Monthly);

    Carbon::setTestNow('2024-10-05 09:00');

    expect(Order::nextNumber())->toBe('2024-10-001')
        ->and(Order::nextNumber())->toBe('2024-10-002')
        ->and(Order::nextNumber())->toBe('2024-10-003');
});

it('restarts the sequence when the month rolls over', function (): void {
    useFormat('{YYYY}-{MM}-{SEQ}', 3, OrderNumberReset::Monthly);

    Carbon::setTestNow('2024-10-31 23:59');
    expect(Order::nextNumber())->toBe('2024-10-001');

    Carbon::setTestNow('2024-11-01 00:01');
    expect(Order::nextNumber())->toBe('2024-11-001');
});

it('keeps one continuous sequence across years when set to never restart', function (): void {
    useFormat('INV-{SEQ}', 5, OrderNumberReset::Never);

    Carbon::setTestNow('2024-10-05');
    expect(Order::nextNumber())->toBe('INV-00001');

    Carbon::setTestNow('2025-02-11');
    expect(Order::nextNumber())->toBe('INV-00002');
});

/**
 * A number that has been on an invoice can never be reissued, so the counter
 * is stored rather than derived from the highest existing order.
 */
it('does not rewind when an order is deleted', function (): void {
    useFormat('CI-{YYYY}-{SEQ}', 4, OrderNumberReset::Yearly);

    $first = Order::factory()->create(['number' => Order::nextNumber()]);
    Order::factory()->create(['number' => Order::nextNumber()]);

    $first->forceDelete();

    expect(Order::nextNumber())->toBe('CI-'.now()->format('Y').'-0003');
});

it('carries the sequence across a format change', function (): void {
    useFormat('CI-{YYYY}-{SEQ}', 4, OrderNumberReset::Yearly);
    Carbon::setTestNow('2026-05-01');

    expect(Order::nextNumber())->toBe('CI-2026-0001')
        ->and(Order::nextNumber())->toBe('CI-2026-0002');

    useFormat('ORD/{YYYY}/{SEQ}', 3, OrderNumberReset::Yearly);

    expect(Order::nextNumber())->toBe('ORD/2026/003');
});

/**
 * Switching to an April year in February moves today's orders into the previous
 * accounting year, whose counter starts at 1 — while numbers carrying that year
 * have already been issued. Rather than fail the checkout, step over what is
 * taken.
 */
it('skips numbers that are already in use', function (): void {
    useFormat('CI-{YYYY}-{SEQ}', 4, OrderNumberReset::Yearly);
    Carbon::setTestNow('2025-02-10');

    Order::factory()->create(['number' => 'CI-2024-0001']);
    Order::factory()->create(['number' => 'CI-2024-0002']);

    // Same dates, now read as the accounting year that began April 2024.
    useFormat('CI-{YYYY}-{SEQ}', 4, OrderNumberReset::Yearly, yearStartMonth: 4);

    expect(Order::nextNumber())->toBe('CI-2024-0003');
});

/**
 * The live site is WordPress + WooCommerce and its orders carry numbers
 * already. The import has to continue past them rather than collide.
 */
it('resumes from a number set by hand', function (): void {
    useFormat('CI-{YYYY}-{SEQ}', 4, OrderNumberReset::Yearly);

    GenerateOrderNumber::setNext(4821);

    expect(Order::nextNumber())->toBe('CI-'.now()->format('Y').'-4821')
        ->and(Order::nextNumber())->toBe('CI-'.now()->format('Y').'-4822');
});

/**
 * The settings screen refuses an invalid combination, so this is only reachable
 * by editing the row directly — but a bad row must not mint colliding numbers.
 */
it('falls back to the default when the stored format is unusable', function (): void {
    useFormat('{SEQ}', 3, OrderNumberReset::Monthly);

    expect(Order::nextNumber())->toBe('CI-'.now()->format('Y').'-0001');
});

// The settings screen ---------------------------------------------------------

it('saves an April-to-March accounting year', function (): void {
    Carbon::setTestNow('2025-02-10');

    Livewire\Livewire::actingAs(userWithRole(UserRole::SuperAdmin))
        ->test(Settings::class)
        ->fillForm([
            'order_number_format' => 'CI-{FY}-{SEQ}',
            'order_number_padding' => 4,
            'order_number_reset' => OrderNumberReset::Yearly->value,
            'order_year_start_month' => 4,
            'order_number_next' => 1,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Setting::get('orders.year_start_month'))->toBe(4)
        ->and(Order::nextNumber())->toBe('CI-2024-25-0001');
});

it('refuses to save a format that would produce duplicates', function (): void {
    Livewire\Livewire::actingAs(userWithRole(UserRole::SuperAdmin))
        ->test(Settings::class)
        ->fillForm([
            'order_number_format' => '{YYYY}-{SEQ}',
            'order_number_padding' => 3,
            'order_number_reset' => OrderNumberReset::Monthly->value,
            'order_year_start_month' => 1,
            'order_number_next' => 1,
        ])
        ->call('save')
        ->assertHasFormErrors(['order_number_format']);

    expect(Setting::get('orders.number_format'))->toBe('CI-{YYYY}-{SEQ}');
});

it('sets the next number from the settings screen', function (): void {
    Livewire\Livewire::actingAs(userWithRole(UserRole::SuperAdmin))
        ->test(Settings::class)
        ->fillForm([
            'order_number_format' => 'CI-{YYYY}-{SEQ}',
            'order_number_padding' => 4,
            'order_number_reset' => OrderNumberReset::Yearly->value,
            'order_year_start_month' => 1,
            'order_number_next' => 500,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(GenerateOrderNumber::peek())->toBe(500)
        ->and(Order::nextNumber())->toBe('CI-'.now()->format('Y').'-0500');
});

it('previews the next three numbers rather than one', function (): void {
    $format = new OrderNumberFormat('{YYYY}-{MM}-{SEQ}', 3, OrderNumberReset::Monthly);

    expect($format->preview(1, 3, Carbon::parse('2024-10-05')))
        ->toBe(['2024-10-001', '2024-10-002', '2024-10-003']);
});

it('keeps one counter row per period', function (): void {
    useFormat('{YYYY}-{MM}-{SEQ}', 3, OrderNumberReset::Monthly);

    Carbon::setTestNow('2024-10-05');
    Order::nextNumber();
    Order::nextNumber();

    Carbon::setTestNow('2024-11-06');
    Order::nextNumber();

    expect(OrderNumberSequence::query()->count())->toBe(2)
        ->and(OrderNumberSequence::query()->where('period_key', 'm2024-10')->value('next_value'))->toBe(3)
        ->and(OrderNumberSequence::query()->where('period_key', 'm2024-11')->value('next_value'))->toBe(2);
});
