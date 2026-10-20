<?php

declare(strict_types=1);

namespace App\Filament\Account\Pages;

use App\Enums\EntitlementSource;
use App\Models\Entitlement;
use App\Models\Order;
use App\Models\User;
use App\Models\Webinar;
use App\Models\WebinarHandout;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

/**
 * The member's purchase history and invoices — CLAUDE.md §6 / P5.
 *
 * Scoped to the account, so a customer sees only their own orders — the IDOR the
 * predecessor had on every invoice (§14 #10) cannot recur, because the query is
 * keyed on the signed-in user and the invoice link is a signed, expiring URL
 * minted per order (§9).
 */
class MyOrders extends Page
{
    protected string $view = 'filament.account.pages.my-orders';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $title = 'Orders';

    protected static ?string $navigationLabel = 'Orders';

    protected static ?int $navigationSort = 7;

    /**
     * This member's orders, newest first.
     *
     * @return Collection<int, Order>
     */
    public function orders(): Collection
    {
        /** @var User $user */
        $user = auth()->user();

        return Order::query()
            ->where('user_id', $user->getKey())
            ->with(['items', 'payments.refunds'])
            ->latest('placed_at')
            ->latest('id')
            ->get();
    }

    /**
     * The study materials reachable through each order, keyed by order id.
     *
     * Derived from the entitlements the order granted rather than its line items,
     * so a package expands into its webinars for free and only materials the
     * buyer can actually open are shown — the handout gate (§9) wants an active
     * grant, so a refunded or expired one drops out and no dead link is offered.
     * Handouts come with every format (confirmed with the client), so a past
     * live-only purchase still surfaces its materials here, where the order
     * itself never vanishes.
     *
     * @return SupportCollection<int, SupportCollection<int, WebinarHandout>>
     */
    public function handoutsByOrder(): SupportCollection
    {
        /** @var User $user */
        $user = auth()->user();

        return Entitlement::query()
            ->where('user_id', $user->getKey())
            ->fromSource(EntitlementSource::Order)
            ->active()
            ->with(['webinar.handouts' => fn ($query) => $query->where('is_active', true)])
            ->get()
            ->groupBy('source_id')
            ->map(static fn (Collection $grants): SupportCollection => $grants
                ->map(static fn (Entitlement $grant): ?Webinar => $grant->webinar)
                ->filter()
                ->unique(static fn (Webinar $webinar): int => $webinar->getKey())
                ->flatMap(static fn (Webinar $webinar): SupportCollection => $webinar->handouts)
                ->unique(static fn (WebinarHandout $handout): int => $handout->getKey())
                ->values());
    }
}
