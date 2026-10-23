<?php

declare(strict_types=1);

use App\Enums\EntitlementSource;
use App\Enums\Format;
use App\Enums\OrderStatus;
use App\Import\Importers\EntitlementImporter;
use App\Models\Entitlement;
use App\Models\ImportMap;
use App\Models\Order;
use App\Models\User;
use App\Models\Webinar;

/*
 * Phase 4 — the access ledger, derived from the imported orders. The §5.2/§9
 * money-and-access-critical step, so grant, multi-format fan-out, refund→revoke,
 * guest handling and idempotency are all asserted.
 */

/**
 * An imported order for $user buying $webinar in the given formats.
 *
 * @param  array<int, Format>  $formats
 */
function importedOrder(?User $user, Webinar $webinar, array $formats, OrderStatus $status, int $legacyId): Order
{
    $order = Order::factory()->create([
        'user_id' => $user?->getKey(),
        'status' => $status,
        'number' => 'WC-'.$legacyId,
    ]);

    $item = $order->items()->create([
        'webinar_id' => $webinar->getKey(),
        'description' => $webinar->title,
        'quantity' => 1,
        'unit_price_cents' => 17900,
        'line_total_cents' => 17900,
        'seats_per_unit' => 1,
    ]);

    foreach ($formats as $format) {
        $item->formats()->create(['format' => $format]);
    }

    ImportMap::remember('order', $legacyId, $order);

    return $order;
}

// -----------------------------------------------------------------------------

it('grants the buyer one entitlement per format a paid line sold', function (): void {
    $user = User::factory()->create();
    $webinar = Webinar::factory()->create();
    importedOrder($user, $webinar, [Format::Live, Format::Recorded], OrderStatus::Paid, 10862);

    (new EntitlementImporter)->run();

    expect(Entitlement::grants($user, $webinar, Format::Live))->toBeTrue()
        ->and(Entitlement::grants($user, $webinar, Format::Recorded))->toBeTrue()
        ->and(Entitlement::grants($user, $webinar, Format::Transcript))->toBeFalse();

    $grant = Entitlement::query()->where('user_id', $user->getKey())->where('format', Format::Live)->sole();
    expect($grant->source_type)->toBe(EntitlementSource::Order);
});

it('revokes the grants of a fully refunded order', function (): void {
    $user = User::factory()->create();
    $webinar = Webinar::factory()->create();
    importedOrder($user, $webinar, [Format::Recorded], OrderStatus::Refunded, 10445);

    (new EntitlementImporter)->run();

    // The row exists for the audit trail, but access is gone.
    expect(Entitlement::query()->where('user_id', $user->getKey())->count())->toBe(1)
        ->and(Entitlement::grants($user, $webinar, Format::Recorded))->toBeFalse()
        ->and(Entitlement::query()->sole()->isRevoked())->toBeTrue();
});

it('leaves a partially refunded order standing', function (): void {
    $user = User::factory()->create();
    $webinar = Webinar::factory()->create();
    importedOrder($user, $webinar, [Format::Recorded], OrderStatus::PartiallyRefunded, 999);

    (new EntitlementImporter)->run();

    expect(Entitlement::grants($user, $webinar, Format::Recorded))->toBeTrue();
});

it('grants nothing for a guest, pending, failed or cancelled order', function (): void {
    $webinar = Webinar::factory()->create();
    $buyer = User::factory()->create();

    importedOrder(null, $webinar, [Format::Recorded], OrderStatus::Paid, 1); // guest
    importedOrder($buyer, $webinar, [Format::Recorded], OrderStatus::Pending, 2);
    importedOrder($buyer, $webinar, [Format::Recorded], OrderStatus::Failed, 3);
    importedOrder($buyer, $webinar, [Format::Recorded], OrderStatus::Cancelled, 4);

    (new EntitlementImporter)->run();

    expect(Entitlement::query()->count())->toBe(0);
});

it('recovers access for a guest order whose billing email has an account', function (): void {
    $user = User::factory()->create(['email' => 'guest@buyer.com']);
    $webinar = Webinar::factory()->create();

    $order = Order::factory()->create([
        'user_id' => null, 'status' => OrderStatus::Paid, 'number' => 'WC-guest',
        'billing_snapshot' => ['email' => 'Guest@Buyer.com', 'name' => 'Guest Buyer'],
    ]);
    $order->items()->create([
        'webinar_id' => $webinar->getKey(), 'description' => 'x',
        'quantity' => 1, 'unit_price_cents' => 1000, 'line_total_cents' => 1000, 'seats_per_unit' => 1,
    ])->formats()->create(['format' => Format::Recorded]);
    ImportMap::remember('order', 5555, $order);

    (new EntitlementImporter)->run();

    expect(Entitlement::grants($user, $webinar, Format::Recorded))->toBeTrue();
});

it('is idempotent — a second run grants nothing new', function (): void {
    $user = User::factory()->create();
    $webinar = Webinar::factory()->create();
    importedOrder($user, $webinar, [Format::Live, Format::Recorded], OrderStatus::Paid, 10862);

    (new EntitlementImporter)->run();
    (new EntitlementImporter)->run();

    expect(Entitlement::query()->where('user_id', $user->getKey())->count())->toBe(2);
});

it('only processes imported orders, not pre-existing ones', function (): void {
    $user = User::factory()->create();
    $webinar = Webinar::factory()->create();

    // An order with no import_maps row (e.g. a seeded demo order).
    $order = Order::factory()->create(['user_id' => $user->getKey(), 'status' => OrderStatus::Paid]);
    $order->items()->create([
        'webinar_id' => $webinar->getKey(), 'description' => 'x',
        'quantity' => 1, 'unit_price_cents' => 1000, 'line_total_cents' => 1000, 'seats_per_unit' => 1,
    ])->formats()->create(['format' => Format::Recorded]);

    (new EntitlementImporter)->run();

    expect(Entitlement::query()->count())->toBe(0);
});
