<?php

declare(strict_types=1);

use App\Actions\Coupons\RedeemCoupon;
use App\Actions\Coupons\ValidateCoupon;
use App\Enums\CouponType;
use App\Enums\Format;
use App\Enums\UserRole;
use App\Filament\Resources\Coupons\Pages\CreateCoupon;
use App\Filament\Resources\Coupons\Pages\ListCoupons;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Industry;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Models\Webinar;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

/** An order with one line, optionally on a given webinar and formats. */
function orderWithLine(
    int $cents = 20000,
    ?Webinar $webinar = null,
    array $formats = [Format::Live],
): Order {
    $order = Order::factory()->paid()->totalling($cents)->create();

    $item = OrderItem::factory()->create([
        'order_id' => $order->id,
        'webinar_id' => $webinar?->getKey(),
        'quantity' => 1,
        'unit_price_cents' => $cents,
        'line_total_cents' => $cents,
    ]);

    foreach ($formats as $format) {
        $item->formats()->create(['format' => $format]);
    }

    return $order->fresh(['items.formats', 'items.webinar']);
}

// Authorization ---------------------------------------------------------------

it('opens coupons to roles that may read them', function (UserRole $role): void {
    $this->actingAs(userWithRole($role))
        ->get('/admin/coupons')
        ->assertSuccessful();
})->with([UserRole::SuperAdmin, UserRole::Finance]);

/** A discount is money, so it sits behind the same wall as orders. */
it('keeps the Content Editor and Support out of coupons', function (UserRole $role): void {
    $this->actingAs(userWithRole($role))
        ->get('/admin/coupons')
        ->assertForbidden();
})->with([UserRole::ContentEditor, UserRole::Support]);

// The model -------------------------------------------------------------------

it('derives the type from whichever value is set', function (): void {
    expect(Coupon::factory()->percent(20)->make()->type())->toBe(CouponType::Percent)
        ->and(Coupon::factory()->fixed(2500)->make()->type())->toBe(CouponType::Fixed);
});

/**
 * §14 #12 — a code that works in capitals and not in lower case is the same
 * class of bug as the predecessor's 'E_transcript' vs 'E_transcripts'.
 */
it('stores and matches codes case-insensitively', function (): void {
    $coupon = Coupon::factory()->create(['code' => 'save10']);

    expect($coupon->code)->toBe('SAVE10')
        ->and(Coupon::query()->matchingCode('save10')->first()?->id)->toBe($coupon->id)
        ->and(Coupon::query()->matchingCode('  SaVe10 ')->first()?->id)->toBe($coupon->id);
});

/**
 * A fixed $50 coupon against a $30 basket takes $30, not $50 — a negative total
 * would be a refund the business never agreed to.
 */
it('never discounts more than the amount it applies to', function (): void {
    $coupon = Coupon::factory()->fixed(5000)->make();

    expect($coupon->discountFor(3000))->toBe(3000)
        ->and($coupon->discountFor(8000))->toBe(5000);
});

/** §4 rule 6 — integer arithmetic, rounding down rather than drifting. */
it('rounds a percentage down to the cent', function (): void {
    $coupon = Coupon::factory()->percent(33)->make();

    expect($coupon->discountFor(1000))->toBe(330)
        ->and($coupon->discountFor(999))->toBe(329)
        ->and($coupon->discountFor(1))->toBe(0);
});

it('counts redemptions rather than trusting a column', function (): void {
    $coupon = Coupon::factory()->cappedAt(2)->create();

    expect($coupon->isExhausted())->toBeFalse();

    CouponRedemption::factory()->count(2)->create(['coupon_id' => $coupon->id]);

    expect($coupon->fresh()?->isExhausted())->toBeTrue();
});

it('says why a code is not live', function (): void {
    expect(Coupon::factory()->inactive()->create()->unusableReason())->toBe('Switched off')
        ->and(Coupon::factory()->expired()->create()->unusableReason())->toContain('Expired')
        ->and(Coupon::factory()->notYetStarted()->create()->unusableReason())->toContain('Starts')
        ->and(Coupon::factory()->create()->unusableReason())->toBeNull();
});

// Validation ------------------------------------------------------------------

it('accepts a live code and works out the discount', function (): void {
    $verdict = app(ValidateCoupon::class)(
        Coupon::factory()->percent(20)->create(),
        orderWithLine(20000),
    );

    expect($verdict->accepted)->toBeTrue()
        ->and($verdict->discountCents)->toBe(4000);
});

it('refuses a code that is not live', function (string $state, string $expected): void {
    $coupon = Coupon::factory()->{$state}()->create();

    $verdict = app(ValidateCoupon::class)($coupon, orderWithLine());

    expect($verdict->accepted)->toBeFalse()
        ->and($verdict->reason)->toContain($expected);
})->with([
    'switched off' => ['inactive', 'not available'],
    'expired' => ['expired', 'expired'],
    'not started' => ['notYetStarted', 'cannot be used yet'],
]);

it('refuses a code that has been fully redeemed', function (): void {
    $coupon = Coupon::factory()->cappedAt(1)->create();
    CouponRedemption::factory()->create(['coupon_id' => $coupon->id]);

    expect(app(ValidateCoupon::class)($coupon->fresh(), orderWithLine())->reason)
        ->toContain('fully redeemed');
});

it('refuses a customer who has used their allowance', function (): void {
    $coupon = Coupon::factory()->cappedAt(100, perUser: 1)->create();
    $user = User::factory()->create();

    CouponRedemption::factory()->create(['coupon_id' => $coupon->id, 'user_id' => $user->id]);

    expect(app(ValidateCoupon::class)($coupon->fresh(), orderWithLine(), $user)->reason)
        ->toContain('already used');
});

/**
 * A guest checkout has no identity to count against. Worth asserting so the
 * limit of what `max_per_user` promises is written down rather than assumed.
 */
it('cannot enforce a per-customer cap on a guest', function (): void {
    $coupon = Coupon::factory()->cappedAt(100, perUser: 1)->create();
    CouponRedemption::factory()->create(['coupon_id' => $coupon->id, 'user_id' => null]);

    expect(app(ValidateCoupon::class)($coupon->fresh(), orderWithLine(), null)->accepted)->toBeTrue();
});

it('refuses below the minimum spend', function (): void {
    $coupon = Coupon::factory()->percent(20)->minimumSpend(15000)->create();

    expect(app(ValidateCoupon::class)($coupon, orderWithLine(10000))->reason)->toContain('at least')
        ->and(app(ValidateCoupon::class)($coupon, orderWithLine(20000))->accepted)->toBeTrue();
});

it('refuses the same code twice on one order', function (): void {
    $coupon = Coupon::factory()->create();
    $order = orderWithLine();

    CouponRedemption::factory()->create(['coupon_id' => $coupon->id, 'order_id' => $order->id]);

    expect(app(ValidateCoupon::class)($coupon->fresh(), $order)->reason)
        ->toContain('already applied');
});

// Scope -----------------------------------------------------------------------

/**
 * §8 improvement #2 — scoping to industry, format or a specific webinar. A
 * scoped coupon discounts its own lines only; anything else quietly gives away
 * more than the offer said.
 */
it('discounts only the lines it covers', function (): void {
    $industry = Industry::factory()->create();
    $covered = Webinar::factory()->create(['industry_id' => $industry->id]);
    $other = Webinar::factory()->create();

    $order = Order::factory()->paid()->totalling(30000)->create();

    foreach ([[$covered, 20000], [$other, 10000]] as [$webinar, $cents]) {
        $item = OrderItem::factory()->create([
            'order_id' => $order->id,
            'webinar_id' => $webinar->id,
            'quantity' => 1,
            'unit_price_cents' => $cents,
            'line_total_cents' => $cents,
        ]);
        $item->formats()->create(['format' => Format::Live]);
    }

    $coupon = Coupon::factory()->percent(50)->forIndustry($industry)->create();

    // Half of the covered £200 line, not half of the £300 basket.
    expect(app(ValidateCoupon::class)($coupon, $order->fresh())->discountCents)->toBe(10000);
});

it('scopes to a single webinar', function (): void {
    $webinar = Webinar::factory()->create();
    $coupon = Coupon::factory()->percent(10)->forWebinar($webinar)->create();

    expect(app(ValidateCoupon::class)($coupon, orderWithLine(20000, $webinar))->discountCents)->toBe(2000)
        ->and(app(ValidateCoupon::class)($coupon, orderWithLine(20000, Webinar::factory()->create()))->accepted)
        ->toBeFalse();
});

it('scopes to a format', function (): void {
    $coupon = Coupon::factory()->percent(10)->forFormat(Format::Transcript)->create();

    expect(app(ValidateCoupon::class)($coupon, orderWithLine(20000, null, [Format::Transcript]))->discountCents)
        ->toBe(2000)
        ->and(app(ValidateCoupon::class)($coupon, orderWithLine(20000, null, [Format::Live]))->accepted)
        ->toBeFalse();
});

/** Both set means the overlap — "20% off HR transcripts". */
it('combines a record scope and a format scope', function (): void {
    $industry = Industry::factory()->create();
    $webinar = Webinar::factory()->create(['industry_id' => $industry->id]);

    $coupon = Coupon::factory()->percent(20)->forIndustry($industry)->forFormat(Format::Transcript)->create();

    expect(app(ValidateCoupon::class)($coupon, orderWithLine(10000, $webinar, [Format::Transcript]))->accepted)
        ->toBeTrue()
        ->and(app(ValidateCoupon::class)($coupon, orderWithLine(10000, $webinar, [Format::Live]))->accepted)
        ->toBeFalse();
});

// Redemption ------------------------------------------------------------------

it('writes a redemption with the discount snapshotted', function (): void {
    $coupon = Coupon::factory()->percent(20)->create();
    $order = orderWithLine(20000);
    $user = User::factory()->create();

    $redemption = app(RedeemCoupon::class)($coupon, $order, $user);

    expect($redemption->amount_cents)->toBe(4000)
        ->and($redemption->user_id)->toBe($user->id)
        ->and($redemption->order_id)->toBe($order->id);
});

/**
 * §8 improvement #2 — the redemption is taken under a lock and re-validated, so
 * a code exhausted since the customer saw a price cannot still be spent.
 */
it('re-validates under the lock rather than trusting the earlier check', function (): void {
    $coupon = Coupon::factory()->cappedAt(1)->create();

    app(RedeemCoupon::class)($coupon, orderWithLine());

    expect(fn () => app(RedeemCoupon::class)($coupon->fresh(), orderWithLine()))
        ->toThrow(RuntimeException::class, 'fully redeemed');
});

/** The UNIQUE is what makes a retried checkout idempotent. */
it('refuses two redemptions of one coupon on one order', function (): void {
    $coupon = Coupon::factory()->create();
    $order = orderWithLine();

    CouponRedemption::factory()->create(['coupon_id' => $coupon->id, 'order_id' => $order->id]);

    expect(fn () => CouponRedemption::factory()->create([
        'coupon_id' => $coupon->id,
        'order_id' => $order->id,
    ]))->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);
});

// The screen ------------------------------------------------------------------

it('renders the list without lazy loading relations', function (): void {
    Coupon::factory()->count(3)->create();
    Coupon::factory()->forIndustry(Industry::factory()->create())->create();

    $this->actingAs(userWithRole(UserRole::Finance))
        ->get('/admin/coupons')
        ->assertSuccessful();
});

it('separates running codes from finished ones', function (): void {
    $live = Coupon::factory()->create();
    $finished = Coupon::factory()->expired()->create();

    Livewire\Livewire::actingAs(userWithRole(UserRole::Finance))
        ->test(ListCoupons::class)
        ->set('activeTab', 'live')
        ->assertCanSeeTableRecords([$live])
        ->assertCanNotSeeTableRecords([$finished])
        ->set('activeTab', 'finished')
        ->assertCanSeeTableRecords([$finished])
        ->assertCanNotSeeTableRecords([$live]);
});

it('creates a percentage coupon from the form', function (): void {
    Livewire\Livewire::actingAs(userWithRole(UserRole::Finance))
        ->test(CreateCoupon::class)
        ->fillForm([
            'code' => 'newcode',
            'discount_type' => CouponType::Percent->value,
            'percent_off' => 15,
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $coupon = Coupon::query()->matchingCode('NEWCODE')->firstOrFail();

    expect($coupon->percent_off)->toBe(15)
        ->and($coupon->amount_off_cents)->toBeNull()
        ->and($coupon->type())->toBe(CouponType::Percent);
});

/**
 * Money typed as a decimal, stored as cents, and never through a float —
 * (float) '49.95' * 100 is not reliably 4995 (§4 rule 6).
 */
it('creates a fixed coupon and stores the amount in cents', function (): void {
    Livewire\Livewire::actingAs(userWithRole(UserRole::Finance))
        ->test(CreateCoupon::class)
        ->fillForm([
            'code' => 'FIFTY',
            'discount_type' => CouponType::Fixed->value,
            'amount_off' => '49.95',
            'minimum_spend' => '150.00',
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $coupon = Coupon::query()->matchingCode('FIFTY')->firstOrFail();

    expect($coupon->amount_off_cents)->toBe(4995)
        ->and($coupon->percent_off)->toBeNull()
        ->and($coupon->minimum_spend_cents)->toBe(15000);
});

it('refuses a window that closes before it opens', function (): void {
    Livewire\Livewire::actingAs(userWithRole(UserRole::Finance))
        ->test(CreateCoupon::class)
        ->fillForm([
            'code' => 'BACKWARDS',
            'discount_type' => CouponType::Percent->value,
            'percent_off' => 10,
            'starts_at' => now()->addWeek(),
            'ends_at' => now()->addDay(),
        ])
        ->call('create')
        ->assertHasFormErrors(['ends_at']);
});

it('refuses a duplicate code', function (): void {
    Coupon::factory()->create(['code' => 'TAKEN']);

    Livewire\Livewire::actingAs(userWithRole(UserRole::Finance))
        ->test(CreateCoupon::class)
        ->fillForm([
            'code' => 'TAKEN',
            'discount_type' => CouponType::Percent->value,
            'percent_off' => 10,
        ])
        ->call('create')
        ->assertHasFormErrors(['code']);
});
