<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\RelationManagers;

use App\Enums\Format;
use App\Models\OrderItem;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * What was bought.
 *
 * Read-only: editing a line after the fact would rewrite what a customer was
 * charged while the payment record still says otherwise. Corrections go through
 * a refund, which leaves both numbers intact and auditable.
 */
class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Lines';

    protected static ?string $modelLabel = 'line';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('description')
            ->columns([
                TextColumn::make('description')
                    ->label('Item')
                    // Keyed off the column: a package line belongs to no single
                    // webinar, and that is the case worth naming rather than
                    // leaving as a blank cell.
                    ->description(fn (OrderItem $record): string => $record->webinar_id === null
                        ? 'Package or bundle'
                        : (string) $record->webinar->title)
                    ->wrap(),

                TextColumn::make('formats')
                    ->label('Grants')
                    ->badge()
                    // Snapshotted on the line, not read from the offering: the
                    // offering can be edited afterwards, and a refund has to
                    // revoke exactly what was granted.
                    ->state(fn (OrderItem $record): array => $record->formatEnums()
                        ->map(fn (Format $format): string => $format->label())
                        ->all()),

                TextColumn::make('quantity')->label('Qty')->alignEnd(),

                TextColumn::make('unit_price_cents')
                    ->label('Unit')
                    ->money('USD', divideBy: 100)
                    ->alignEnd(),

                TextColumn::make('line_total_cents')
                    ->label('Line total')
                    ->money('USD', divideBy: 100)
                    ->alignEnd()
                    // §14 #7 — the predecessor kept a unit price in a column
                    // called `total_price` and every revenue sum was wrong by a
                    // factor of quantity. If the two ever disagree, say so here
                    // rather than letting the number look authoritative.
                    ->color(fn (OrderItem $record): string => $record->totalIsConsistent() ? 'gray' : 'danger')
                    ->tooltip(fn (OrderItem $record): ?string => $record->totalIsConsistent()
                        ? null
                        : 'This does not equal unit price × quantity.'),

                TextColumn::make('seats')
                    ->label('Seats')
                    ->state(fn (OrderItem $record): int => $record->seatCount())
                    ->alignEnd()
                    ->toggleable(),

                TextColumn::make('sku')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->paginated(false)
            ->emptyStateHeading('No lines')
            ->emptyStateDescription('An order with no lines cannot have been placed through checkout.');
    }
}
