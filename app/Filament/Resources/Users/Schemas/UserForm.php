<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\UserRole;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Hash;

/**
 * `make:filament-resource --generate` scaffolds a field per column, which for
 * users meant editable textareas for two_factor_secret and
 * two_factor_recovery_codes, plus Cashier's billing columns.
 *
 * Those are deliberately absent here. 2FA material is credential data that must
 * never be rendered or hand-edited, and the Cashier columns are owned by Stripe
 * — writing to them by hand desynchronises the local record from the gateway.
 */
class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Account')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),

                        TextInput::make('email')
                            ->label('Email address')
                            ->email()
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),

                        TextInput::make('password')
                            ->password()
                            ->revealable()
                            ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? Hash::make($state) : null)
                            // Leaving the field blank on edit must not blank the
                            // password — only persist it when something was typed.
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->helperText('Leave blank to keep the current password.')
                            ->maxLength(255),

                        DateTimePicker::make('email_verified_at')
                            ->label('Email verified at'),
                    ]),

                Section::make('Access')
                    ->description('Roles come from spatie/laravel-permission — users carry no role column (§5.8). A user with no role is a customer and cannot reach the admin panel.')
                    ->schema([
                        Select::make('roles')
                            ->relationship('roles', 'name')
                            ->multiple()
                            ->preload()
                            ->searchable()
                            ->options(fn (): array => collect(UserRole::cases())
                                ->mapWithKeys(fn (UserRole $role): array => [$role->value => $role->label()])
                                ->all())
                            ->helperText('Granting any admin role gives this user access to /admin.')
                            ->visible(fn (): bool => auth()->user()?->can('update_role') ?? false),
                    ]),
            ]);
    }
}
