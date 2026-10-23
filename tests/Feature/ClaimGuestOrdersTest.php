<?php

declare(strict_types=1);

use App\Actions\Orders\ClaimGuestOrders;
use App\Enums\Format;
use App\Enums\OrderStatus;
use App\Models\Entitlement;
use App\Models\Order;
use App\Models\User;
use App\Models\Webinar;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Verified;

/*
 * Guest-claim-on-verification — the migration's §5.2 recovery path. A buyer who
 * registers and verifies the email their guest order was placed under (or an
 * imported customer logging in with a verified account) has those orders linked
 * and the access they bought granted. Gated on proven email ownership.
 */

/**
 * A guest order (no account) under $email buying $webinar in $formats.
 *
 * @param  array<int, Format>  $formats
 */
function guestOrder(string $email, Webinar $webinar, array $formats, OrderStatus $status = OrderStatus::Paid): Order
{
    $order = Order::factory()->create([
        'user_id' => null,
        'status' => $status,
        'billing_snapshot' => ['email' => $email, 'name' => 'Guest Buyer'],
    ]);

    $item = $order->items()->create([
        'webinar_id' => $webinar->getKey(), 'description' => $webinar->title,
        'quantity' => 1, 'unit_price_cents' => 17900, 'line_total_cents' => 17900, 'seats_per_unit' => 1,
    ]);
    $item->attendees()->create(['name' => 'Guest Buyer', 'email' => $email]);

    foreach ($formats as $format) {
        $item->formats()->create(['format' => $format]);
    }

    return $order;
}

// The action --------------------------------------------------------------------

it('links the order, claims the seat and grants the access', function (): void {
    $user = User::factory()->create(['email' => 'buyer@example.com']);
    $webinar = Webinar::factory()->create();
    $order = guestOrder('buyer@example.com', $webinar, [Format::Live, Format::Recorded]);

    $claimed = app(ClaimGuestOrders::class)($user);

    expect($claimed)->toBe(1)
        ->and($order->fresh()->user_id)->toBe($user->getKey())
        ->and($order->items()->sole()->attendees()->sole()->user_id)->toBe($user->getKey())
        ->and(Entitlement::grants($user, $webinar, Format::Live))->toBeTrue()
        ->and(Entitlement::grants($user, $webinar, Format::Recorded))->toBeTrue();
});

it('matches the billing email case-insensitively', function (): void {
    $user = User::factory()->create(['email' => 'buyer@example.com']);
    $webinar = Webinar::factory()->create();
    guestOrder('Buyer@Example.com', $webinar, [Format::Recorded]);

    // Stored billing email is lower-cased by WooCommerce; the match is on the
    // normalised address.
    expect(app(ClaimGuestOrders::class)(User::factory()->create(['email' => 'nomatch@example.com'])))->toBe(0);
});

it('does not grant for an unsettled guest order but still links it', function (): void {
    $user = User::factory()->create(['email' => 'buyer@example.com']);
    $webinar = Webinar::factory()->create();
    $order = guestOrder('buyer@example.com', $webinar, [Format::Recorded], OrderStatus::Pending);

    app(ClaimGuestOrders::class)($user);

    expect($order->fresh()->user_id)->toBe($user->getKey())
        ->and(Entitlement::query()->count())->toBe(0);
});

it('revokes access for a fully refunded guest order', function (): void {
    $user = User::factory()->create(['email' => 'buyer@example.com']);
    $webinar = Webinar::factory()->create();
    guestOrder('buyer@example.com', $webinar, [Format::Recorded], OrderStatus::Refunded);

    app(ClaimGuestOrders::class)($user);

    expect(Entitlement::grants($user, $webinar, Format::Recorded))->toBeFalse()
        ->and(Entitlement::query()->count())->toBe(1); // the row exists, revoked
});

it('ignores a non-matching email and is idempotent', function (): void {
    $user = User::factory()->create(['email' => 'buyer@example.com']);
    $webinar = Webinar::factory()->create();
    guestOrder('someone-else@example.com', $webinar, [Format::Recorded]);
    guestOrder('buyer@example.com', $webinar, [Format::Recorded]);

    expect(app(ClaimGuestOrders::class)($user))->toBe(1)
        ->and(app(ClaimGuestOrders::class)($user))->toBe(0); // already linked
});

// The event wiring --------------------------------------------------------------

it('claims when the user verifies their email', function (): void {
    $user = User::factory()->create(['email' => 'buyer@example.com']);
    $webinar = Webinar::factory()->create();
    guestOrder('buyer@example.com', $webinar, [Format::Recorded]);

    event(new Verified($user));

    expect($user->orders()->count())->toBe(1)
        ->and(Entitlement::grants($user, $webinar, Format::Recorded))->toBeTrue();
});

it('claims on login of a verified account, but not an unverified one', function (): void {
    $webinar = Webinar::factory()->create();

    $verified = User::factory()->create(['email' => 'v@example.com', 'email_verified_at' => now()]);
    guestOrder('v@example.com', $webinar, [Format::Recorded]);
    event(new Login('web', $verified, false));
    expect($verified->orders()->count())->toBe(1);

    $unverified = User::factory()->unverified()->create(['email' => 'u@example.com']);
    guestOrder('u@example.com', $webinar, [Format::Recorded]);
    event(new Login('web', $unverified, false));
    expect($unverified->orders()->count())->toBe(0); // email not proven yet
});
