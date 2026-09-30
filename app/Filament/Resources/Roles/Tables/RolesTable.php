<?php

declare(strict_types=1);

namespace App\Filament\Resources\Roles\Tables;

use App\Models\Role;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RolesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Role')
                    ->weight('bold')
                    ->state(fn (Role $record): string => $record->displayName())
                    ->description(fn (Role $record): ?string => $record->description)
                    ->searchable()
                    ->wrap()
                    ->width('22rem'),

                TextColumn::make('is_system')
                    ->label('Owned by')
                    ->badge()
                    ->state(fn (Role $record): string => $record->isSystem() ? 'Built in' : 'Yours')
                    ->color(fn (Role $record): string => $record->isSystem() ? 'gray' : 'success')
                    ->tooltip(fn (Role $record): ?string => $record->isSystem()
                        ? 'Set in code and kept up to date on every deploy. It cannot be changed here.'
                        : null),

                TextColumn::make('permissions_count')
                    ->label('Permissions')
                    ->alignEnd(),

                TextColumn::make('users_count')
                    ->label('People')
                    ->alignEnd()
                    // A role nobody holds is either new or forgotten, and
                    // neither is obvious from anywhere else.
                    ->color(fn (Role $record): string => (int) $record->users_count === 0 ? 'gray' : 'primary'),
            ])
            ->recordActions([
                EditAction::make()->label(fn (Role $record): string => $record->isSystem() ? 'View' : 'Edit'),

                DeleteAction::make()
                    ->visible(fn (Role $record): bool => $record->isDeletable())
                    ->modalDescription('Nobody holds this role, so nothing loses access.'),
            ])
            ->emptyStateHeading('No roles yet');
    }
}
