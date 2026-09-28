<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Filament\Pages\Reports\Concerns\PicksAPeriod;
use App\Reports\EngagementReport;
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
 * The mailing list, the inbox and the blog, on one screen.
 *
 * None of these is worth a report of its own — this is the smallest thing on
 * §13's descope ladder for a reason. Together they answer whether anything
 * being published is bringing anybody in, which is the question the marketing
 * side of this business asks and cannot currently answer.
 *
 * Gated on `view_reports` like the rest of this section, though nothing here
 * is money. The alternative was inventing a permission for it, and a role that
 * cannot see revenue has no particular claim on the funnel above it either.
 */
class Engagement extends Page implements HasForms
{
    use InteractsWithForms;
    use PicksAPeriod;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|null|UnitEnum $navigationGroup = 'Reports';

    protected static ?int $navigationSort = 60;

    protected static ?string $navigationLabel = 'Engagement';

    protected static ?string $title = 'Engagement';

    protected static ?string $slug = 'reports/engagement';

    protected string $view = 'filament.pages.reports.engagement';

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

    public function getReport(): EngagementReport
    {
        return new EngagementReport($this->getPeriod());
    }

    /** @return Collection<int, ReportRow> */
    public function getSignupsBySource(): Collection
    {
        return $this->getReport()->signupsBySource();
    }

    /** @return Collection<int, ReportRow> */
    public function getInquiriesBySource(): Collection
    {
        return $this->getReport()->inquiriesBySource();
    }

    /** The wait, in words a person reads rather than a number of hours. */
    public function describeWait(): string
    {
        $hours = $this->getReport()->medianResponseHours();

        return match (true) {
            $hours === null => 'Nothing answered yet',
            $hours < 1 => 'Under an hour',
            $hours < 48 => round($hours).' hours',
            default => round($hours / 24, 1).' days',
        };
    }

    private function export(): StreamedResponse
    {
        $report = $this->getReport();
        $period = $this->getPeriod();

        return Csv::download(
            Csv::filename('engagement'),
            ['Engagement', $period->describe()],
            function () use ($report): iterable {
                yield [];
                yield ['Mailing list'];
                yield ['On the list now', (string) $report->listSize()];
                yield ['Joined', (string) $report->joined()];
                yield ['Left', (string) $report->left()];
                yield ['Net change', (string) $report->netListChange()];
                yield ['Deliverability problems', (string) $report->deliverabilityProblems()];

                yield [];
                yield ['Signups by source'];

                foreach ($report->signupsBySource() as $row) {
                    yield [$row->label, (string) $row->cents];
                }

                yield [];
                yield ['Inbox'];
                yield ['Received', (string) $report->inquiriesReceived()];
                yield ['Answered', (string) $report->inquiriesAnswered()];
                yield ['Still waiting', (string) $report->inquiriesStillWaiting()];
                yield ['Median wait, hours', (string) ($report->medianResponseHours() ?? '')];

                yield [];
                yield ['Inquiries by source'];

                foreach ($report->inquiriesBySource() as $row) {
                    yield [$row->label, (string) $row->cents];
                }

                yield [];
                yield ['Blog'];
                yield ['Articles published', (string) $report->postsPublished()];
                yield ['Comments received', (string) $report->commentsReceived()];
                yield ['Comments published', (string) $report->commentsPublished()];
                yield ['Comments waiting', (string) $report->commentsWaiting()];
            },
        );
    }
}
