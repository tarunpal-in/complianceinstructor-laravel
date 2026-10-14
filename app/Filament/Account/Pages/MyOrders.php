<?php

declare(strict_types=1);

namespace App\Filament\Account\Pages;

use App\Models\Order;
use App\Models\User;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;

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

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static ?string $title = 'My orders';

    protected static ?string $navigationLabel = 'My orders';

    protected static ?int $navigationSort = 3;

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
}
