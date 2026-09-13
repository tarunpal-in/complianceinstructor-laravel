<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\RelationManagers;

use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Models\Subscription;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which memberships this person has held.
 *
 * The other half of "what have they paid us for". A customer can have several
 * over time — one that ran out, one they are on now — and the record of a
 * lapsed one is kept because it holds the terms they were sold under.
 *
 * Read-only here. §3 gives Cashier the lifecycle, and the actions that change
 * one live on the membership itself where the consequences are spelled out.
 */
class MembershipsRelationManager extends RelationManager
{
    protected static string $relationship = 'subscriptions';

    protected static ?string $title = 'Memberships';

    protected static ?string $modelLabel = 'membership';

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
            ->recordTitleAttribute('stripe_id')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['plan', 'entitlements']))
            ->columns([
                TextColumn::make('plan.name')
                    ->label('Plan')
                    ->weight('bold')
                    ->placeholder('No plan recorded')
                    ->description(fn (Subscription $record): string => $record->describePrice())
                    ->url(fn (Subscription $record): string => SubscriptionResource::getUrl('view', ['record' => $record])),

                TextColumn::make('state')
                    ->label('Status')
                    ->badge()
                    ->state(fn (Subscription $record): string => $record->state()->label())
                    ->color(fn (Subscription $record): string => $record->state()->color()),

                TextColumn::make('term')
                    ->label('Runs')
                    ->state(fn (Subscription $record): string => $record->describeTermDates())
                    ->wrap()
                    ->width('11rem'),

                TextColumn::make('allowances')
                    ->label('Grants')
                    ->state(fn (Subscription $record): array => $record->allowanceLines())
                    ->listWithLineBreaks()
                    ->limitList(2)
                    ->expandableLimitedList()
                    ->placeholder('No allowances recorded')
                    ->size('sm')
                    ->width('15rem'),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No memberships')
            ->emptyStateDescription('Memberships appear here when this customer subscribes.');
    }
}
