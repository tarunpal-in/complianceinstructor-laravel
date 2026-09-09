<?php

declare(strict_types=1);

namespace App\Filament\Resources\Payments\RelationManagers;

use App\Enums\RefundStatus;
use App\Filament\Resources\Payments\Pages\ViewPayment;
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

    /**
     * Filament makes relation managers read-only on a View page by default.
     * Cancelling a scheduled refund is the one thing that has to happen here,
     * and it is permissioned on `refund_payment` like the refund itself.
     */
    public function isReadOnly(): bool
    {
        return false;
    }

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
                    ->color(fn (RefundStatus $state): string => $state->color())
                    // How long is left is the only thing anyone wants to know
                    // about a scheduled refund, so it sits under the badge
                    // rather than needing the row opened.
                    ->description(fn (Refund $record): ?string => $record->windowRemaining() === null
                        ? null
                        : 'Sends in '.$record->windowRemaining()),

                TextColumn::make('amount_cents')
                    ->label('Amount')
                    ->money('USD', divideBy: 100)
                    ->alignEnd(),

                TextColumn::make('reason')
                    ->wrap()
                    ->placeholder('None given')
                    // The admin's reason and the gateway's refusal are different
                    // statements and neither may overwrite the other, so a
                    // failed refund carries both.
                    ->description(fn (Refund $record): ?string => $record->gateway_message)
                    ->color(fn (Refund $record): string => $record->status === RefundStatus::Failed ? 'danger' : 'gray'),

                TextColumn::make('issuedBy.name')
                    ->label('Issued by')
                    // §9 — "who gave money back" is the entry a finance dispute
                    // always comes down to. And who stopped it, for the same
                    // reason: not refunding is also a decision somebody made.
                    ->placeholder('Unknown')
                    ->description(fn (Refund $record): ?string => $record->cancelled_by === null
                        ? null
                        : 'Cancelled by '.($record->cancelledBy->name ?? 'someone since removed')),

                TextColumn::make('gateway_refund_id')
                    ->label('Gateway ID')
                    ->placeholder('—')
                    ->copyable()
                    ->limit(20)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([ViewPayment::cancelRefundAction()])
            ->paginated(false)
            ->emptyStateHeading('No refunds')
            ->emptyStateDescription('Nothing has been given back against this payment.');
    }
}
