<?php

declare(strict_types=1);

namespace App\Filament\Resources\Inquiries\Pages;

use App\Enums\InquiryStatus;
use App\Filament\Resources\Inquiries\InquiryResource;
use App\Models\Inquiry;
use App\Support\InquiryCsv;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListInquiries extends ListRecords
{
    protected static string $resource = InquiryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->exportAction(),
            CreateAction::make()->label('Add an inquiry'),
        ];
    }

    /**
     * Untouched first, because that is the tab that costs money to ignore.
     *
     * "Mine" earns its place on a team of four: the register is only worked if
     * somebody can see their own list without building a filter every morning.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'untouched' => Tab::make('Nobody picked up')
                ->modifyQueryUsing(self::onlyUntouched(...))
                ->badge(fn (): int => Inquiry::query()->untouched()->count())
                ->badgeColor(InquiryStatus::New->color()),

            'mine' => Tab::make('Mine')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->where('assigned_to', auth()->id())
                    ->whereIn('status', [InquiryStatus::New, InquiryStatus::Working]))
                ->badge(fn (): int => Inquiry::query()
                    ->open()
                    ->where('assigned_to', auth()->id())
                    ->count()),

            'open' => Tab::make('Open')
                ->modifyQueryUsing(self::onlyOpen(...))
                ->badge(fn (): int => Inquiry::query()->open()->count()),

            'answered' => Tab::make('Answered')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->where('status', InquiryStatus::Answered))
                ->badgeColor(InquiryStatus::Answered->color()),

            /*
             * Derived, not a status. This tab asks the orders table whether
             * anything ever came of these people — see InquiryStatus for why there
             * is no `converted` value to filter on instead.
             */
            'converted' => Tab::make('Became customers')
                ->modifyQueryUsing(self::onlyConverted(...))
                ->badgeColor('success'),

            'all' => Tab::make('All'),
        ];
    }

    public function getDefaultActiveTab(): string
    {
        return 'untouched';
    }

    /**
     * The whole filtered list as a CSV — §6's "inquiry export".
     *
     * Streamed rather than queued: this is a few thousand rows at the outside,
     * and §4 rule 7's "anything slow is queued" is about PDFs and mail, not a
     * text file the browser starts receiving immediately. It exports what the
     * screen is currently showing, tab and filters included, because an export
     * button that silently ignores the filters is how the wrong list gets
     * emailed to a mailing house.
     *
     * Gated on `export_data` rather than on read access: being able to look at
     * an inquiry and being able to take every inquiry off the system are different
     * privileges, which is why §8 named that permission separately.
     */
    private function exportAction(): Action
    {
        return Action::make('export')
            ->label('Export CSV')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->visible(fn (): bool => auth()->user()?->can('export_data') ?? false)
            // The filtered query, not the table: whatever tab and filters are
            // on the screen are what comes out of the file.
            ->action(fn (): StreamedResponse => InquiryCsv::download($this->getFilteredTableQuery()));
    }

    /**
     * Named rather than inline: a tab is handed an untyped builder, where a
     * model scope is invisible to static analysis.
     *
     * @param  Builder<Inquiry>  $query
     */
    private static function onlyUntouched(Builder $query): void
    {
        $query->untouched();
    }

    /** @param  Builder<Inquiry>  $query */
    private static function onlyOpen(Builder $query): void
    {
        $query->open();
    }

    /** @param  Builder<Inquiry>  $query */
    private static function onlyConverted(Builder $query): void
    {
        $query->converted();
    }
}
