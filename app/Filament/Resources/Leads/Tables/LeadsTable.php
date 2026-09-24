<?php

declare(strict_types=1);

namespace App\Filament\Resources\Leads\Tables;

use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Models\Industry;
use App\Models\Lead;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The lead register.
 *
 * Built around one question — who has not been rung yet — because that is the
 * only thing on this screen that costs money to ignore. Everything else is
 * reference.
 */
class LeadsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    // Title and company, not the email: that is what tells
                    // somebody whether this lead is worth an hour.
                    ->description(fn (Lead $record): ?string => $record->describeRole())
                    ->wrap()
                    ->width('16rem'),

                TextColumn::make('email')
                    ->searchable()
                    ->copyable()
                    ->color('gray')
                    // Set by the register's subselect — see LeadResource.
                    ->badge(fn (Lead $record): bool => $record->enquiries_count > 1)
                    ->description(fn (Lead $record): ?string => $record->enquiries_count > 1
                        ? $record->enquiries_count.' enquiries from this address'
                        : null)
                    ->wrap()
                    ->width('16rem')
                    ->toggleable(),

                TextColumn::make('source')
                    ->label('Came from')
                    ->badge()
                    ->state(fn (Lead $record): string => $record->source->label())
                    ->color(fn (Lead $record): string => $record->source->color())
                    ->description(fn (Lead $record): ?string => $record->source_detail)
                    ->wrap()
                    ->width('12rem'),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->state(fn (Lead $record): string => $record->status->label())
                    ->color(fn (Lead $record): string => $record->status->color())
                    ->sortable(),

                /*
                 * Derived, never stored. Two subselects on the resource query
                 * answer it for the whole page — see the enum's comment for why
                 * there is no "converted" status to read instead.
                 */
                TextColumn::make('outcome')
                    ->label('Outcome')
                    ->badge()
                    ->state(fn (Lead $record): string => $record->describeOutcome())
                    ->color(fn (Lead $record): string => $record->outcomeColor())
                    ->tooltip('Worked out from their account and orders, not from a column.'),

                TextColumn::make('assignedTo.name')
                    ->label('Owner')
                    ->placeholder('Nobody')
                    ->color(fn (Lead $record): string => $record->assigned_to === null && $record->isOpen()
                        ? 'danger'
                        : 'gray')
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label('Came in')
                    ->dateTime('j M Y')
                    ->description(fn (Lead $record): ?string => $record->created_at?->diffForHumans())
                    ->sortable()
                    ->width('11rem'),

                TextColumn::make('contacted_at')
                    ->label('Last spoken to')
                    ->dateTime('j M Y')
                    ->placeholder('Never')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            // Newest first: a lead goes cold, so the top of this list is where
            // the work is.
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('source')->options(LeadSource::options()),

                SelectFilter::make('industry_id')
                    ->label('Industry')
                    ->options(fn (): array => Industry::query()->orderBy('name')->pluck('name', 'id')->all()),

                SelectFilter::make('assigned_to')
                    ->label('Owner')
                    ->options(fn (): array => User::query()
                        ->whereHas('roles')
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all()),

                Filter::make('marketing_opt_in')
                    ->label('Agreed to be emailed')
                    ->query(self::onlyOptedIn(...)),

                /*
                 * No status filter — the tabs are the status. A filter doing a
                 * tab's job narrows every other tab on top of its own query.
                 */
                TrashedFilter::make()->label('Deleted leads'),
            ], layout: FiltersLayout::AboveContentCollapsible)
            ->recordActions([
                self::assignToMeAction(),
                self::logContactAction(),
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    self::bulkAssignAction(),
                    self::bulkStatusAction(),
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No leads yet')
            ->emptyStateDescription('Leads arrive from the quiz, gated downloads and enquiry forms. Staff can also add one from a phone call.');
    }

    // Working a lead ----------------------------------------------------------------

    /**
     * Take ownership in one click.
     *
     * The whole point of the register is that an unowned lead is visible and
     * easy to claim. Making somebody open an edit form to put their own name in
     * a select is how a queue stops being worked.
     */
    private static function assignToMeAction(): Action
    {
        return Action::make('assignToMe')
            ->label('Take it')
            ->icon(Heroicon::OutlinedHandRaised)
            ->color('primary')
            ->visible(fn (Lead $record): bool => $record->assigned_to === null
                && $record->isOpen()
                && self::canWork())
            ->action(function (Lead $record): void {
                $record->forceFill([
                    'assigned_to' => auth()->id(),
                    // Claiming it is the start of working it. Leaving it "New"
                    // would keep it in the queue somebody just took it out of.
                    'status' => LeadStatus::Working,
                ])->save();

                Notification::make()->success()->title('Yours now')->send();
            });
    }

    /** Record that somebody actually rang them, without opening the form. */
    private static function logContactAction(): Action
    {
        return Action::make('logContact')
            ->label('Spoke to them')
            ->icon(Heroicon::OutlinedPhone)
            ->color('gray')
            ->visible(fn (Lead $record): bool => $record->isOpen() && self::canWork())
            ->requiresConfirmation()
            ->modalHeading('Mark as contacted?')
            ->modalDescription('Stamps today against them and moves them to being worked.')
            ->action(function (Lead $record): void {
                $record->forceFill([
                    'contacted_at' => now(),
                    'status' => LeadStatus::Working,
                    'assigned_to' => $record->assigned_to ?? auth()->id(),
                ])->save();

                Notification::make()->success()->title('Noted')->send();
            });
    }

    // Several at once -------------------------------------------------------------------

    private static function bulkAssignAction(): BulkAction
    {
        return BulkAction::make('assign')
            ->label('Hand to somebody')
            ->icon(Heroicon::OutlinedUserPlus)
            ->color('primary')
            ->visible(self::canWork(...))
            ->deselectRecordsAfterCompletion()
            ->schema([
                Select::make('assigned_to')
                    ->label('Owner')
                    ->options(fn (): array => User::query()
                        ->whereHas('roles')
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->required(),
            ])
            ->action(function (Collection $records, array $data): void {
                $owner = (int) $data['assigned_to'];

                /** @var Lead $record */
                foreach ($records as $record) {
                    $record->forceFill([
                        'assigned_to' => $owner,
                        'status' => $record->status === LeadStatus::New ? LeadStatus::Working : $record->status,
                    ])->save();
                }

                Notification::make()->success()->title('Handed over')->send();
            });
    }

    private static function bulkStatusAction(): BulkAction
    {
        return BulkAction::make('setStatus')
            ->label('Set status')
            ->icon(Heroicon::OutlinedFlag)
            ->color('gray')
            ->visible(self::canWork(...))
            ->deselectRecordsAfterCompletion()
            ->schema([
                Select::make('status')
                    ->options(LeadStatus::options())
                    ->required(),
            ])
            ->action(function (Collection $records, array $data): void {
                $status = LeadStatus::from((string) $data['status']);

                /** @var Lead $record */
                foreach ($records as $record) {
                    $record->forceFill(['status' => $status])->save();
                }

                Notification::make()->success()->title('Updated')->send();
            });
    }

    // Filters --------------------------------------------------------------------------------

    /**
     * Named rather than inline: a filter is handed an untyped builder, where a
     * plain where on a cast column is fine but static analysis cannot see the
     * model behind it.
     *
     * @param  Builder<Lead>  $query
     */
    private static function onlyOptedIn(Builder $query): void
    {
        $query->where('marketing_opt_in', true);
    }

    private static function canWork(): bool
    {
        return auth()->user()?->can('update_lead') ?? false;
    }
}
