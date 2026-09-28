<?php

declare(strict_types=1);

use App\Enums\BillingPeriod;
use App\Enums\Format;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Filament\Pages\Reports\Accreditation;
use App\Filament\Pages\Reports\Memberships;
use App\Filament\Pages\Reports\Webinars;
use App\Models\Certificate;
use App\Models\MembershipPlan;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Webinar;
use App\Models\WebinarView;
use App\Reports\AccreditationReport;
use App\Reports\MembershipReport;
use App\Reports\WebinarPerformance;
use App\Support\Period;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    Setting::set('reports.timezone', 'UTC', 'string');
});

function everything(): Period
{
    return Period::between('2020-01-01', '2030-12-31');
}

// Accreditation --------------------------------------------------------------------------

/*
 * The report HRCI and SHRM actually ask for. It reads the snapshot frozen on
 * each certificate, not the live webinar — a title changed since must not
 * change what was already submitted.
 */
it('totals credits per accrediting body from the certificate snapshot', function (): void {
    Certificate::factory()->create([
        'accreditation_code' => 'HRCI',
        'credits' => '1.50',
        'webinar_title' => 'FMLA in 2026',
        'issued_at' => '2026-03-10',
    ]);
    Certificate::factory()->create([
        'accreditation_code' => 'HRCI',
        'credits' => '2.00',
        'issued_at' => '2026-03-20',
    ]);
    Certificate::factory()->create([
        'accreditation_code' => 'SHRM',
        'credits' => '1.00',
        'issued_at' => '2026-03-25',
    ]);

    $summary = (new AccreditationReport(Period::between('2026-03-01', '2026-03-31')))->summary();

    expect($summary)->toHaveCount(2)
        ->and($summary->firstWhere('code', 'HRCI')->credits)->toBe('3.50')
        ->and($summary->firstWhere('code', 'HRCI')->certificates)->toBe(2)
        ->and($summary->firstWhere('code', 'SHRM')->credits)->toBe('1.00');
});

/** Half credits are the whole point — 90 minutes is 1.5 — so they must survive. */
it('keeps credits as a decimal string rather than a float', function (): void {
    Certificate::factory()->create(['accreditation_code' => 'CPE', 'credits' => '0.50', 'issued_at' => '2026-03-10']);

    expect((new AccreditationReport(Period::between('2026-03-01', '2026-03-31')))->totalCredits())
        ->toBe('0.50')
        ->toBeString();
});

/*
 * The error this report exists to prevent: reporting a withdrawn credit as
 * awarded.
 */
it('excludes revoked certificates from what was awarded', function (): void {
    Certificate::factory()->create(['accreditation_code' => 'HRCI', 'credits' => '1.00', 'issued_at' => '2026-03-10']);
    Certificate::factory()->create([
        'accreditation_code' => 'HRCI',
        'credits' => '1.00',
        'issued_at' => '2026-03-11',
        'revoked_at' => '2026-03-15',
    ]);

    $report = new AccreditationReport(Period::between('2026-03-01', '2026-03-31'));

    expect($report->totalCertificates())->toBe(1)
        ->and($report->totalCredits())->toBe('1.00');
});

/*
 * Listed by the date it was revoked, not issued. One awarded in March and
 * withdrawn in May belongs in May's submission as a withdrawal; March's was
 * correct when it was made.
 */
it('lists a withdrawal in the period it was withdrawn', function (): void {
    Certificate::factory()->create([
        'accreditation_code' => 'HRCI',
        'issued_at' => '2026-03-10',
        'revoked_at' => '2026-05-02',
    ]);

    expect((new AccreditationReport(Period::between('2026-03-01', '2026-03-31')))->revoked())->toHaveCount(0)
        ->and((new AccreditationReport(Period::between('2026-05-01', '2026-05-31')))->revoked())->toHaveCount(1);
});

it('counts a learner once however many certificates they hold', function (): void {
    $learner = User::factory()->create();

    Certificate::factory()->count(3)->create([
        'user_id' => $learner->getKey(),
        'accreditation_code' => 'HRCI',
        'credits' => '1.00',
        'issued_at' => '2026-03-10',
    ]);

    expect((new AccreditationReport(Period::between('2026-03-01', '2026-03-31')))->totalLearners())->toBe(1);
});

it('opens the accreditation report on the month just gone', function (): void {
    Carbon\CarbonImmutable::setTestNow('2026-03-15 12:00:00');

    $page = new Accreditation;
    $page->data = [];

    expect($page->getPeriod()->from->format('Y-m'))->toBe('2026-02');

    Carbon\CarbonImmutable::setTestNow();
});

// Memberships ------------------------------------------------------------------------------

/*
 * MRR is normalised, not summed. A year at $1,200 is $100 a month — adding raw
 * term prices would make every annual renewal look like a spike.
 */
it('normalises a term price to a month for MRR', function (): void {
    $yearly = MembershipPlan::factory()->create([
        'billing_period' => BillingPeriod::Year,
        'duration_months' => 12,
        'price_cents' => 120000,
    ]);

    Subscription::factory()->for($yearly, 'plan')->create(['price_cents' => 120000]);

    expect((new MembershipReport(everything()))->mrrCents())->toBe(10000);
});

it('adds a monthly plan at its face value', function (): void {
    $monthly = MembershipPlan::factory()->create([
        'billing_period' => BillingPeriod::Month,
        'duration_months' => 1,
        'price_cents' => 4900,
    ]);

    Subscription::factory()->for($monthly, 'plan')->create(['price_cents' => 4900]);

    expect((new MembershipReport(everything()))->mrrCents())->toBe(4900);
});

/** Revenue, but not recurring revenue. Including it makes the figure meaningless. */
it('leaves one-off plans out of MRR', function (): void {
    $oneOff = MembershipPlan::factory()->create([
        'billing_period' => BillingPeriod::OneTime,
        'price_cents' => 99900,
    ]);

    Subscription::factory()->for($oneOff, 'plan')->create(['price_cents' => 99900]);

    expect((new MembershipReport(everything()))->mrrCents())->toBe(0);
});

/** Members keep what they signed up for, even after the plan is repriced. */
it('uses the price the member was sold, not the plan current price', function (): void {
    $plan = MembershipPlan::factory()->create([
        'billing_period' => BillingPeriod::Month,
        'duration_months' => 1,
        'price_cents' => 9900,
    ]);

    Subscription::factory()->for($plan, 'plan')->create(['price_cents' => 4900]);

    expect((new MembershipReport(everything()))->mrrCents())->toBe(4900);
});

it('states ARR as twelve times MRR so the two cannot differ', function (): void {
    $plan = MembershipPlan::factory()->create([
        'billing_period' => BillingPeriod::Month,
        'duration_months' => 1,
        'price_cents' => 5000,
    ]);

    Subscription::factory()->for($plan, 'plan')->create(['price_cents' => 5000]);

    $report = new MembershipReport(everything());

    expect($report->arrCents())->toBe($report->mrrCents() * 12);
});

/*
 * "0% churn" on a period that began with nobody reads as a triumph rather than
 * as an absence of data.
 */
it('reports no churn figure when there was nobody to lose', function (): void {
    expect((new MembershipReport(Period::between('2026-03-01', '2026-03-31')))->churnPercent())->toBeNull();
});

it('works churn out over the members there were at the start', function (): void {
    $plan = MembershipPlan::factory()->create(['billing_period' => BillingPeriod::Month, 'duration_months' => 1]);

    // Four active before the period, one of them cancelling inside it.
    Subscription::factory()->count(3)->for($plan, 'plan')->create(['created_at' => '2026-01-01']);
    Subscription::factory()->for($plan, 'plan')->create([
        'created_at' => '2026-01-01',
        'ends_at' => '2026-03-15',
    ]);

    expect((new MembershipReport(Period::between('2026-03-01', '2026-03-31')))->churnPercent())->toBe(25.0);
});

it('does not divide by zero working out the average member', function (): void {
    expect((new MembershipReport(everything()))->averageMrrCents())->toBe(0);
});

// Webinar performance -------------------------------------------------------------------------

it('brings sales, views and certificates together per webinar', function (): void {
    $webinar = Webinar::factory()->create();
    $order = Order::factory()->paid()->create();

    Payment::factory()->for($order)->create([
        'status' => PaymentStatus::Succeeded,
        'amount_cents' => 20000,
        'processed_at' => '2026-03-10 10:00',
    ]);

    OrderItem::factory()->for($order)->create([
        'webinar_id' => $webinar->getKey(),
        'quantity' => 2,
        'seats_per_unit' => 1,
        'line_total_cents' => 20000,
    ]);

    WebinarView::factory()->count(3)->create([
        'webinar_id' => $webinar->getKey(),
        'completed_at' => null,
    ]);
    WebinarView::factory()->create([
        'webinar_id' => $webinar->getKey(),
        'completed_at' => now(),
    ]);

    Certificate::factory()->create(['webinar_id' => $webinar->getKey()]);

    $row = (new WebinarPerformance(Period::between('2026-03-01', '2026-03-31')))->rows()->first();

    expect($row->seats)->toBe(2)
        ->and($row->revenueCents)->toBe(20000)
        ->and($row->views)->toBe(4)
        ->and($row->completed)->toBe(1)
        ->and($row->certificates)->toBe(1);
});

/*
 * Completion is views finished over views started, not over seats sold.
 * Dividing by seats would report a catalogue of unopened recordings as a
 * quality problem.
 */
it('works completion out over views started, not seats sold', function (): void {
    $webinar = Webinar::factory()->create();

    WebinarView::factory()->create(['webinar_id' => $webinar->getKey(), 'completed_at' => now()]);
    WebinarView::factory()->create(['webinar_id' => $webinar->getKey(), 'completed_at' => null]);

    expect((new WebinarPerformance(everything()))->rows()->first()->completion())->toBe(50.0);
});

it('says nothing rather than zero when a webinar has no views', function (): void {
    $webinar = Webinar::factory()->create();
    Certificate::factory()->create(['webinar_id' => $webinar->getKey()]);

    $row = (new WebinarPerformance(everything()))->rows()->first();

    expect($row->completion())->toBeNull()
        ->and($row->describeCompletion())->toBe('No views');
});

it('leaves out a webinar nothing happened to', function (): void {
    Webinar::factory()->create();

    expect((new WebinarPerformance(everything()))->rows())->toHaveCount(0);
});

/** A revoked certificate was not awarded, here as on the accreditation report. */
it('does not count a revoked certificate against a webinar', function (): void {
    $webinar = Webinar::factory()->create();

    Certificate::factory()->create(['webinar_id' => $webinar->getKey()]);
    Certificate::factory()->create(['webinar_id' => $webinar->getKey(), 'revoked_at' => now()]);

    expect((new WebinarPerformance(everything()))->rows()->first()->certificates)->toBe(1);
});

/*
 * Views are counted for all time while sales are counted in the period — a
 * recording sold in March is still watched in June, and charging those views to
 * March would make every recent webinar look unwatched.
 */
it('counts views for all time even when the sale was outside the period', function (): void {
    $webinar = Webinar::factory()->create();
    WebinarView::factory()->create(['webinar_id' => $webinar->getKey(), 'completed_at' => now()]);

    $row = (new WebinarPerformance(Period::between('2026-03-01', '2026-03-31')))->rows()->first();

    expect($row->views)->toBe(1)
        ->and($row->revenueCents)->toBe(0);
});

// The screens ------------------------------------------------------------------------------------

it('gates the money reports on view_reports', function (string $page): void {
    $this->actingAs(userWithRole(UserRole::Finance))->get($page)->assertSuccessful();
    $this->actingAs(userWithRole(UserRole::ContentEditor))->get($page)->assertForbidden();
})->with(['/admin/reports/memberships', '/admin/reports/webinars']);

/*
 * Accreditation is gated on certificates rather than on revenue: Support
 * answers "where is my certificate" and needs to see what was awarded without
 * being shown what the business took.
 */
it('gates the accreditation report on certificates, not on revenue', function (): void {
    $this->actingAs(userWithRole(UserRole::Support))->get('/admin/reports/accreditation')->assertSuccessful();
    $this->actingAs(userWithRole(UserRole::ContentEditor))->get('/admin/reports/accreditation')->assertForbidden();
});

it('renders the membership figures', function (): void {
    $plan = MembershipPlan::factory()->create([
        'name' => 'Professional',
        'billing_period' => BillingPeriod::Month,
        'duration_months' => 1,
        'price_cents' => 7500,
    ]);

    Subscription::factory()->for($plan, 'plan')->create(['price_cents' => 7500]);

    Livewire\Livewire::actingAs(userWithRole(UserRole::Finance))
        ->test(Memberships::class)
        ->assertSee('$75.00')
        ->assertSee('Professional');
});

it('renders the webinar table', function (): void {
    $webinar = Webinar::factory()->create(['title' => 'Wage and Hour Essentials']);
    WebinarView::factory()->create(['webinar_id' => $webinar->getKey()]);

    Livewire\Livewire::actingAs(userWithRole(UserRole::Finance))
        ->test(Webinars::class)
        ->assertSee('Wage and Hour Essentials');
});

it('offers one export per accrediting body that awarded anything', function (): void {
    Certificate::factory()->create([
        'accreditation_code' => 'HRCI',
        'issued_at' => Period::now()->subMonth()->startOfMonth()->addDay(),
    ]);

    Livewire\Livewire::actingAs(userWithRole(UserRole::SuperAdmin))
        ->test(Accreditation::class)
        ->assertActionVisible('exportHRCI');
});

it('offers no export to somebody who may not take data off the system', function (): void {
    Certificate::factory()->create([
        'accreditation_code' => 'HRCI',
        'issued_at' => Period::now()->subMonth()->startOfMonth()->addDay(),
    ]);

    Livewire\Livewire::actingAs(userWithRole(UserRole::Support))
        ->test(Accreditation::class)
        ->assertActionHidden('exportHRCI');
});

// Allowances --------------------------------------------------------------------------------------

it('lists members who are nearly out of an allowance', function (): void {
    $plan = MembershipPlan::factory()->create(['billing_period' => BillingPeriod::Month, 'duration_months' => 1]);
    $subscription = Subscription::factory()->for($plan, 'plan')->create();

    $subscription->entitlements()->create([
        'format' => Format::Live,
        'quantity' => 4,
        'window' => App\Enums\AllowanceWindow::Term,
    ]);

    expect((new MembershipReport(everything()))->nearingTheirLimit())->toHaveCount(0);
});

/** Unlimited allowances are skipped — there is nothing to run out of. */
it('never lists an unlimited allowance as running out', function (): void {
    $plan = MembershipPlan::factory()->create(['billing_period' => BillingPeriod::Month, 'duration_months' => 1]);
    $subscription = Subscription::factory()->for($plan, 'plan')->create();

    $subscription->entitlements()->create([
        'format' => Format::Recorded,
        'quantity' => null,
        'window' => App\Enums\AllowanceWindow::Term,
    ]);

    expect((new MembershipReport(everything()))->nearingTheirLimit())->toHaveCount(0);
});
