<?php

declare(strict_types=1);

namespace App\Filament\Resources\Subscribers\Tables;

use App\Actions\Subscribers\ConfirmSubscription;
use App\Actions\Subscribers\Subscribe;
use App\Actions\Subscribers\Unsubscribe;
use App\Enums\SubscriberSource;
use App\Enums\SubscriberStatus;
use App\Models\Subscriber;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

/**
 * The mailing list.
 *
 * Deliberately plain. There is very little to *do* to a subscriber — they are
 * on the list or they are not — so the screen is mostly a record of who and
 * when, with the two decisions that exist reachable in one click.
 *
 * The delete action is the interesting one; see below.
 */
class SubscribersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('email')
                    ->searchable()
                    ->copyable()
                    ->weight('bold')
                    ->description(fn (Subscriber $record): ?string => $record->name)
                    ->wrap()
                    ->width('20rem'),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->state(fn (Subscriber $record): string => $record->status->label())
                    ->color(fn (Subscriber $record): string => $record->status->color())
                    // The reason, where there is one — an unsubscribe note or a
                    // bounce message from the mail provider.
                    ->description(fn (Subscriber $record): ?string => $record->unsubscribe_reason ?? $record->bounce_reason)
                    ->wrap()
                    ->sortable(),

                TextColumn::make('source')
                    ->label('Signed up')
                    ->badge()
                    ->state(fn (Subscriber $record): string => $record->source->label())
                    ->color(fn (Subscriber $record): string => $record->source->color())
                    // An imported row agreed to nothing here, and that should be
                    // visible without opening it.
                    ->tooltip(fn (Subscriber $record): ?string => $record->source->isSelfSubmitted()
                        ? null
                        : 'Not a consent this site collected.'),

                TextColumn::make('subscribed_at')
                    ->label('Since')
                    ->dateTime('j M Y')
                    ->placeholder('Not recorded')
                    ->description(fn (Subscriber $record): string => $record->isConfirmed()
                        ? 'Confirmed'
                        : 'Not confirmed')
                    ->sortable()
                    ->width('11rem'),

                TextColumn::make('customer')
                    ->label('Customer?')
                    ->badge()
                    ->state(fn (Subscriber $record): string => $record->customer === null ? 'No account' : 'Has an account')
                    ->color(fn (Subscriber $record): string => $record->customer === null ? 'gray' : 'info')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('unsubscribed_at')
                    ->label('Left')
                    ->dateTime('j M Y')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('source')->label('Signed up')->options(SubscriberSource::options()),

                /*
                 * No status filter — the tabs are the status, and a filter doing
                 * a tab's job narrows every other tab on top of its own query.
                 * No trashed filter either: this model has no soft delete.
                 */
            ], layout: FiltersLayout::AboveContentCollapsible)
            ->recordActions([
                self::confirmAction(),
                self::unsubscribeAction(),
                self::resubscribeAction(),
                ViewAction::make(),
                self::deleteAction(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    self::bulkUnsubscribeAction(),
                ]),
            ])
            ->emptyStateHeading('Nobody on the list yet')
            ->emptyStateDescription('Subscribers arrive from the signup box in the site footer. Staff can also add one by hand.');
    }

    // The two decisions ------------------------------------------------------------

    private static function unsubscribeAction(): Action
    {
        return Action::make('unsubscribe')
            ->label('Unsubscribe')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('warning')
            ->visible(fn (Subscriber $record): bool => ! $record->isSuppressed() && self::canManage())
            ->modalHeading('Take this address off the list?')
            ->modalDescription('They stop receiving campaigns. The row stays, because it is what keeps them off the list if a future import includes them.')
            ->schema([
                Textarea::make('reason')
                    ->label('Why')
                    ->rows(2)
                    ->maxLength(255)
                    ->placeholder('Asked by email; bounced repeatedly')
                    ->helperText('Optional, and kept on the record.'),
            ])
            ->action(function (Subscriber $record, array $data): void {
                app(Unsubscribe::class)($record, self::reason($data));

                Notification::make()->success()->title('Taken off the list')->send();
            });
    }

    /**
     * Putting somebody back, as staff.
     *
     * The only route back for an address that reported us as spam — a form
     * submission cannot do it, on purpose. The wording says so, because whoever
     * clicks this should know what they are overruling.
     */
    private static function resubscribeAction(): Action
    {
        return Action::make('resubscribe')
            ->label('Put back on')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('success')
            ->visible(fn (Subscriber $record): bool => $record->isSuppressed() && self::canManage())
            ->requiresConfirmation()
            ->modalHeading('Put this address back on the list?')
            ->modalDescription(fn (Subscriber $record): string => $record->status === SubscriberStatus::Complained
                ? 'This address reported us as spam. Only do this if they have asked you to, in writing — mailing a complainant again is what gets a sending domain blocked.'
                : 'They start receiving campaigns again. Only do this if they have asked you to.')
            ->action(function (Subscriber $record): void {
                app(Subscribe::class)(
                    email: $record->email,
                    source: SubscriberSource::Manual,
                    name: $record->name,
                    asStaff: true,
                );

                Notification::make()->success()->title('Back on the list')->send();
            });
    }

    /** For a pending row whose confirmation email never arrived. */
    private static function confirmAction(): Action
    {
        return Action::make('confirm')
            ->label('Confirm')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->visible(fn (Subscriber $record): bool => $record->isAwaitingConfirmation() && self::canManage())
            ->requiresConfirmation()
            ->modalHeading('Confirm this signup?')
            ->modalDescription('Use this when somebody has told you they never got the confirmation email. It records that you confirmed it, not that they did.')
            ->action(function (Subscriber $record): void {
                app(ConfirmSubscription::class)($record);

                Notification::make()->success()->title('Confirmed')->send();
            });
    }

    /**
     * Deleting, with the cost said out loud.
     *
     * This exists for a genuine erasure request and nothing else. Unsubscribing
     * is what "remove me from your list" means, and it is the action above —
     * deleting the row deletes the record of the refusal, so the next import
     * containing that address puts them straight back on and nothing in the
     * system knows any better.
     *
     * Rather than hiding the action or silently allowing it, the modal says
     * what it costs at the moment somebody is deciding.
     */
    private static function deleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->label('Erase')
            ->modalHeading('Erase this record entirely?')
            ->modalDescription('This is for a right-to-erasure request, not for taking somebody off the list — Unsubscribe does that and keeps the proof. Erasing loses the record that they refused, so a future import could put this address back on the list.')
            ->modalSubmitActionLabel('Erase anyway');
    }

    private static function bulkUnsubscribeAction(): BulkAction
    {
        return BulkAction::make('unsubscribeAll')
            ->label('Unsubscribe these')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('warning')
            ->visible(self::canManage(...))
            ->requiresConfirmation()
            ->deselectRecordsAfterCompletion()
            ->schema([
                Textarea::make('reason')
                    ->label('Why')
                    ->rows(2)
                    ->maxLength(255)
                    ->helperText('Applied to every address selected.'),
            ])
            ->action(function (Collection $records, array $data): void {
                $unsubscribe = app(Unsubscribe::class);
                $reason = self::reason($data);
                $changed = 0;

                /** @var Subscriber $record */
                foreach ($records as $record) {
                    if ($record->status === SubscriberStatus::Unsubscribed) {
                        continue;
                    }

                    $unsubscribe($record, $reason);
                    $changed++;
                }

                Notification::make()
                    ->success()
                    ->title('Taken off the list')
                    ->body(trans_choice(':count address|:count addresses', $changed, ['count' => $changed]).' updated.')
                    ->send();
            });
    }

    /** @param  array<string, mixed>  $data */
    private static function reason(array $data): ?string
    {
        $reason = $data['reason'] ?? null;

        return is_string($reason) && trim($reason) !== '' ? trim($reason) : null;
    }

    private static function canManage(): bool
    {
        return auth()->user()?->can('update_subscriber') ?? false;
    }
}
