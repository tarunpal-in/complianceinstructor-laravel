<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Dashboard overview.
 *
 * Deliberately limited to figures that are real today. CLAUDE.md §8 puts the
 * revenue, MRR, churn, funnel and attendance widgets in P8, over pre-aggregated
 * daily tables — this is not a placeholder for those, it is the account-level
 * strip that sits above them.
 */
class PlatformOverview extends StatsOverviewWidget
{
    protected static ?int $sort = -10;

    protected function getStats(): array
    {
        $total = User::query()->count();
        $admins = User::query()->has('roles')->count();

        return [
            Stat::make('Registered users', number_format($total))
                ->description('All accounts, customers and staff')
                ->descriptionIcon('heroicon-m-users')
                ->color('primary'),

            Stat::make('Admin users', number_format($admins))
                ->description('Hold one of the four admin roles')
                ->descriptionIcon('heroicon-m-shield-check')
                ->color('success'),

            Stat::make('Customers', number_format($total - $admins))
                ->description('No admin role — cannot reach this panel')
                ->descriptionIcon('heroicon-m-shopping-bag')
                ->color('gray'),
        ];
    }

    public static function canView(): bool
    {
        return auth()->user()?->can('view_any_user') ?? false;
    }
}
