<?php

declare(strict_types=1);

namespace App\Filament\Resources\Subscriptions\RelationManagers;

use App\Actions\Memberships\AdjustSubscriptionAllowance;
use App\Models\Subscription;
use App\Models\SubscriptionEntitlement;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Throwable;

/**
 * The allowance snapshot, row by row — CLAUDE.md §5.5, with what is left of
 * each read off the §5.2 ledger.
 *
 * Rows cannot be created or deleted freely — an allowance that no plan sells
 * and no order paid for is the access bug §5.2 exists to prevent — but each one
 * can be **adjusted on its own**, which is what support asks for. "Give this
 * member one more live session" should not mean replacing every allowance they
 * hold from a plan that may have moved on since they joined.
 */
class EntitlementsRelationManager extends RelationManager
{
    protected static string $relationship = 'entitlements';

    protected static ?string $title = 'Allowances';

    protected static ?string $modelLabel = 'allowance';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    /** No free-form create or delete; the Adjust action is the way in. */
    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('format')
            ->columns([
                TextColumn::make('format')
                    ->label('Format')
                    ->badge()
                    ->formatStateUsing(fn (SubscriptionEntitlement $record): string => $record->format->label()),

                TextColumn::make('quantity')
                    ->label('Allowance')
                    // NULL means unlimited (§5.5), and a blank cell would read
                    // as "none" — the opposite of what it means.
                    ->state(fn (SubscriptionEntitlement $record): string => $record->isUnlimited()
                        ? 'Unlimited'
                        : (string) $record->quantity)
                    ->badge()
                    ->color(fn (SubscriptionEntitlement $record): string => $record->isUnlimited() ? 'success' : 'gray'),

                /*
                 * Counted from the ledger, not stored (agreed 2026-09-13). A
                 * used count that came from a column would drift, and could not
                 * be traced back to the sessions it stood for.
                 */
                TextColumn::make('used')
                    ->label('Used this window')
                    ->state(fn (SubscriptionEntitlement $record): int => $this->owner()->usedInWindow($record->format))
                    ->alignEnd(),

                TextColumn::make('remaining')
                    ->label('Remaining')
                    ->state(fn (SubscriptionEntitlement $record): string => $this->owner()->describeRemaining($record->format))
                    ->badge()
                    ->color(fn (SubscriptionEntitlement $record): string => match (true) {
                        $record->isUnlimited() => 'success',
                        ($this->owner()->remainingFor($record->format) ?? 0) < 1 => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('window')
                    ->label('Resets')
                    ->formatStateUsing(fn (SubscriptionEntitlement $record): string => $record->window->label())
                    ->description(fn (SubscriptionEntitlement $record): string => $record->isUnlimited()
                        ? 'Nothing to reset.'
                        : $this->describeWindow($record)),
            ])
            ->recordActions([$this->adjustAction()])
            ->emptyStateHeading('No allowances recorded')
            ->emptyStateDescription('This subscription was never given a snapshot of its plan. "Re-apply plan terms" will take one.');
    }

    /**
     * Change this one format, up or down, leaving the others alone.
     *
     * Deliberately not a route to crediting back a session somebody could not
     * attend — that is "Give back" on the Redemptions list, and the difference
     * is spelled out here because picking the wrong one is the easy mistake:
     * this changes the cap for good, that returns one session.
     */
    private function adjustAction(): Action
    {
        return Action::make('adjust')
            ->label('Adjust')
            ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
            ->color('gray')
            ->visible(fn (): bool => auth()->user()?->can('update_subscription') ?? false)
            ->modalHeading(fn (SubscriptionEntitlement $record): string => 'Adjust '.strtolower($record->format->label()))
            ->modalDescription('This changes what the membership grants from now on, including future windows. To hand back a single session they could not attend, use "Give back" on the Redemptions list instead.')
            ->schema([
                Toggle::make('unlimited')
                    ->label('Unlimited')
                    ->live()
                    ->default(fn (SubscriptionEntitlement $record): bool => $record->isUnlimited()),

                TextInput::make('quantity')
                    ->label('How many')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(999)
                    ->default(fn (SubscriptionEntitlement $record): ?int => $record->quantity)
                    ->visible(fn (callable $get): bool => $get('unlimited') !== true)
                    ->required(fn (callable $get): bool => $get('unlimited') !== true)
                    ->helperText(fn (SubscriptionEntitlement $record): string => 'They have used '
                        .$this->owner()->usedInWindow($record->format).' this window.'),

                Textarea::make('reason')
                    ->label('Why')
                    ->required()
                    ->rows(2)
                    ->maxLength(255)
                    ->placeholder('Goodwill after a cancelled session')
                    ->helperText('Recorded against this membership. Somebody will ask why it differs from the plan.'),
            ])
            ->action(function (SubscriptionEntitlement $record, array $data): void {
                try {
                    app(AdjustSubscriptionAllowance::class)(
                        $this->owner(),
                        $record->format,
                        ($data['unlimited'] ?? false) === true ? null : (int) $data['quantity'],
                        (string) $data['reason'],
                    );
                } catch (Throwable $exception) {
                    Notification::make()->danger()->title('Not adjusted')->body($exception->getMessage())->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title('Allowance adjusted')
                    ->body(strtolower($record->format->label()).' is now '.$this->owner()->fresh(['entitlements'])?->describeRemaining($record->format))
                    ->send();
            });
    }

    /** "Resets 15 Oct 2026" — when this allowance next comes back. */
    private function describeWindow(SubscriptionEntitlement $record): string
    {
        [, $end] = $this->owner()->windowFor($record->window);

        return 'Resets '.$end->format('j M Y');
    }

    private function owner(): Subscription
    {
        /** @var Subscription $owner */
        $owner = $this->getOwnerRecord();

        return $owner;
    }
}
