<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Enums\UserRole;
use App\Filament\Pages\Reports\Sales;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Setting;
use App\Reports\OperationsSnapshot;
use App\Reports\SalesReport;
use App\Support\Csv;
use App\Support\OrderCsv;
use App\Support\Period;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    Setting::set('reports.timezone', 'UTC', 'string');
});

/** An order with money captured against it on a given date. */
function paidOrder(string $paidOn, int $cents = 10000, array $orderAttributes = []): Order
{
    $order = Order::factory()->paid()->create([
        'total_cents' => $cents,
        'placed_at' => Carbon\CarbonImmutable::parse($paidOn)->subDay(),
        ...$orderAttributes,
    ]);

    Payment::factory()->for($order)->create([
        'status' => PaymentStatus::Succeeded,
        'amount_cents' => $cents,
        'processed_at' => Carbon\CarbonImmutable::parse($paidOn),
    ]);

    return $order;
}

function refundOn(Order $order, string $refundedOn, int $cents): Refund
{
    return Refund::factory()->for($order->payments->first())->create([
        'status' => RefundStatus::Succeeded,
        'amount_cents' => $cents,
        'processed_at' => Carbon\CarbonImmutable::parse($refundedOn),
    ]);
}

function reportFor(string $from, string $to): SalesReport
{
    return new SalesReport(Period::between($from, $to));
}

// The three judgements the report is built on ------------------------------------------

/*
 * The first of them: money belongs to the day it was captured, not the day the
 * order was placed. An order placed on the 30th and paid on the 2nd is the new
 * month's revenue — which is what an accountant means, and the only date on
 * which the money actually existed.
 */
it('counts revenue on the day the money was captured', function (): void {
    paidOrder('2026-03-02 10:00', 25000, ['placed_at' => '2026-02-28 23:00']);

    expect(reportFor('2026-02-01', '2026-02-28')->netCents())->toBe(0)
        ->and(reportFor('2026-03-01', '2026-03-31')->netCents())->toBe(25000);
});

/*
 * The second: a refund reduces the period it happened in. Otherwise a refund
 * issued today rewrites a month that has already been reported, and the
 * dashboard starts disagreeing with whatever was filed.
 */
it('charges a refund to the period it happened in, not the sale', function (): void {
    $order = paidOrder('2026-03-10 10:00', 30000);
    refundOn($order, '2026-04-05 10:00', 10000);

    expect(reportFor('2026-03-01', '2026-03-31')->netCents())->toBe(30000)
        ->and(reportFor('2026-04-01', '2026-04-30')->netCents())->toBe(-10000);
});

/*
 * The third: a line selling several formats splits its revenue between them.
 * Counting it fully against each would make the breakdown add up to more than
 * the revenue, which is worse than being slightly arbitrary.
 */
it('splits a multi-format line evenly and never invents money', function (): void {
    $order = paidOrder('2026-03-10 10:00', 30000);

    $item = App\Models\OrderItem::factory()->for($order)->create(['line_total_cents' => 30000]);
    $item->formats()->create(['format' => App\Enums\Format::Live]);
    $item->formats()->create(['format' => App\Enums\Format::Recorded]);

    $byFormat = reportFor('2026-03-01', '2026-03-31')->byFormat();

    expect($byFormat->sum('cents'))->toBe(30000)
        ->and($byFormat->pluck('cents')->all())->toBe([15000, 15000]);
});

/** An odd amount must not lose a cent to integer division. */
it('gives the rounding remainder to a format rather than losing it', function (): void {
    $order = paidOrder('2026-03-10 10:00', 10001);

    $item = App\Models\OrderItem::factory()->for($order)->create(['line_total_cents' => 10001]);
    $item->formats()->create(['format' => App\Enums\Format::Live]);
    $item->formats()->create(['format' => App\Enums\Format::Recorded]);

    expect(reportFor('2026-03-01', '2026-03-31')->byFormat()->sum('cents'))->toBe(10001);
});

// Headline ---------------------------------------------------------------------------------

it('reports gross, refunded and net separately', function (): void {
    $order = paidOrder('2026-03-10 10:00', 30000);
    refundOn($order, '2026-03-20 10:00', 5000);

    $headline = reportFor('2026-03-01', '2026-03-31')->headline();

    expect($headline['gross'])->toBe(30000)
        ->and($headline['refunded'])->toBe(5000)
        ->and($headline['net'])->toBe(25000);
});

it('ignores payments that never succeeded', function (): void {
    $order = Order::factory()->pending()->create(['total_cents' => 50000]);

    Payment::factory()->for($order)->create([
        'status' => PaymentStatus::Failed,
        'amount_cents' => 50000,
        'processed_at' => '2026-03-10 10:00',
    ]);

    expect(reportFor('2026-03-01', '2026-03-31')->grossCents())->toBe(0);
});

it('ignores a refund that has not gone through', function (): void {
    $order = paidOrder('2026-03-10 10:00', 30000);

    Refund::factory()->for($order->payments->first())->create([
        'status' => RefundStatus::Pending,
        'amount_cents' => 10000,
        'processed_at' => '2026-03-15 10:00',
    ]);

    expect(reportFor('2026-03-01', '2026-03-31')->netCents())->toBe(30000);
});

/** An order paid in two instalments is one order, not two. */
it('counts an order once however many payments it took', function (): void {
    $order = Order::factory()->paid()->create(['total_cents' => 20000, 'discount_cents' => 1000]);

    foreach (['2026-03-05 10:00', '2026-03-09 10:00'] as $when) {
        Payment::factory()->for($order)->create([
            'status' => PaymentStatus::Succeeded,
            'amount_cents' => 10000,
            'processed_at' => $when,
        ]);
    }

    $report = reportFor('2026-03-01', '2026-03-31');

    expect($report->orderCount())->toBe(1)
        ->and($report->grossCents())->toBe(20000)
        // The discount must not be double-counted with the second payment.
        ->and($report->discountCents())->toBe(1000);
});

it('does not divide by zero on an empty period', function (): void {
    expect(reportFor('2026-03-01', '2026-03-31')->averageOrderCents())->toBe(0);
});

// Over time -----------------------------------------------------------------------------------

/*
 * A day with no sales has to be a row reading zero. A chart drawn from unfilled
 * data quietly closes the gap and shows a flat line through a week nothing
 * happened.
 */
it('fills the days nothing happened on', function (): void {
    paidOrder('2026-03-01 10:00', 10000);
    paidOrder('2026-03-05 10:00', 10000);

    $series = reportFor('2026-03-01', '2026-03-05')->overTime();

    expect($series)->toHaveCount(5)
        ->and($series->pluck('cents')->all())->toBe([10000, 0, 0, 0, 10000]);
});

it('drops to months when the range is long enough to need it', function (): void {
    expect(Period::between('2026-01-01', '2026-12-31')->grouping())->toBe('month')
        ->and(Period::between('2026-03-01', '2026-03-31')->grouping())->toBe('day');
});

// Periods --------------------------------------------------------------------------------------

/*
 * A calendar month compares against the month before, not against the 30 days
 * before it — otherwise a 31-day month reads as growth against a 30-day one.
 */
it('steps a calendar period back by calendar', function (): void {
    Carbon\CarbonImmutable::setTestNow('2026-03-15 12:00:00');

    $previous = Period::preset('mtd')->previous();

    expect($previous->from->format('Y-m-d'))->toBe('2026-02-01')
        ->and($previous->to->format('Y-m-d'))->toBe('2026-02-28');

    Carbon\CarbonImmutable::setTestNow();
});

it('takes dates in either order', function (): void {
    $period = Period::between('2026-03-31', '2026-03-01');

    expect($period->from->format('Y-m-d'))->toBe('2026-03-01')
        ->and($period->to->format('Y-m-d'))->toBe('2026-03-31');
});

/*
 * The app runs on UTC, which is right for storage and wrong for "what did we
 * take today". A US business asking that at 8pm on the 3rd must not be shown
 * the 4th.
 */
it('works out today in the business timezone, not the server one', function (): void {
    Setting::set('reports.timezone', 'America/New_York', 'string');
    Carbon\CarbonImmutable::setTestNow('2026-03-04 02:00:00');

    // 2am UTC on the 4th is 9pm on the 3rd in New York.
    expect(Period::preset('today')->from->format('Y-m-d'))->toBe('2026-03-03');

    Carbon\CarbonImmutable::setTestNow();
});

it('falls back rather than breaking every report on a mistyped timezone', function (): void {
    Setting::set('reports.timezone', 'Not/A/Zone', 'string');

    expect(Period::timezone()->getName())->toBe('America/New_York');
});

// What needs doing -------------------------------------------------------------------------------

it('counts what is waiting without counting what is not', function (): void {
    Order::factory()->pending()->create([
        'total_cents' => 50000,
        'placed_at' => now()->subDays(30),
    ]);
    Order::factory()->pending()->create([
        'total_cents' => 10000,
        'placed_at' => now()->subDay(),
    ]);
    paidOrder(now()->toDateTimeString(), 20000);

    $snapshot = new OperationsSnapshot;

    expect($snapshot->awaitingPayment())->toBe(2)
        // Only the old one is overdue; a few days unpaid is normal for an
        // invoiced B2B order.
        ->and($snapshot->overdue())->toBe(1)
        ->and($snapshot->outstandingCents())->toBe(60000);
});

it('shows nothing in the attention list when nothing needs attention', function (): void {
    expect((new OperationsSnapshot)->attention())->toBe([]);
});

it('lists only the things that are actually waiting', function (): void {
    Order::factory()->pending()->create(['placed_at' => now()->subDays(30)]);

    $labels = collect((new OperationsSnapshot)->attention())->pluck('label');

    expect($labels)->toHaveCount(1)
        ->and($labels->first())->toContain('invoices');
});

// The screen ----------------------------------------------------------------------------------------

it('keeps reports to roles that may see money', function (): void {
    $this->actingAs(userWithRole(UserRole::Finance))->get('/admin/reports/sales')->assertSuccessful();
    $this->actingAs(userWithRole(UserRole::SuperAdmin))->get('/admin/reports/sales')->assertSuccessful();
    $this->actingAs(userWithRole(UserRole::ContentEditor))->get('/admin/reports/sales')->assertForbidden();
    $this->actingAs(userWithRole(UserRole::Support))->get('/admin/reports/sales')->assertForbidden();
});

it('renders the figures for the chosen period', function (): void {
    paidOrder(now()->toDateTimeString(), 42500);

    Livewire\Livewire::actingAs(userWithRole(UserRole::Finance))
        ->test(Sales::class)
        ->assertSee('$425.00');
});

/** Reading a report and walking away with the figures are different privileges. */
it('keeps the report export to roles holding export_data', function (): void {
    Livewire\Livewire::actingAs(userWithRole(UserRole::Finance))
        ->test(Sales::class)
        ->assertActionVisible('export');
});

// Order exports ---------------------------------------------------------------------------------------

it('offers both shapes of order export, to roles that may export', function (): void {
    Livewire\Livewire::actingAs(userWithRole(UserRole::Finance))
        ->test(ListOrders::class)
        ->assertActionVisible('exportOrders')
        ->assertActionVisible('exportLines');

    Livewire\Livewire::actingAs(userWithRole(UserRole::Support))
        ->test(ListOrders::class)
        ->assertActionHidden('exportOrders')
        ->assertActionHidden('exportLines');
});

it('writes one row per order, with the money as decimals', function (): void {
    paidOrder('2026-03-10 10:00', 27900);

    $rows = iterator_to_array(OrderCsv::orderRows(Order::query()));

    expect($rows)->toHaveCount(1)
        // Parsed as a string, never multiplied as a float (§4 rule 6).
        ->and($rows[0])->toContain('279.00');
});

it('writes one row per line for the line-level export', function (): void {
    $order = paidOrder('2026-03-10 10:00', 20000);
    App\Models\OrderItem::factory()->count(3)->for($order)->create();

    expect(iterator_to_array(OrderCsv::lineRows(Order::query())))->toHaveCount(3);
});

it('exports the date the money arrived, not just the date it was ordered', function (): void {
    paidOrder('2026-03-10 14:30', 10000, ['placed_at' => '2026-02-28 09:00']);

    $row = iterator_to_array(OrderCsv::orderRows(Order::query()))[0];

    expect($row)->toContain('2026-02-28 09:00')
        // Which is the date the Sales report counts it on — an export whose
        // dates disagree with the report is two people with two answers.
        ->toContain('2026-03-10 14:30');
});

it('honours the filters on the screen rather than exporting everything', function (): void {
    paidOrder('2026-03-10 10:00', 10000);
    Order::factory()->pending()->create(['placed_at' => '2026-03-11 10:00']);

    $paidOnly = Order::query()->where('status', OrderStatus::Paid);

    expect(iterator_to_array(OrderCsv::orderRows($paidOnly)))->toHaveCount(1);
});

// Money formatting ------------------------------------------------------------------------------------

it('writes money as a plain decimal string', function (): void {
    expect(Csv::money(27900))->toBe('279.00')
        ->and(Csv::money(0))->toBe('0.00')
        ->and(Csv::money(5))->toBe('0.05');
});
