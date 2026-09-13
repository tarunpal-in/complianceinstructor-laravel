<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Tables;

use App\Enums\UserRole;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('email')
                    ->label('Email address')
                    ->searchable()
                    ->sortable()
                    ->copyable(),

                TextColumn::make('roles.name')
                    ->label('Roles')
                    ->badge()
                    ->placeholder('Customer')
                    ->formatStateUsing(fn (string $state): string => UserRole::tryFrom($state)?->label() ?? $state),

                /*
                 * What this person is worth to the business, in the three terms
                 * it is measured in. Counted rather than summed to money: a
                 * single "spent" figure would have to net off refunds to be
                 * true, and a subtly wrong money total is worse than none. The
                 * exact figures are on the Orders list inside the record, where
                 * each one carries its own status.
                 */
                TextColumn::make('orders_count')
                    ->label('Orders')
                    // No ->counts(): the resource query already adds the alias.
                    ->alignEnd()
                    ->sortable()
                    ->color(fn (int $state): string => $state > 0 ? 'success' : 'gray')
                    ->toggleable(),

                TextColumn::make('subscriptions_count')
                    ->label('Memberships')
                    ->alignEnd()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('entitlements_count')
                    ->label('Access')
                    ->alignEnd()
                    ->sortable()
                    ->tooltip('Webinars this person has been granted, in any format.')
                    ->toggleable(isToggledHiddenByDefault: true),

                IconColumn::make('email_verified_at')
                    ->label('Verified')
                    ->boolean()
                    ->sortable(),

                // Whether 2FA is on — never the secret or the recovery codes.
                IconColumn::make('two_factor_confirmed_at')
                    ->label('2FA')
                    ->boolean()
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Registered')
                    ->dateTime('j M Y')
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                /*
                 * Which role, for narrowing the Staff tab. Deliberately no
                 * "Customers only" or "Unverified" filters any more — the tabs
                 * answer both, and a filter doing the same job as a tab narrows
                 * every other tab on top of its own query.
                 */
                SelectFilter::make('roles')
                    ->relationship('roles', 'name')
                    ->multiple()
                    ->preload()
                    ->label('Role'),

                // Customers who bought once and never came back are a different
                // question from customers who never bought at all.
                Filter::make('has_bought')
                    ->label('Has bought something')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->whereHas('orders')),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
