<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Filament\Pages\Reports\Concerns\PicksAPeriod;
use App\Models\Certificate;
use App\Reports\AccreditationReport;
use App\Reports\AccreditationTotal;
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
 * Credits awarded, ready to report to the accrediting bodies.
 *
 * Opens on last month rather than month-to-date, because that is the period a
 * submission is actually made for — nobody reports a month that has not
 * finished.
 *
 * The export is per body, because each one is submitted separately. **The
 * column layout here is a sensible default and not a body's prescribed
 * format**: §12 has HRCI and SHRM's requirements as fixed and non-negotiable,
 * and the actual templates were not available when this was built. Swapping the
 * columns is a change to AccreditationReport, not to this screen.
 */
class Accreditation extends Page implements HasForms
{
    use InteractsWithForms;
    use PicksAPeriod;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static string|null|UnitEnum $navigationGroup = 'Reports';

    protected static ?int $navigationSort = 30;

    protected static ?string $navigationLabel = 'Accreditation';

    protected static ?string $title = 'Credits awarded';

    protected static ?string $slug = 'reports/accreditation';

    protected string $view = 'filament.pages.reports.accreditation';

    public function form(Schema $schema): Schema
    {
        return $this->periodForm($schema);
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view_any_certificate') ?? false;
    }

    /** A submission covers a month that has finished. */
    protected function defaultPreset(): string
    {
        return 'last_month';
    }

    protected function getHeaderActions(): array
    {
        return collect($this->getSummary())
            ->map(fn (AccreditationTotal $body): Action => Action::make('export'.$body->code)
                ->label('Export '.$body->code)
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->visible(fn (): bool => auth()->user()?->can('export_data') ?? false)
                ->action(fn (): StreamedResponse => $this->export($body->code)))
            ->all();
    }

    public function getReport(): AccreditationReport
    {
        return new AccreditationReport($this->getPeriod());
    }

    /** @return array<int, AccreditationTotal> */
    public function getSummary(): array
    {
        return $this->getReport()->summary()->all();
    }

    /** @return Collection<int, Certificate> */
    public function getAwarded(): Collection
    {
        return $this->getReport()->awarded();
    }

    /** @return Collection<int, Certificate> */
    public function getRevoked(): Collection
    {
        return $this->getReport()->revoked();
    }

    /**
     * One body's awards, in the shape a submission wants.
     *
     * Everything here is read from the certificate's own snapshot rather than
     * from the webinar or the learner's account — a name changed since must not
     * change what was already reported.
     */
    private function export(string $code): StreamedResponse
    {
        $awarded = $this->getReport()->awarded($code);

        return Csv::download(
            Csv::filename('credits-'.strtolower($code)),
            ['Learner', 'Email', 'Activity', 'Credit hours', 'Awarded', 'Certificate number'],
            function () use ($awarded): iterable {
                foreach ($awarded as $certificate) {
                    yield [
                        $certificate->learner_name,
                        $certificate->user?->email,
                        $certificate->webinar_title,
                        (string) $certificate->credits,
                        $certificate->issued_at->format('Y-m-d'),
                        $certificate->serial,
                    ];
                }
            },
        );
    }
}
