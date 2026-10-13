<?php

declare(strict_types=1);

namespace App\Actions\Entitlements;

use App\Enums\EntitlementSource;
use App\Models\Order;
use App\Models\OrderAttendee;
use App\Models\OrderItem;

/**
 * Grants the access a paid order bought — CLAUDE.md §5.2 / §3's hard gate.
 *
 * One entitlement per claimed seat × each format the line sold, written to the
 * ledger with source Order and the order id — the one place access is recorded,
 * so a refund later revokes exactly these rows. Each grant is idempotent
 * (GrantEntitlement keys on the unique tuple and restores a revoked row rather
 * than duplicating), so a replayed webhook or a return-plus-webhook race grants
 * nothing twice.
 *
 * A seat with no user is a guest seat, unclaimed: there is no one to grant to
 * yet, so it is skipped and granted when the seat is claimed by registering.
 */
class GrantEntitlementsForOrder
{
    public function __construct(private readonly GrantEntitlement $grant) {}

    public function __invoke(Order $order): void
    {
        $order->loadMissing(['items.formats', 'items.webinar', 'items.attendees.user']);

        foreach ($order->items as $item) {
            $webinar = $item->webinar;

            // A package line without a single webinar grants nothing here; that
            // path arrives with packages (not v1).
            if ($webinar === null) {
                continue;
            }

            $formats = $item->formatEnums();

            foreach ($item->attendees as $attendee) {
                $this->grantSeat($attendee, $item, $webinar, $formats, $order);
            }
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<int, \App\Enums\Format>  $formats
     */
    private function grantSeat(OrderAttendee $attendee, OrderItem $item, \App\Models\Webinar $webinar, $formats, Order $order): void
    {
        $user = $attendee->user;

        if ($user === null) {
            return;
        }

        $primaryId = $attendee->entitlement_id;

        foreach ($formats as $format) {
            $entitlement = ($this->grant)($user, $webinar, $format, EntitlementSource::Order, $order->getKey());
            // Link the seat to one of its entitlements, for tracing from the
            // order screen; the ledger holds the full set.
            $primaryId ??= $entitlement->getKey();
        }

        if ($primaryId !== $attendee->entitlement_id) {
            $attendee->update(['entitlement_id' => $primaryId]);
        }
    }
}
