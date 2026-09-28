<?php

declare(strict_types=1);

use App\Enums\InquirySource;
use App\Enums\PaymentStatus;
use App\Enums\SubscriberSource;
use App\Enums\UserRole;
use App\Filament\Pages\Reports\Coupons;
use App\Filament\Pages\Reports\Engagement;
use App\Models\Comment;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Inquiry;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Post;
use App\Models\Setting;
use App\Models\Subscriber;
use App\Reports\CouponPerformance;
use App\Reports\EngagementReport;
use App\Support\Period;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    Setting::set('reports.timezone', 'UTC', 'string');
});

function march(): Period
{
    return Period::between('2026-03-01', '2026-03-31');
}

/** A coupon used on an order that was paid for. */
function redeemed(Coupon $coupon, int $discountCents, int $paidCents, string $on = '2026-03-10 10:00'): Order
{
    $order = Order::factory()->paid()->create(['total_cents' => $paidCents]);

    Payment::factory()->for($order)->create([
        'status' => PaymentStatus::Succeeded,
        'amount_cents' => $paidCents,
        'processed_at' => $on,
    ]);

    CouponRedemption::factory()->create([
        'coupon_id' => $coupon->getKey(),
        'order_id' => $order->getKey(),
        'amount_cents' => $discountCents,
        'redeemed_at' => $on,
    ]);

    return $order;
}

// Coupons ------------------------------------------------------------------------------

it('reports what a code cost and what the orders were worth', function (): void {
    $coupon = Coupon::factory()->create(['code' => 'LAUNCH20']);

    redeemed($coupon, 2000, 8000);
    redeemed($coupon, 2500, 7500);

    $row = (new CouponPerformance(march()))->rows()->sole();

    expect($row->uses)->toBe(2)
        ->and($row->discountCents)->toBe(4500)
        ->and($row->revenueCents)->toBe(15500);
});

/*
 * The discount as a share of what the order would have been worth without it —
 * 2000 off an order that then took 8000 is 20% of the 10000 list price.
 */
it('works the discount rate out against the undiscounted value', function (): void {
    $coupon = Coupon::factory()->create();
    redeemed($coupon, 2000, 8000);

    expect((new CouponPerformance(march()))->rows()->sole()->discountRate())->toBe(20.0);
});

it('says nothing rather than zero when there is nothing to divide by', function (): void {
    $coupon = Coupon::factory()->create();

    // Redeemed against an order that was never paid for.
    $order = Order::factory()->pending()->create();
    CouponRedemption::factory()->create([
        'coupon_id' => $coupon->getKey(),
        'order_id' => $order->getKey(),
        'amount_cents' => 0,
        'redeemed_at' => '2026-03-10 10:00',
    ]);

    expect((new CouponPerformance(march()))->rows()->sole()->discountRate())->toBeNull();
});

it('counts a redemption in the period it was redeemed', function (): void {
    $coupon = Coupon::factory()->create();
    redeemed($coupon, 1000, 9000, '2026-04-02 10:00');

    expect((new CouponPerformance(march()))->rows())->toHaveCount(0)
        ->and((new CouponPerformance(Period::between('2026-04-01', '2026-04-30')))->rows())->toHaveCount(1);
});

/*
 * The revenue is what was captured on those orders, whenever it was captured.
 * Restricting it to the same window would show a code discounted in March and
 * paid in April as pure cost.
 */
it('shows revenue captured after the redemption month', function (): void {
    $coupon = Coupon::factory()->create();

    $order = Order::factory()->paid()->create(['total_cents' => 9000]);
    Payment::factory()->for($order)->create([
        'status' => PaymentStatus::Succeeded,
        'amount_cents' => 9000,
        'processed_at' => '2026-04-20 10:00',
    ]);
    CouponRedemption::factory()->create([
        'coupon_id' => $coupon->getKey(),
        'order_id' => $order->getKey(),
        'amount_cents' => 1000,
        'redeemed_at' => '2026-03-25 10:00',
    ]);

    expect((new CouponPerformance(march()))->rows()->sole()->revenueCents)->toBe(9000);
});

it('ignores money that was never captured', function (): void {
    $coupon = Coupon::factory()->create();

    $order = Order::factory()->pending()->create(['total_cents' => 9000]);
    Payment::factory()->for($order)->create([
        'status' => PaymentStatus::Failed,
        'amount_cents' => 9000,
        'processed_at' => '2026-03-10 10:00',
    ]);
    CouponRedemption::factory()->create([
        'coupon_id' => $coupon->getKey(),
        'order_id' => $order->getKey(),
        'amount_cents' => 1000,
        'redeemed_at' => '2026-03-10 10:00',
    ]);

    expect((new CouponPerformance(march()))->rows()->sole()->revenueCents)->toBe(0);
});

it('shows how much of a redemption cap has gone', function (): void {
    $coupon = Coupon::factory()->create(['max_redemptions' => 4]);
    redeemed($coupon, 1000, 9000);

    expect((new CouponPerformance(march()))->rows()->sole()->capUsed())->toBe(25.0);
});

/** A progress bar against infinity means nothing. */
it('shows no cap usage for a code without a cap', function (): void {
    $coupon = Coupon::factory()->create(['max_redemptions' => null]);
    redeemed($coupon, 1000, 9000);

    expect((new CouponPerformance(march()))->rows()->sole()->capUsed())->toBeNull();
});

it('lists live codes nobody has used', function (): void {
    Coupon::factory()->create(['code' => 'UNUSED', 'is_active' => true]);
    $used = Coupon::factory()->create(['code' => 'USED', 'is_active' => true]);
    redeemed($used, 1000, 9000);

    $neverUsed = (new CouponPerformance(march()))->neverUsed();

    expect($neverUsed)->toHaveCount(1)
        ->and($neverUsed->first()->code)->toBe('UNUSED');
});

it('does not list a switched-off code as unused', function (): void {
    Coupon::factory()->create(['is_active' => false]);

    expect((new CouponPerformance(march()))->neverUsed())->toHaveCount(0);
});

it('warns about codes ending soon', function (): void {
    Coupon::factory()->create(['is_active' => true, 'ends_at' => now()->addDays(10)]);
    Coupon::factory()->create(['is_active' => true, 'ends_at' => now()->addMonths(6)]);
    Coupon::factory()->create(['is_active' => true, 'ends_at' => null]);

    expect((new CouponPerformance(march()))->endingSoon())->toHaveCount(1);
});

// Engagement ----------------------------------------------------------------------------------

it('counts the list as it stands and the movement over the period', function (): void {
    Subscriber::factory()->count(3)->create(['subscribed_at' => '2026-03-05']);
    Subscriber::factory()->unsubscribed()->create([
        'subscribed_at' => '2026-01-01',
        'unsubscribed_at' => '2026-03-20',
    ]);

    $report = new EngagementReport(march());

    expect($report->joined())->toBe(3)
        ->and($report->left())->toBe(1)
        ->and($report->netListChange())->toBe(2)
        // As of now, not a count of the period.
        ->and($report->listSize())->toBe(3);
});

it('breaks signups down by where they came from', function (): void {
    Subscriber::factory()->count(2)->from(SubscriberSource::Footer)->create(['subscribed_at' => '2026-03-05']);
    Subscriber::factory()->from(SubscriberSource::Checkout)->create(['subscribed_at' => '2026-03-06']);

    $rows = (new EngagementReport(march()))->signupsBySource();

    expect($rows->first()->label)->toBe(SubscriberSource::Footer->label())
        ->and($rows->first()->cents)->toBe(2);
});

/** A deliverability problem is a fact about now, not about a month. */
it('counts deliverability problems as they stand, not per period', function (): void {
    Subscriber::factory()->bounced()->create(['subscribed_at' => '2020-01-01']);
    Subscriber::factory()->complained()->create(['subscribed_at' => '2020-01-01']);

    expect((new EngagementReport(march()))->deliverabilityProblems())->toBe(2);
});

/*
 * The median, not the mean. One inquiry answered after three weeks would drag
 * an average into meaninglessness.
 */
it('reports the median wait rather than the average', function (): void {
    foreach ([1, 2, 3, 500] as $hours) {
        Inquiry::factory()->create([
            'created_at' => '2026-03-10 00:00',
            'contacted_at' => Carbon\CarbonImmutable::parse('2026-03-10 00:00')->addHours($hours),
        ]);
    }

    // Median of 1, 2, 3, 500 is 2.5. The mean would be 126.5.
    expect((new EngagementReport(march()))->medianResponseHours())->toBe(2.5);
});

it('reports no wait at all when nothing was answered', function (): void {
    Inquiry::factory()->create(['created_at' => '2026-03-10 00:00', 'contacted_at' => null]);

    expect((new EngagementReport(march()))->medianResponseHours())->toBeNull();
});

it('counts inquiries received and answered over the period', function (): void {
    Inquiry::factory()->count(2)->create([
        'created_at' => '2026-03-10 00:00',
        'contacted_at' => '2026-03-11 00:00',
    ]);
    Inquiry::factory()->create(['created_at' => '2026-03-12 00:00', 'contacted_at' => null]);

    $report = new EngagementReport(march());

    expect($report->inquiriesReceived())->toBe(3)
        ->and($report->inquiriesAnswered())->toBe(2);
});

it('breaks inquiries down by where they came from', function (): void {
    Inquiry::factory()->count(2)->from(InquirySource::Contact)->create(['created_at' => '2026-03-10']);
    Inquiry::factory()->from(InquirySource::Quiz)->create(['created_at' => '2026-03-11']);

    expect((new EngagementReport(march()))->inquiriesBySource()->first()->cents)->toBe(2);
});

it('counts blog activity over the period', function (): void {
    Post::factory()->create(['status' => App\Enums\PublishStatus::Published, 'published_at' => '2026-03-05']);
    Post::factory()->create(['status' => App\Enums\PublishStatus::Published, 'published_at' => '2026-01-05']);

    Comment::factory()->count(2)->create(['created_at' => '2026-03-10']);
    Comment::factory()->approved()->create(['created_at' => '2026-03-11']);

    $report = new EngagementReport(march());

    expect($report->postsPublished())->toBe(1)
        ->and($report->commentsReceived())->toBe(3)
        ->and($report->commentsPublished())->toBe(1);
});

it('knows when nothing happened at all', function (): void {
    expect((new EngagementReport(march()))->isQuiet())->toBeTrue();

    Inquiry::factory()->create(['created_at' => '2026-03-10']);

    expect((new EngagementReport(march()))->isQuiet())->toBeFalse();
});

// The screens -----------------------------------------------------------------------------------

it('gates both Tier 3 reports on view_reports', function (string $page): void {
    $this->actingAs(userWithRole(UserRole::Finance))->get($page)->assertSuccessful();
    $this->actingAs(userWithRole(UserRole::ContentEditor))->get($page)->assertForbidden();
    $this->actingAs(userWithRole(UserRole::Support))->get($page)->assertForbidden();
})->with(['/admin/reports/coupons', '/admin/reports/engagement']);

it('renders the discount cost', function (): void {
    $coupon = Coupon::factory()->create(['code' => 'LAUNCH20']);
    redeemed($coupon, 2000, 8000, now()->toDateTimeString());

    Livewire\Livewire::actingAs(userWithRole(UserRole::Finance))
        ->test(Coupons::class)
        ->assertSee('LAUNCH20')
        ->assertSee('$20.00');
});

it('renders the engagement figures', function (): void {
    Subscriber::factory()->count(4)->create(['subscribed_at' => now()]);

    Livewire\Livewire::actingAs(userWithRole(UserRole::Finance))
        ->test(Engagement::class)
        ->assertSee('Mailing list')
        ->assertSee('Typical wait');
});

it('keeps both exports to roles holding export_data', function (): void {
    Livewire\Livewire::actingAs(userWithRole(UserRole::Finance))
        ->test(Coupons::class)
        ->assertActionVisible('export');

    Livewire\Livewire::actingAs(userWithRole(UserRole::SuperAdmin))
        ->test(Engagement::class)
        ->assertActionVisible('export');
});

it('puts the wait into words rather than raw hours', function (): void {
    Inquiry::factory()->create([
        'created_at' => now()->startOfMonth(),
        'contacted_at' => now()->startOfMonth()->addMinutes(20),
    ]);

    $page = new Engagement;
    $page->data = ['preset' => 'mtd'];

    expect($page->describeWait())->toBe('Under an hour');
});

it('says so plainly when nothing has been answered', function (): void {
    $page = new Engagement;
    $page->data = ['preset' => 'mtd'];

    expect($page->describeWait())->toBe('Nothing answered yet');
});
