<?php

declare(strict_types=1);

namespace App\Filament\Resources\Staff\Schemas;

use App\Models\Role;
use App\Models\User;
use App\Support\StaffLockout;
use Closure;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Inviting somebody, and changing what they can do.
 *
 * **No password field, and there must never be one.** §9's first critical
 * finding in the predecessor was an unauthenticated password-change endpoint
 * that let anybody take an admin account. Fortify owns password setting with
 * signed single-use expiring tokens; an administrator who could set another
 * administrator's password could become them, and nothing in the log would say
 * so. Invited staff get a reset link and choose their own.
 */
class StaffForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([

            Section::make('Who they are')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(255),

                    TextInput::make('email')
                        ->email()
                        ->required()
                        ->maxLength(255)
                        ->unique(User::class, ignoreRecord: true)
                        ->helperText('Where their invitation goes. Also how they sign in.'),
                ]),

            Section::make('What they can do')
                ->description('Somebody with no role cannot reach this panel at all.')
                ->schema([
                    CheckboxList::make('roles')
                        ->hiddenLabel()
                        ->relationship('roles', 'name')
                        ->getOptionLabelFromRecordUsing(fn (Role $record): string => $record->displayName())
                        // The description matters more than the name here: the
                        // difference between Finance and Support is not obvious
                        // from two words, and picking wrong hands somebody the
                        // ability to move money.
                        ->descriptions(fn (): array => Role::query()
                            ->get()
                            ->mapWithKeys(fn (Role $role): array => [
                                $role->getKey() => $role->description ?? 'No description.',
                            ])
                            ->all())
                        ->columns(2)
                        ->required()
                        ->bulkToggleable()
                        /*
                         * The lockout guard, as a validation rule.
                         *
                         * It belongs here rather than in the save: Filament
                         * saves a relationship field separately from the rest
                         * of the record, so a check in handleRecordUpdate runs
                         * against state the form has already moved on from.
                         * A rule runs before anything is written and puts the
                         * message against the field that caused it — the same
                         * shape as the order-number rule on the settings page.
                         *
                         * SetStaffAccess re-checks regardless. This is the
                         * message; that is the enforcement.
                         */
                        ->rule(static fn (?User $record): Closure => static function (
                            string $attribute,
                            mixed $value,
                            Closure $fail,
                        ) use ($record): void {
                            if ($record === null) {
                                // Inviting somebody new cannot remove the last
                                // Super Admin's role.
                                return;
                            }

                            $names = Role::query()->whereKey((array) $value)->pluck('name')->all();
                            $reason = StaffLockout::reasonCannotAssign($record, $names, auth()->user());

                            if ($reason !== null) {
                                $fail($reason);
                            }
                        }),
                ]),
        ]);
    }
}
