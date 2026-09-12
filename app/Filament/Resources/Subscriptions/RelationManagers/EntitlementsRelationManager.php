<?php

declare(strict_types=1);

namespace App\Filament\Resources\Subscriptions\RelationManagers;

use App\Models\SubscriptionEntitlement;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The allowance snapshot, row by row — CLAUDE.md §5.5.
 *
 * The overview summarises these; this is where support can see each one and
 * what it resets on, which is the part that decides whether "4 live sessions"
 * means four a month or four a year.
 *
 * Read-only. Editing a single row here would let somebody grant an allowance
 * that no plan sells and no order paid for, which is the kind of access bug
 * §5.2 exists to make impossible. Corrections go through "Re-apply plan terms"
 * on the record itself, which is confirmed, atomic and says what it is doing.
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

                TextColumn::make('window')
                    ->label('Resets')
                    ->formatStateUsing(fn (SubscriptionEntitlement $record): string => $record->window->label())
                    ->description(fn (SubscriptionEntitlement $record): ?string => $record->isUnlimited()
                        ? 'Nothing to reset.'
                        : null),
            ])
            ->emptyStateHeading('No allowances recorded')
            ->emptyStateDescription('This subscription was never given a snapshot of its plan. "Re-apply plan terms" will take one.');
    }
}
