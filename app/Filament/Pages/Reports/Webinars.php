<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Filament\Pages\Reports\Concerns\PicksAPeriod;
use App\Reports\WebinarPerformance;
use App\Reports\WebinarRow;
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
 * Which webinars are worth running again.
 *
 * Sales are counted inside the chosen period; views and certificates are
 * counted for all time. See WebinarPerformance — the short version is that a
 * recording sold in March is still being watched in June, and charging those
 * views to March would make every recent webinar look unwatched.
 */
class Webinars extends Page implements HasForms
{
    use InteractsWithForms;
    use PicksAPeriod;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected static string|null|UnitEnum $navigationGroup = 'Reports';

    protected static ?int $navigationSort = 40;

    protected static ?string $navigationLabel = 'Webinars';

    protected static ?string $title = 'Webinar performance';

    protected static ?string $slug = 'reports/webinars';

    protected string $view = 'filament.pages.reports.webinars';

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
                ->label('Export')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->visible(fn (): bool => auth()->user()?->can('export_data') ?? false)
                ->action(fn (): StreamedResponse => $this->export()),
        ];
    }

    /** @return Collection<int, WebinarRow> */
    public function getRows(): Collection
    {
        return (new WebinarPerformance($this->getPeriod()))->rows();
    }

    private function export(): StreamedResponse
    {
        $rows = $this->getRows();

        return Csv::download(
            Csv::filename('webinar-performance'),
            ['Webinar', 'Speaker', 'Industry', 'Seats sold', 'Revenue', 'Views', 'Completed', 'Completion %', 'Certificates'],
            function () use ($rows): iterable {
                foreach ($rows as $row) {
                    yield [
                        $row->webinar->title,
                        $row->webinar->speaker?->name,
                        $row->webinar->industry?->name,
                        (string) $row->seats,
                        Csv::money($row->revenueCents),
                        (string) $row->views,
                        (string) $row->completed,
                        $row->describeCompletion(),
                        (string) $row->certificates,
                    ];
                }
            },
        );
    }
}
