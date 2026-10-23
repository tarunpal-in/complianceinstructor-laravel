<?php

declare(strict_types=1);

namespace App\Import\Importers;

use App\Actions\Entitlements\GrantEntitlement;
use App\Actions\Entitlements\RevokeEntitlement;
use App\Enums\EntitlementSource;
use App\Enums\OrderStatus;
use App\Import\Importer;
use App\Import\ImportResult;
use App\Models\ImportMap;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * The access ledger, derived from the imported orders — Phase 4, §5.2 / §9.
 *
 * WooCommerce had no entitlements; access is synthesised from what was bought.
 * For every settled order, the buyer is granted one entitlement per webinar ×
 * each format the line sold — written through the app's own GrantEntitlement, so
 * a migrated grant is indistinguishable from one a live purchase would make
 * (idempotent on the unique tuple). A fully refunded order grants then revokes,
 * exactly as "a refund revokes access by writing revoked_at"; a partial refund
 * leaves access standing (it cannot be attributed to a seat). Pending, failed
 * and cancelled orders took no money and grant nothing.
 *
 * Access goes to the buyer, not the named attendees: in the old store the
 * customer held the downloads, and the attendee fields were registration names
 * for the live session, not accounts. A guest order has no account to grant to
 * and is skipped.
 */
class EntitlementImporter extends Importer
{
    public function label(): string
    {
        return 'Entitlements';
    }

    public function run(): ImportResult
    {
        $result = new ImportResult;

        $grant = app(GrantEntitlement::class);
        $revoke = app(RevokeEntitlement::class);

        $revoked = 0;
        $guests = 0;

        $orderIds = ImportMap::query()->where('legacy_type', 'order')->pluck('model_id');

        Order::query()
            ->whereIn('id', $orderIds)
            ->with(['user', 'items.formats', 'items.webinar'])
            ->chunkById(200, function (Collection $orders) use ($grant, $revoke, $result, &$revoked, &$guests): void {
                foreach ($orders as $order) {
                    if (! in_array($order->status, [OrderStatus::Paid, OrderStatus::PartiallyRefunded, OrderStatus::Refunded], true)) {
                        $result->skipped();

                        continue;
                    }

                    // The buyer: the linked account, or — for a guest checkout —
                    // an account under the same billing email (the same person,
                    // who in WooCommerce reached their purchase by that email).
                    $user = $order->user ?? $this->userByBillingEmail($order);

                    if ($user === null) {
                        $guests++;
                        $result->skipped();

                        continue;
                    }

                    $isRefunded = $order->status === OrderStatus::Refunded;

                    foreach ($order->items as $item) {
                        $webinar = $item->webinar;

                        if ($webinar === null) {
                            continue;
                        }

                        foreach ($item->formatEnums() as $format) {
                            $entitlement = $grant(
                                $user,
                                $webinar,
                                $format,
                                EntitlementSource::Order,
                                $order->getKey(),
                                null,
                                'Migrated from WooCommerce order '.$order->number,
                            );

                            $entitlement->wasRecentlyCreated ? $result->created() : $result->updated();

                            if ($isRefunded && ! $entitlement->isRevoked()) {
                                $revoke($entitlement, 'Order refunded (migrated)', null);
                                $revoked++;
                            }
                        }
                    }
                }
            });

        $result->note("{$revoked} grants revoked for refunded orders; {$guests} guest orders skipped (no matching account).");

        return $result;
    }

    /** The account matching a guest order's billing email, if one exists. */
    private function userByBillingEmail(Order $order): ?User
    {
        $email = is_array($order->billing_snapshot)
            ? strtolower(trim((string) ($order->billing_snapshot['email'] ?? '')))
            : '';

        return $email !== '' ? User::query()->where('email', $email)->first() : null;
    }
}
