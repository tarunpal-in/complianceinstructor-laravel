<?php

declare(strict_types=1);

namespace App\Filament\Resources\Payments\Tables;

use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Payment;
use App\Support\Money;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * The payment log — CLAUDE.md §6.
 *
 * Distinct from the Orders screen on purpose. Orders answer "what did this
 * customer buy"; this answers "what money moved, through which gateway, and
 * does it add up" — which is a reconciliation question and gets a reconciliation
 * shape: totals over the filtered set, and a filter for every row that does not
 * agree with itself.
 */
class PaymentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('processed_at')
                    ->label('Processed')
                    ->dateTime('j M Y, H:i')
                    ->placeholder('Not yet')
                    ->sortable(),

                TextColumn::make('order.number')
                    ->label('Order')
                    ->searchable()
                    ->weight('bold')
                    // The payment is rarely the end of the question; whoever is
                    // looking at one usually wants the order behind it.
                    ->url(fn (Payment $record): string => OrderResource::getUrl('view', ['record' => $record->order_id]))
                    ->description(fn (Payment $record): string => $record->order->customerName()),

                TextColumn::make('gateway')
                    ->badge()
                    ->formatStateUsing(fn (PaymentGateway $state): string => $state->label())
                    ->color(fn (PaymentGateway $state): string => $state->color())
                    ->sortable(),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (PaymentStatus $state): string => $state->label())
                    ->color(fn (PaymentStatus $state): string => $state->color())
                    ->sortable(),

                TextColumn::make('amount_cents')
                    ->label('Amount')
                    ->money('USD', divideBy: 100)
                    ->alignEnd()
                    ->sortable()
                    // Only succeeded payments are summed. A total that quietly
                    // includes failed attempts is worse than no total, because
                    // it looks authoritative.
                    ->summarize(
                        Sum::make()
                            ->label('Captured')
                            ->money('USD', divideBy: 100)
                            ->query(fn (QueryBuilder $query): QueryBuilder => $query
                                ->where('status', PaymentStatus::Succeeded->value))
                    ),

                TextColumn::make('refunded')
                    ->label('Refunded')
                    ->state(fn (Payment $record): string => Money::format($record->refundedCents()))
                    ->alignEnd()
                    ->color(fn (Payment $record): string => $record->refundedCents() > 0 ? 'warning' : 'gray'),

                TextColumn::make('net')
                    ->label('Net')
                    ->state(fn (Payment $record): string => Money::format(
                        $record->status === PaymentStatus::Succeeded
                            ? $record->amount_cents - $record->refundedCents()
                            : 0,
                    ))
                    ->alignEnd()
                    ->weight('bold'),

                TextColumn::make('gateway_payment_id')
                    ->label('Gateway ID')
                    ->placeholder('—')
                    ->copyable()
                    ->limit(20)
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('reference')
                    ->label('Reference')
                    ->placeholder('—')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('recordedBy.name')
                    ->label('Recorded by')
                    ->placeholder('Gateway')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('processed_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(PaymentStatus::options())
                    ->multiple(),

                SelectFilter::make('gateway')
                    ->options(PaymentGateway::options())
                    ->multiple(),

                TernaryFilter::make('refunded')
                    ->label('Refunds')
                    ->placeholder('Any')
                    ->trueLabel('Has been refunded')
                    ->falseLabel('Never refunded')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->has('refunds'),
                        false: fn (Builder $query): Builder => $query->doesntHave('refunds'),
                        blank: fn (Builder $query): Builder => $query,
                    ),

                Filter::make('processed_between')
                    ->schema([
                        DatePicker::make('from')->label('Processed from'),
                        DatePicker::make('until')->label('Processed until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, string $from): Builder => $q
                            ->whereDate('processed_at', '>=', $from))
                        ->when($data['until'] ?? null, fn (Builder $q, string $until): Builder => $q
                            ->whereDate('processed_at', '<=', $until)))
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];

                        if ($data['from'] ?? null) {
                            $indicators[] = 'Processed from '.$data['from'];
                        }

                        if ($data['until'] ?? null) {
                            $indicators[] = 'Processed until '.$data['until'];
                        }

                        return $indicators;
                    }),

                /*
                 * The rows that need a human.
                 *
                 * A succeeded payment with no processed date, or a manual
                 * payment nobody is recorded against, means the trail is
                 * incomplete — and for a manual payment the trail is the only
                 * evidence there is, since no gateway confirmed it.
                 */
                Filter::make('incomplete_record')
                    ->label('Missing a processed date or an actor')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query
                        ->where('status', PaymentStatus::Succeeded->value)
                        ->where(fn (Builder $inner): Builder => $inner
                            ->whereNull('processed_at')
                            ->orWhere(fn (Builder $manual): Builder => $manual
                                ->where('gateway', PaymentGateway::Manual->value)
                                ->whereNull('recorded_by')))),
            ], layout: FiltersLayout::AboveContentCollapsible)
            ->recordActions([ViewAction::make()])
            ->emptyStateHeading('No payments yet')
            ->emptyStateDescription('Every capture, decline and invoiced settlement lands here.');
    }
}
