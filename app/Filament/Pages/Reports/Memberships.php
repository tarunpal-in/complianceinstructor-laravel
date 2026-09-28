<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Filament\Pages\Reports\Concerns\PicksAPeriod;
use App\Models\Subscription;
use App\Reports\MembershipReport;
use App\Reports\ReportRow;
use App\Support\Csv;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * The recurring side: what it is worth, and who is about to leave.
 *
 * Half analysis and half a worklist. The renewals and the members running out
 * of allowance are both things somebody can act on this week, and putting them
 * on the same screen as MRR is deliberate — a churn figure with nobody to ring
 * is a number to feel bad about.
 */
class Memberships extends Page implements HasForms
{
    use InteractsWithForms;
    use PicksAPeriod;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    protected static string|null|UnitEnum $navigationGroup = 'Reports';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Memberships';

    protected static ?string $title = 'Memberships';

    protected static ?string $slug = 'reports/memberships';

    protected string $view = 'filament.pages.reports.memberships';

    public function form(Schema $schema): Schema
    {
        return $this->periodForm($schema);
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view_reports') ?? false;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label('Export figures')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->visible(fn (): bool => auth()->user()?->can('export_data') ?? false)
                ->action(fn (): StreamedResponse => $this->export()),
        ];
    }

    public function getReport(): MembershipReport
    {
        return new MembershipReport($this->getPeriod());
    }

    /** @return Collection<int, ReportRow> */
    public function getMrrByPlan(): Collection
    {
        return $this->getReport()->mrrByPlan();
    }

    /** @return Collection<int, Subscription> */
    public function getRenewalsDue(): Collection
    {
        return $this->getReport()->renewalsDue();
    }

    /** @return Collection<int, array{subscription: Subscription, format: string, used: int, remaining: int}> */
    public function getNearingLimit(): Collection
    {
        return $this->getReport()->nearingTheirLimit();
    }

    private function export(): StreamedResponse
    {
        $report = $this->getReport();
        $period = $this->getPeriod();

        return Csv::download(
            Csv::filename('memberships'),
            ['Membership report', $period->describe()],
            function () use ($report): iterable {
                yield [];
                yield ['Active members', (string) $report->activeMembers()];
                yield ['MRR', Csv::money($report->mrrCents())];
                yield ['ARR', Csv::money($report->arrCents())];
                yield ['Average per member', Csv::money($report->averageMrrCents())];
                yield ['Started in period', (string) $report->started()];
                yield ['Cancelled in period', (string) $report->cancelled()];
                yield ['Churn %', $report->churnPercent() === null ? 'No members at the start' : (string) $report->churnPercent()];

                yield [];
                yield ['MRR by plan'];

                foreach ($report->mrrByPlan() as $row) {
                    yield [$row->label, Csv::money($row->cents), $row->note];
                }
            },
        );
    }
}
