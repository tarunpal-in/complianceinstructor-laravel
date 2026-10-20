<?php

declare(strict_types=1);

namespace App\Filament\Account\Pages;

use App\Models\Subscription;
use App\Models\User;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * The member's plan at a glance — CLAUDE.md §6 / P5.
 *
 * A read surface inside the account panel: the plan, its state and the allowance
 * left this window, all from the subscription's own snapshot (§5.2). Changing the
 * plan — cancel, resume, upgrade — stays on the existing /membership/manage page
 * rather than being rebuilt here, so there is one place those actions live.
 */
class MyMembership extends Page
{
    protected string $view = 'filament.account.pages.my-membership';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $title = 'Memberships';

    protected static ?string $navigationLabel = 'Memberships';

    protected static ?int $navigationSort = 6;

    public function membership(): ?Subscription
    {
        /** @var User $user */
        $user = auth()->user();

        $subscription = $user->membership();

        $subscription?->loadMissing('entitlements', 'plan');

        return $subscription;
    }
}
