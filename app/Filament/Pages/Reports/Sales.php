<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Filament\Pages\Reports\Concerns\PicksAPeriod;
use App\Reports\ReportRow;
use App\Reports\SalesReport;
use App\Support\Csv;
use App\Support\Period;
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
 * What the business took, over a stretch of time somebody chooses.
 *
 * The page is thin on purpose: every number comes from SalesReport, which is a
 * plain class with no Filament in it and its own tests. A report whose
 * arithmetic lives inside a Livewire component is a report nobody can check.
 *
 * The three judgements behind the figures — revenue counted on the payment
 * date, refunds charged to the period they happened in, multi-format lines
 * split evenly — are stated on SalesReport and repeated in the footnote on this
 * page, because a number whose rules are invisible gets argued with.
 */
class Sales extends Page implements HasForms
{
    use InteractsWithForms;
    use PicksAPeriod;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|null|UnitEnum $navigationGroup = 'Reports';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Sales';

    protected static ?string $title = 'Sales';

    /*
     * Filament takes the slug from the class name, not the namespace, so
     * without this the page would live at /admin/sales — beside the
     * resources rather than under the group it belongs to.
     */
    protected static ?string $slug = 'reports/sales';

    protected string $view = 'filament.pages.reports.sales';

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

    // What the view reads ------------------------------------------------------------

    public function getReport(): SalesReport
    {
        return new SalesReport($this->getPeriod());
    }

    /** @return array<string, int> */
    public function getHeadline(): array
    {
        return $this->getReport()->headline();
    }

    /** @return array<string, int> */
    public function getComparison(): array
    {
        return (new SalesReport($this->getPeriod()->previous()))->headline();
    }

    /**
     * The breakdowns, in the order somebody reads them.
     *
     * Over time first, because the shape of the period is the first question;
     * then what sold, then who sold it, then how it was paid for.
     *
     * @return array<string, Collection<int, ReportRow>>
     */
    public function getBreakdowns(): array
    {
        $report = $this->getReport();

        return [
            'Over time' => $report->overTime(),
            'By format' => $report->byFormat(),
            'By webinar' => $report->byWebinar(),
            'By industry' => $report->byIndustry(),
            'By speaker' => $report->bySpeaker(),
            'By gateway' => $report->byGateway(),
            'By coupon' => $report->byCoupon(),
        ];
    }

    /** How a figure moved against the period before, as a signed percentage. */
    public function change(int $now, int $before): ?int
    {
        if ($before === 0) {
            return null;
        }

        return (int) round((($now - $before) / abs($before)) * 100);
    }

    // Export ---------------------------------------------------------------------------

    /**
     * Every figure on the page, as one file.
     *
     * One file rather than a download per table: somebody exporting a sales
     * report wants the report, and seven separate CSVs is seven things to
     * reconcile. The blank line and heading between blocks is what makes it
     * readable once it is open in a spreadsheet.
     */
    private function export(): StreamedResponse
    {
        $period = $this->getPeriod();
        $headline = $this->getHeadline();
        $breakdowns = $this->getBreakdowns();

        return Csv::download(
            Csv::filename('sales-report'),
            ['Sales report', $period->describe()],
            function () use ($headline, $breakdowns): iterable {
                yield [];
                yield ['Headline'];
                yield ['Gross', Csv::money($headline['gross'])];
                yield ['Refunded', Csv::money($headline['refunded'])];
                yield ['Net', Csv::money($headline['net'])];
                yield ['Discount given', Csv::money($headline['discount'])];
                yield ['Tax', Csv::money($headline['tax'])];
                yield ['Orders', (string) $headline['orders']];
                yield ['Average order', Csv::money($headline['average'])];

                foreach ($breakdowns as $heading => $rows) {
                    yield [];
                    yield [$heading];

                    foreach ($rows as $row) {
                        yield [$row->label, Csv::money($row->cents)];
                    }
                }
            },
        );
    }
}
