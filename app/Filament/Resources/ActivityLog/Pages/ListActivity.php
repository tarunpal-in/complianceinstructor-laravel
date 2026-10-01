<?php

declare(strict_types=1);

namespace App\Filament\Resources\ActivityLog\Pages;

use App\Filament\Resources\ActivityLog\ActivityResource;
use App\Support\ActivityNarrator;
use App\Support\Csv;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListActivity extends ListRecords
{
    protected static string $resource = ActivityResource::class;

    protected function getHeaderActions(): array
    {
        return [$this->exportAction()];
    }

    /**
     * Grouped by the part of the business, not by model.
     *
     * The audit questions this business will be asked are "who touched money"
     * and "who changed somebody's access". Neither is answerable by scrolling
     * one list of everything, and both are a tab away here.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $tabs = ['all' => Tab::make('Everything')];

        foreach (ActivityNarrator::GROUPS as $group) {
            $logNames = ActivityNarrator::logNamesInGroup($group);

            $tabs[strtolower($group)] = Tab::make($group)
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('log_name', $logNames));
        }

        return $tabs;
    }

    /**
     * The log as a CSV, for whoever asked the question.
     *
     * Gated on `export_data` on top of `view_activity_log`: reading the trail
     * and walking away with a file of who did what are different privileges,
     * the same split every other export on this panel uses.
     *
     * It exports the tab and filters on screen, so "everything Sarah did in
     * March" is three controls and a button rather than a database query.
     */
    private function exportAction(): Action
    {
        return Action::make('export')
            ->label('Export CSV')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->visible(fn (): bool => auth()->user()?->can('export_data') ?? false)
            ->action(fn (): StreamedResponse => $this->export());
    }

    private function export(): StreamedResponse
    {
        $query = $this->getFilteredTableQuery();

        return Csv::download(
            Csv::filename('activity-log'),
            ['When', 'Who', 'Kind', 'Event', 'What happened', 'Record', 'Changes'],
            function () use ($query): iterable {
                // lazy() rather than cursor(): cursor cannot eager load, and
                // both the causer and the subject are morphs read per row.
                foreach ($query->with(['causer', 'subject'])->lazy(200) as $entry) {
                    /** @var Activity $entry */
                    yield [
                        $entry->created_at?->format('Y-m-d H:i:s'),
                        ActivityNarrator::causerName($entry),
                        ActivityNarrator::kind($entry),
                        (string) $entry->event,
                        ActivityNarrator::describe($entry),
                        ActivityNarrator::recordLabel($entry),
                        self::flattenChanges($entry),
                    ];
                }
            },
        );
    }

    /** Before and after on one line, so a spreadsheet cell can hold it. */
    private static function flattenChanges(Activity $entry): string
    {
        return collect(ActivityNarrator::changes($entry))
            ->map(fn (array $change): string => "{$change['attribute']}: {$change['from']} → {$change['to']}")
            ->implode('; ');
    }
}
