<?php

declare(strict_types=1);

namespace App\Filament\Resources\Subscriptions\RelationManagers;

use App\Actions\Entitlements\GrantEntitlement;
use App\Actions\Entitlements\RevokeEntitlement;
use App\Enums\EntitlementSource;
use App\Enums\Format;
use App\Models\Entitlement;
use App\Models\Subscription;
use App\Models\Webinar;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * What this membership has been spent on — CLAUDE.md §5.2's ledger, filtered to
 * one subscriber.
 *
 * This is where the support case is answered. A member spends a live session,
 * cannot attend, is given the recording as a goodwill comp, and asks for the
 * live allowance back: "Give back" revokes the grant that spent it, and their
 * remaining count goes up on its own — because the quota is counted from these
 * rows rather than held in a number somebody has to remember to decrement.
 *
 * "Grant access" writes a `manual` row instead, which is how the replacement
 * recording is comped without coming out of the recorded allowance. Solving one
 * complaint must not quietly create the next one.
 */
class RedemptionsRelationManager extends RelationManager
{
    protected static string $relationship = 'redemptions';

    protected static ?string $title = 'Sessions used';

    protected static ?string $modelLabel = 'session';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    /** Rows are written by redeeming and amended by revoking, never edited. */
    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('webinar'))
            ->columns([
                TextColumn::make('webinar.title')
                    ->label('Webinar')
                    ->wrap()
                    ->width('20rem')
                    ->description(fn (Entitlement $record): ?string => $record->note),

                TextColumn::make('format')
                    ->label('Format')
                    ->badge()
                    ->formatStateUsing(fn (Entitlement $record): string => $record->format->label()),

                TextColumn::make('granted_at')
                    ->label('Used')
                    ->dateTime('j M Y')
                    ->sortable(),

                TextColumn::make('state')
                    ->label('Status')
                    ->badge()
                    ->state(fn (Entitlement $record): string => $record->describeState())
                    ->color(fn (Entitlement $record): string => $record->stateColor())
                    ->description(fn (Entitlement $record): ?string => $record->isRevoked()
                        ? 'by '.($record->revokedBy->name ?? 'staff')
                        : null),
            ])
            ->defaultSort('granted_at', 'desc')
            ->filters([
                // The default question is "what have they spent that still
                // counts", so the returned ones are out of the way until asked
                // for.
                Filter::make('still_counted')
                    ->label('Hide sessions already given back')
                    ->default()
                    ->query(fn (Builder $query): Builder => $query->whereNull('revoked_at')),
            ])
            ->headerActions([$this->grantAction()])
            ->recordActions([$this->giveBackAction()])
            ->emptyStateHeading('Nothing used yet')
            ->emptyStateDescription('Sessions appear here as this member spends their allowance.');
    }

    /**
     * Hand one session back.
     *
     * Support's permission, not Finance's — `revoke_entitlement` is one of §8's
     * privileged operations and Support holds it precisely so a person on a
     * ticket can fix this without being able to change what anybody is sold.
     */
    private function giveBackAction(): Action
    {
        return Action::make('giveBack')
            ->label('Give back')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('warning')
            ->visible(fn (Entitlement $record): bool => ! $record->isRevoked()
                && (auth()->user()?->can('revoke_entitlement') ?? false))
            ->modalHeading('Give this session back?')
            ->modalDescription(fn (Entitlement $record): string => sprintf(
                'Their access to "%s" stops, and the %s allowance it spent returns to them.',
                $record->webinar->title ?? 'this webinar',
                strtolower($record->format->label()),
            ))
            ->schema([
                Textarea::make('reason')
                    ->label('Why')
                    ->required()
                    ->rows(2)
                    ->maxLength(255)
                    ->placeholder('Could not attend; sent the recording instead')
                    ->helperText('Kept on the record. Access disappearing from a dashboard generates a second ticket otherwise.'),
            ])
            ->action(function (Entitlement $record, array $data): void {
                try {
                    app(RevokeEntitlement::class)($record, (string) $data['reason']);
                } catch (Throwable $exception) {
                    Notification::make()->danger()->title('Not given back')->body($exception->getMessage())->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title('Session given back')
                    ->body(strtolower($record->format->label()).' is now '
                        .$this->owner()->fresh(['entitlements'])?->describeRemaining($record->format))
                    ->send();
            });
    }

    /**
     * Comp a webinar without touching the quota.
     *
     * A `manual` grant, which §5.2 has precisely for this: the replacement
     * recording is a gift from staff, not something the member bought and not
     * something that should eat the allowance they are still owed.
     */
    private function grantAction(): Action
    {
        return Action::make('grant')
            ->label('Grant access')
            ->icon(Heroicon::OutlinedGift)
            ->color('gray')
            ->visible(fn (): bool => auth()->user()?->can('grant_entitlement') ?? false)
            ->modalHeading('Grant access without spending an allowance')
            ->modalDescription('Recorded as given by staff, so it costs this member nothing. Use it for a replacement or a goodwill gesture.')
            ->schema([
                Select::make('webinar_id')
                    ->label('Webinar')
                    ->options(fn (): array => Webinar::query()->orderBy('title')->limit(200)->pluck('title', 'id')->all())
                    ->searchable()
                    ->required(),

                Select::make('format')
                    ->label('Format')
                    ->options(Format::options())
                    ->required(),

                Textarea::make('reason')
                    ->label('Why')
                    ->required()
                    ->rows(2)
                    ->maxLength(255)
                    ->placeholder('Replacement for the live session they missed'),
            ])
            ->action(function (array $data): void {
                $member = $this->owner()->member;
                $webinar = Webinar::query()->find($data['webinar_id']);

                if ($member === null || $webinar === null) {
                    Notification::make()->danger()->title('Nothing granted')->body('That member or webinar no longer exists.')->send();

                    return;
                }

                app(GrantEntitlement::class)(
                    $member,
                    $webinar,
                    Format::from((string) $data['format']),
                    EntitlementSource::Manual,
                    null,
                    // No expiry: a comp is not part of the membership term, so
                    // it does not end when the term does.
                    null,
                    (string) $data['reason'],
                );

                Notification::make()
                    ->success()
                    ->title('Access granted')
                    ->body('Given by staff, so it costs them no allowance.')
                    ->send();
            });
    }

    private function owner(): Subscription
    {
        /** @var Subscription $owner */
        $owner = $this->getOwnerRecord();

        return $owner;
    }
}
