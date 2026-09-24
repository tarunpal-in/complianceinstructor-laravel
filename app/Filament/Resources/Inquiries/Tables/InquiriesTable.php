<?php

declare(strict_types=1);

namespace App\Filament\Resources\Inquiries\Tables;

use App\Enums\InquirySource;
use App\Enums\InquiryStatus;
use App\Models\Inquiry;
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
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

/**
 * The inbox.
 *
 * Built around one question — who is still waiting for an answer — because that
 * is the only thing on this screen that costs anything to ignore.
 *
 * Who wrote and what they asked take most of the width; everything else is
 * toggleable and off by default where it is not needed to triage. An earlier
 * version of this table led with campaign and industry columns, which is what a
 * lead-capture register wants and not what somebody clearing an inbox does.
 */
class InquiriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('From')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    // The company, not the email: this is a B2B inbox, and the
                    // organisation is what tells somebody how to answer.
                    ->description(fn (Inquiry $record): ?string => $record->company)
                    ->wrap()
                    ->width('13rem'),

                /*
                 * What they asked, which is what a list of messages is actually
                 * triaged on. Falls back to the opening of the message where no
                 * subject was given, because a blank cell here would mean
                 * opening every row to find out what it is.
                 */
                TextColumn::make('subject')
                    ->label('About')
                    ->searchable(['subject', 'message'])
                    ->state(fn (Inquiry $record): ?string => $record->describeSubject(110))
                    ->placeholder('No subject')
                    ->wrap()
                    ->width('26rem'),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->state(fn (Inquiry $record): string => $record->status->label())
                    ->color(fn (Inquiry $record): string => $record->status->color())
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Came in')
                    ->dateTime('j M Y')
                    ->description(fn (Inquiry $record): ?string => $record->created_at?->diffForHumans())
                    ->sortable()
                    ->width('11rem'),

                TextColumn::make('assignedTo.name')
                    ->label('Owner')
                    ->placeholder('Nobody')
                    // Red when it is open and unclaimed, which is the state this
                    // screen exists to make impossible to miss.
                    ->color(fn (Inquiry $record): string => $record->assigned_to === null && $record->isOpen()
                        ? 'danger'
                        : 'gray'),

                TextColumn::make('email')
                    ->searchable()
                    ->copyable()
                    ->color('gray')
                    // Set by the register's subselect — see InquiryResource.
                    ->badge(fn (Inquiry $record): bool => $record->from_same_email_count > 1)
                    ->description(fn (Inquiry $record): ?string => $record->from_same_email_count > 1
                        ? 'Written '.$record->from_same_email_count.' times'
                        : null)
                    ->wrap()
                    ->width('16rem')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('source')
                    ->label('Arrived by')
                    ->badge()
                    ->state(fn (Inquiry $record): string => $record->source->label())
                    ->color(fn (Inquiry $record): string => $record->source->color())
                    // Off by default: once the contact form is the overwhelming
                    // majority, a column reading "Contact form" on every row is
                    // width spent saying nothing.
                    ->toggleable(isToggledHiddenByDefault: true),

                /*
                 * Derived, never stored. Two subselects on the resource query
                 * answer it for the whole page — see InquiryStatus for why
                 * there is no "converted" status to read instead.
                 */
                TextColumn::make('outcome')
                    ->label('Customer?')
                    ->badge()
                    ->state(fn (Inquiry $record): string => $record->describeOutcome())
                    ->color(fn (Inquiry $record): string => $record->outcomeColor())
                    ->tooltip('Worked out from their account and orders, not from a column.')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('contacted_at')
                    ->label('Last contacted')
                    ->dateTime('j M Y')
                    ->placeholder('Never')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            // Newest first: the top of this list is where the work is.
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('source')->label('Arrived by')->options(InquirySource::options()),

                SelectFilter::make('assigned_to')
                    ->label('Owner')
                    ->options(fn (): array => User::query()
                        ->whereHas('roles')
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all()),

                /*
                 * No status filter — the tabs are the status. A filter doing a
                 * tab's job narrows every other tab on top of its own query.
                 */
                TrashedFilter::make()->label('Deleted inquiries'),
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
            ->emptyStateHeading('No inquiries yet')
            ->emptyStateDescription('Inquiries arrive from the website contact form, and later from the quiz and gated downloads. Staff can also add one from a phone call.');
    }

    // Handling one ----------------------------------------------------------------------

    /**
     * Take ownership in one click.
     *
     * The whole point of the register is that an unowned inquiry is visible and
     * easy to claim. Making somebody open an edit form to put their own name in
     * a select is how a queue stops being worked.
     */
    private static function assignToMeAction(): Action
    {
        return Action::make('assignToMe')
            ->label('Take it')
            ->icon(Heroicon::OutlinedHandRaised)
            ->color('primary')
            ->visible(fn (Inquiry $record): bool => $record->assigned_to === null
                && $record->isOpen()
                && self::canWork())
            ->action(function (Inquiry $record): void {
                $record->forceFill([
                    'assigned_to' => auth()->id(),
                    // Claiming it is the start of handling it. Leaving it "New"
                    // would keep it in the queue somebody just took it out of.
                    'status' => InquiryStatus::Working,
                ])->save();

                Notification::make()->success()->title('Yours now')->send();
            });
    }

    /** Record that somebody has replied, without opening the record. */
    private static function logContactAction(): Action
    {
        return Action::make('logContact')
            ->label('Mark answered')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->color('success')
            ->visible(fn (Inquiry $record): bool => $record->isOpen() && self::canWork())
            ->requiresConfirmation()
            ->modalHeading('Mark as answered?')
            ->modalDescription('Stamps today against them and takes them out of the queue. Use Log a reply on the record to keep a note of what was said.')
            ->action(function (Inquiry $record): void {
                $record->forceFill([
                    'contacted_at' => now(),
                    'status' => InquiryStatus::Answered,
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

                /** @var Inquiry $record */
                foreach ($records as $record) {
                    $record->forceFill([
                        'assigned_to' => $owner,
                        'status' => $record->status === InquiryStatus::New ? InquiryStatus::Working : $record->status,
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
                    ->options(InquiryStatus::options())
                    ->required(),
            ])
            ->action(function (Collection $records, array $data): void {
                $status = InquiryStatus::from((string) $data['status']);

                /** @var Inquiry $record */
                foreach ($records as $record) {
                    $record->forceFill(['status' => $status])->save();
                }

                Notification::make()->success()->title('Updated')->send();
            });
    }

    private static function canWork(): bool
    {
        return auth()->user()?->can('update_inquiry') ?? false;
    }
}
