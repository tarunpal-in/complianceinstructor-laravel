<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Reports\OperationsSnapshot;
use App\Reports\SalesReport;
use App\Support\Money;
use App\Support\Period;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The money strip at the top of the dashboard.
 *
 * Four figures, chosen because each one changes what somebody does next: what
 * came in today, how the month is going against the last one, what is owed, and
 * what is coming up. Anything that does not change a decision before lunch
 * belongs on the Sales report instead.
 *
 * Gated on `view_reports`, so Support and Content Editor see the dashboard
 * without seeing revenue.
 */
class RevenueOverview extends StatsOverviewWidget
{
    protected static ?int $sort = -20;

    /**
     * Rendered with the page. These are four indexed sums; lazy-loading them
     * costs a round trip and a layout shift to save nothing.
     */
    protected static bool $isLazy = false;

    public static function canView(): bool
    {
        return auth()->user()?->can('view_reports') ?? false;
    }

    protected function getStats(): array
    {
        $today = new SalesReport(Period::preset('today'));
        $month = new SalesReport(Period::preset('mtd'));
        $operations = new OperationsSnapshot;

        return [
            Stat::make('Taken today', Money::format($today->netCents()))
                ->description($this->orderCountDescription($today->orderCount()))
                ->color($today->netCents() > 0 ? 'success' : 'gray'),

            $this->monthStat($month),

            $this->outstandingStat($operations),

            Stat::make('Webinars this week', (string) $operations->webinarsThisWeek()->count())
                ->description('Running in the next seven days')
                ->color('gray'),
        ];
    }

    /**
     * This month against the same point last month.
     *
     * Against the *same point*, not against the whole of last month — on the
     * 3rd, comparing three days to thirty-one would read as a collapse every
     * month. Period::previous() handles the calendar step; the partial
     * comparison is done here by cutting last month off at today's day number.
     */
    private function monthStat(SalesReport $month): Stat
    {
        $net = $month->netCents();
        $lastMonth = new SalesReport($this->sameStretchOfLastMonth());
        $before = $lastMonth->netCents();

        $stat = Stat::make('This month', Money::format($net));

        if ($before <= 0) {
            return $stat->description('Nothing to compare with')->color('gray');
        }

        $change = (int) round((($net - $before) / $before) * 100);

        return $stat
            ->description(sprintf(
                '%s%d%% against the same point last month',
                $change >= 0 ? '+' : '',
                $change,
            ))
            ->descriptionIcon($change >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
            ->color($change >= 0 ? 'success' : 'danger');
    }

    private function outstandingStat(OperationsSnapshot $operations): Stat
    {
        $outstanding = $operations->outstandingCents();
        $overdue = $operations->overdue();

        return Stat::make('Owed to you', Money::format($outstanding))
            ->description($overdue > 0
                ? trans_choice(':count invoice|:count invoices', $overdue, ['count' => $overdue])
                    .' more than '.OperationsSnapshot::OVERDUE_AFTER_DAYS.' days old'
                : 'Nothing overdue')
            ->color($overdue > 0 ? 'danger' : 'gray');
    }

    /** Last month, cut off at the same day of the month as today. */
    private function sameStretchOfLastMonth(): Period
    {
        $now = Period::now();
        $start = $now->subMonthNoOverflow()->startOfMonth();
        $end = $start->addDays($now->day - 1)->endOfDay();

        return Period::between($start->format('Y-m-d'), min($end, $start->endOfMonth())->format('Y-m-d'));
    }

    private function orderCountDescription(int $orders): string
    {
        return $orders === 0
            ? 'No orders paid yet today'
            : trans_choice(':count order paid|:count orders paid', $orders, ['count' => $orders]);
    }
}
