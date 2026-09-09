<?php

declare(strict_types=1);

namespace App\Filament\Resources\Payments\RelationManagers;

use App\Enums\RefundStatus;
use App\Models\Refund;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * What has been given back.
 *
 * Read-only. Refunds are recorded through the Refund action, which caps the
 * amount at what remains and re-derives the order — editing a row here would
 * bypass both.
 */
class RefundsRelationManager extends RelationManager
{
    protected static string $relationship = 'refunds';

    protected static ?string $title = 'Refunds';

    protected static ?string $modelLabel = 'refund';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('gateway_refund_id')
            ->columns([
                TextColumn::make('processed_at')
                    ->label('Processed')
                    ->dateTime('j M Y, H:i')
                    ->placeholder('Not yet'),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (RefundStatus $state): string => $state->label())
                    ->color(fn (RefundStatus $state): string => $state->color()),

                TextColumn::make('amount_cents')
                    ->label('Amount')
                    ->money('USD', divideBy: 100)
                    ->alignEnd(),

                TextColumn::make('reason')
                    ->wrap()
                    ->placeholder('None given'),

                TextColumn::make('issuedBy.name')
                    ->label('Issued by')
                    // §9 — "who gave money back" is the entry a finance dispute
                    // always comes down to.
                    ->placeholder('Unknown'),

                TextColumn::make('gateway_refund_id')
                    ->label('Gateway ID')
                    ->placeholder('—')
                    ->copyable()
                    ->limit(20)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('processed_at', 'desc')
            ->paginated(false)
            ->emptyStateHeading('No refunds')
            ->emptyStateDescription('Nothing has been given back against this payment.');
    }
}
