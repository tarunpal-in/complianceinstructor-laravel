<?php

declare(strict_types=1);

namespace App\Filament\Resources\Roles\Schemas;

use App\Models\Role;
use App\Support\PermissionCatalogue;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

/**
 * A role, and what it may do.
 *
 * §8 asks for "~40 named permissions"; there are 140. Rendered flat that is a
 * form nobody reads and everybody guesses at, so they are grouped the way the
 * panel itself is grouped and the dangerous ones carry a sentence saying what
 * they actually allow — "sends money back, the one admin action that cannot be
 * undone" is worth more than the word `refund_payment`.
 *
 * A system role renders exactly the same and refuses to save. Hiding it would
 * remove the one thing somebody comes here to read.
 */
class RoleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([

            Section::make()
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label('Name')
                        ->required()
                        ->maxLength(120)
                        ->unique(Role::class, ignoreRecord: true)
                        // Stored as the identifier spatie checks against, so it
                        // is normalised rather than left as whatever was typed.
                        ->dehydrateStateUsing(fn (?string $state): string => Str::of((string) $state)
                            ->lower()->replace(' ', '_')->replaceMatches('/[^a-z0-9_]/', '')->value())
                        ->disabled(fn (?Role $record): bool => $record?->isSystem() ?? false)
                        ->helperText('Lower case, no spaces. "Marketing" becomes `marketing`.'),

                    TextInput::make('description')
                        ->label('What it is for')
                        ->maxLength(255)
                        ->disabled(fn (?Role $record): bool => $record?->isSystem() ?? false)
                        ->helperText('Shown beside the role when somebody is assigning it.'),
                ]),

            Section::make('Built in')
                ->description('This is one of the four roles the application ships with. Its permissions are set in code and kept up to date on every deploy, so they cannot be changed here — but everything it can do is listed below.')
                ->visible(fn (?Role $record): bool => $record?->isSystem() ?? false)
                ->schema([]),

            Tabs::make('permissions')
                ->columnSpanFull()
                ->tabs(self::permissionTabs()),
        ]);
    }

    /**
     * One tab per panel group, each a checklist of that group's permissions.
     *
     * @return array<int, Tabs\Tab>
     */
    private static function permissionTabs(): array
    {
        $tabs = [];

        foreach (PermissionCatalogue::grouped() as $group => $permissions) {
            $tabs[] = Tabs\Tab::make($group)
                ->badge(fn (callable $get): ?int => self::countHeld($get, array_keys($permissions)))
                ->schema([
                    CheckboxList::make('permissions')
                        ->hiddenLabel()
                        ->relationship('permissions', 'name')
                        ->options($permissions)
                        // Only this group's permissions, so a tab is a tab
                        // rather than the whole list rendered six times.
                        ->descriptions(self::warningsFor(array_keys($permissions)))
                        ->columns(2)
                        ->bulkToggleable()
                        ->disabled(fn (?Role $record): bool => $record?->isSystem() ?? false),
                ]);
        }

        return $tabs;
    }

    /**
     * The warnings that belong on the dangerous permissions.
     *
     * @param  array<int, string>  $permissions
     * @return array<string, string>
     */
    private static function warningsFor(array $permissions): array
    {
        $descriptions = [];

        foreach ($permissions as $permission) {
            $warning = PermissionCatalogue::warning($permission);

            if ($warning !== null) {
                $descriptions[$permission] = $warning;
            }
        }

        return $descriptions;
    }

    /**
     * How many of a group's permissions this role holds, for the tab badge.
     *
     * @param  array<int, string>  $permissions
     */
    private static function countHeld(callable $get, array $permissions): ?int
    {
        $held = $get('permissions');

        if (! is_array($held)) {
            return null;
        }

        // The relationship gives ids; the catalogue gives names. Counting the
        // overlap needs one of them translated, and doing it here keeps the
        // badge honest rather than showing the whole role's total on every tab.
        $names = \Spatie\Permission\Models\Permission::query()
            ->whereKey($held)
            ->pluck('name')
            ->all();

        $count = count(array_intersect($names, $permissions));

        return $count === 0 ? null : $count;
    }
}
