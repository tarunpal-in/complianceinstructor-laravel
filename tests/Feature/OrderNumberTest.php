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
function useFormat(string $pattern, int $padding, OrderNumberReset $reset): void
{
    Setting::set('orders.number_format', $pattern, 'string');
    Setting::set('orders.number_padding', $padding, 'integer');
    Setting::set('orders.number_reset', $reset->value, 'string');
}

// Rendering -------------------------------------------------------------------

it('renders the format the client asked for', function (): void {
    $format = new OrderNumberFormat('{YYYY}-{MM}-{DD}-{SEQ}', 3, OrderNumberReset::Daily);

    expect($format->render(1, Carbon::parse('2024-10-05')))->toBe('2024-10-05-001')
        ->and($format->render(42, Carbon::parse('2024-10-05')))->toBe('2024-10-05-042');
});

it('treats anything outside a token as literal, so a prefix needs no field', function (): void {
    $format = new OrderNumberFormat('CI-{YY}/{SEQ}', 4, OrderNumberReset::Yearly);

    expect($format->render(7, Carbon::parse('2026-03-01')))->toBe('CI-26/0007');
});

// The guards ------------------------------------------------------------------

/**
 * The half of this that is easy to get wrong. A daily restart with no day in
 * the format produces a duplicate on the second day, and `orders.number` is
 * UNIQUE — so it surfaces as a failed checkout, not a cosmetic problem.
 */
it('refuses a restart period the format cannot express', function (
    string $pattern,
    OrderNumberReset $reset,
    bool $valid,
): void {
    expect((new OrderNumberFormat($pattern, 3, $reset))->isValid())->toBe($valid);
})->with([
    'daily with a full date' => ['{YYYY}-{MM}-{DD}-{SEQ}', OrderNumberReset::Daily, true],
    'daily without the day' => ['{YYYY}-{MM}-{SEQ}', OrderNumberReset::Daily, false],
    'daily without the year' => ['{MM}-{DD}-{SEQ}', OrderNumberReset::Daily, false],
    'monthly with year and month' => ['{YYYY}{MM}-{SEQ}', OrderNumberReset::Monthly, true],
    'monthly without the year' => ['{MM}-{SEQ}', OrderNumberReset::Monthly, false],
    'yearly with a two-digit year' => ['CI-{YY}-{SEQ}', OrderNumberReset::Yearly, true],
    'never resetting needs no date' => ['INV-{SEQ}', OrderNumberReset::Never, true],
]);

it('refuses a format with no sequence', function (): void {
    $problems = (new OrderNumberFormat('{YYYY}-{MM}-{DD}', 3, OrderNumberReset::Daily))->problems();

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

// Issuing ---------------------------------------------------------------------

it('hands out consecutive numbers', function (): void {
    useFormat('{YYYY}-{MM}-{DD}-{SEQ}', 3, OrderNumberReset::Daily);

    Carbon::setTestNow('2024-10-05 09:00');

    expect(Order::nextNumber())->toBe('2024-10-05-001')
        ->and(Order::nextNumber())->toBe('2024-10-05-002')
        ->and(Order::nextNumber())->toBe('2024-10-05-003');
});

it('restarts the sequence when the period rolls over', function (): void {
    useFormat('{YYYY}-{MM}-{DD}-{SEQ}', 3, OrderNumberReset::Daily);

    Carbon::setTestNow('2024-10-05 23:59');
    expect(Order::nextNumber())->toBe('2024-10-05-001');

    Carbon::setTestNow('2024-10-06 00:01');
    expect(Order::nextNumber())->toBe('2024-10-06-001');
});

it('keeps one continuous sequence across days when set to never restart', function (): void {
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

/**
 * Changing the format must not reset the counter or reuse a number. The
 * predecessor's numbering was parsed back out of the last order, which only
 * works while the format that produced it is still current.
 */
it('carries the sequence across a format change', function (): void {
    useFormat('CI-{YYYY}-{SEQ}', 4, OrderNumberReset::Yearly);
    Carbon::setTestNow('2026-05-01');

    expect(Order::nextNumber())->toBe('CI-2026-0001')
        ->and(Order::nextNumber())->toBe('CI-2026-0002');

    // Same period, different pattern: the counter is unaffected.
    useFormat('ORD/{YYYY}/{SEQ}', 3, OrderNumberReset::Yearly);

    expect(Order::nextNumber())->toBe('ORD/2026/003');
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
    useFormat('{SEQ}', 3, OrderNumberReset::Daily);

    expect(Order::nextNumber())->toBe('CI-'.now()->format('Y').'-0001');
});

// The settings screen ---------------------------------------------------------

it('saves a new order number format', function (): void {
    Livewire\Livewire::actingAs(userWithRole(UserRole::SuperAdmin))
        ->test(Settings::class)
        ->fillForm([
            'order_number_format' => '{YYYY}-{MM}-{DD}-{SEQ}',
            'order_number_padding' => 3,
            'order_number_reset' => OrderNumberReset::Daily->value,
            'order_number_next' => 1,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Setting::get('orders.number_format'))->toBe('{YYYY}-{MM}-{DD}-{SEQ}')
        ->and(Order::nextNumber())->toBe(now()->format('Y-m-d').'-001');
});

it('refuses to save a format that would produce duplicates', function (): void {
    Livewire\Livewire::actingAs(userWithRole(UserRole::SuperAdmin))
        ->test(Settings::class)
        ->fillForm([
            'order_number_format' => '{YYYY}-{SEQ}',
            'order_number_padding' => 3,
            'order_number_reset' => OrderNumberReset::Daily->value,
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
            'order_number_next' => 500,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(GenerateOrderNumber::peek())->toBe(500)
        ->and(Order::nextNumber())->toBe('CI-'.now()->format('Y').'-0500');
});

it('previews the next three numbers rather than one', function (): void {
    $format = new OrderNumberFormat('{YYYY}-{MM}-{DD}-{SEQ}', 3, OrderNumberReset::Daily);

    expect($format->preview(1, 3, Carbon::parse('2024-10-05')))
        ->toBe(['2024-10-05-001', '2024-10-05-002', '2024-10-05-003']);
});

it('keeps one counter row per period', function (): void {
    useFormat('{YYYY}-{MM}-{DD}-{SEQ}', 3, OrderNumberReset::Daily);

    Carbon::setTestNow('2024-10-05');
    Order::nextNumber();
    Order::nextNumber();

    Carbon::setTestNow('2024-10-06');
    Order::nextNumber();

    expect(OrderNumberSequence::query()->count())->toBe(2)
        ->and(OrderNumberSequence::query()->where('period_key', '2024-10-05')->value('next_value'))->toBe(3)
        ->and(OrderNumberSequence::query()->where('period_key', '2024-10-06')->value('next_value'))->toBe(2);
});
