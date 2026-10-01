<?php

declare(strict_types=1);

namespace App\Filament\Resources\Staff\Tables;

use App\Actions\Staff\SetStaffAccess;
use App\Models\Role;
use App\Models\User;
use App\Support\StaffLockout;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Throwable;

/**
 * The staff list.
 *
 * Reads as an access register rather than a contact list: what each person can
 * do, whether they can currently get in, and whether their account is protected
 * by anything more than a password.
 */
class StaffTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->description(fn (User $record): string => $record->email)
                    ->wrap()
                    ->width('16rem'),

                TextColumn::make('roles.name')
                    ->label('Can do')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::roleLabel($state))
                    ->color('info')
                    ->wrap(),

                TextColumn::make('access')
                    ->label('Access')
                    ->badge()
                    ->state(fn (User $record): string => $record->describeAccess())
                    ->color(fn (User $record): string => $record->accessColor())
                    // Why they were switched off, beside the fact that they
                    // were. The question that follows is always "who and why".
                    ->description(fn (User $record): ?string => $record->isDeactivated()
                        ? trim(($record->deactivation_reason ?? 'No reason recorded')
                            .' · '.self::deactivatorName($record))
                        : null)
                    ->wrap(),

                /*
                 * §3 promises admin 2FA. Nothing enforces it yet, so the least
                 * this screen can do is make an unprotected administrator
                 * account visible rather than something nobody thinks about.
                 */
                TextColumn::make('two_factor')
                    ->label('2FA')
                    ->badge()
                    ->state(fn (User $record): string => $record->hasTwoFactorEnabled() ? 'On' : 'Off')
                    ->color(fn (User $record): string => $record->hasTwoFactorEnabled() ? 'success' : 'warning')
                    ->tooltip(fn (User $record): ?string => $record->hasTwoFactorEnabled()
                        ? null
                        : 'This account is protected by a password alone.'),

                TextColumn::make('email_verified_at')
                    ->label('Verified')
                    ->dateTime('j M Y')
                    ->placeholder('Never')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('Added')
                    ->dateTime('j M Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name')
            ->filters([
                SelectFilter::make('roles')
                    ->label('Role')
                    ->relationship('roles', 'name')
                    ->getOptionLabelFromRecordUsing(fn (Role $record): string => $record->displayName()),
            ], layout: FiltersLayout::AboveContentCollapsible)
            ->recordActions([
                EditAction::make(),
                self::deactivateAction(),
                self::reinstateAction(),
                self::resendInvitationAction(),
                self::clearTwoFactorAction(),
            ])
            ->emptyStateHeading('Nobody here yet')
            ->emptyStateDescription('Invite somebody to give them access to this panel.');
    }

    /**
     * Switch somebody's access off.
     *
     * Hidden rather than disabled where it is refused, because the refusals
     * here are about the person looking — their own account, or the last way
     * back into the panel — and a greyed button with no explanation reads as a
     * fault. The reason is shown in the modal for the cases that are worth
     * explaining.
     */
    private static function deactivateAction(): Action
    {
        return Action::make('deactivate')
            ->label('Deactivate')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->visible(fn (User $record): bool => ! $record->isDeactivated() && self::canManage())
            ->disabled(fn (User $record): bool => ! StaffLockout::canDeactivate($record, auth()->user()))
            ->tooltip(fn (User $record): ?string => StaffLockout::reasonCannotDeactivate($record, auth()->user()))
            ->modalHeading('Take away admin access?')
            ->modalDescription('They stop being able to reach this panel immediately. Their roles are kept, and everything they did still shows their name.')
            ->schema([
                Textarea::make('reason')
                    ->label('Why')
                    ->rows(2)
                    ->maxLength(255)
                    ->placeholder('Left the company on 30 September')
                    ->helperText('Shown on the list, so the next person does not have to ask.'),
            ])
            ->action(function (User $record, array $data): void {
                $reason = $data['reason'] ?? null;

                try {
                    app(SetStaffAccess::class)->deactivate(
                        $record,
                        is_string($reason) && trim($reason) !== '' ? trim($reason) : null,
                        auth()->user(),
                    );
                } catch (Throwable $exception) {
                    Notification::make()->danger()->title('Not deactivated')->body($exception->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title('Access removed')->send();
            });
    }

    private static function reinstateAction(): Action
    {
        return Action::make('reinstate')
            ->label('Reinstate')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('success')
            ->visible(fn (User $record): bool => $record->isDeactivated() && self::canManage())
            ->requiresConfirmation()
            ->modalHeading('Give this account access again?')
            ->modalDescription(fn (User $record): string => 'They get back the roles they had: '
                .self::roleList($record).'.')
            ->action(function (User $record): void {
                app(SetStaffAccess::class)->reinstate($record);

                Notification::make()->success()->title('Access restored')->send();
            });
    }

    /** For somebody who never received the first one, or let it expire. */
    private static function resendInvitationAction(): Action
    {
        return Action::make('resendInvitation')
            ->label('Resend invite')
            ->icon(Heroicon::OutlinedEnvelope)
            ->color('gray')
            ->visible(fn (User $record): bool => ! $record->isDeactivated() && self::canManage())
            ->requiresConfirmation()
            ->modalHeading('Send another link?')
            ->modalDescription('They get a fresh link to set their own password. Nobody else can see or set it.')
            ->action(function (User $record): void {
                app(SetStaffAccess::class)->resendInvitation($record, auth()->user());

                Notification::make()->success()->title('Link sent')->send();
            });
    }

    /**
     * The way back for somebody who has lost their authenticator.
     *
     * On this screen rather than being a database job, because the alternative
     * is a developer running SQL against production every time an
     * administrator changes phone — which is worse in every way.
     */
    private static function clearTwoFactorAction(): Action
    {
        return Action::make('clearTwoFactor')
            ->label('Reset 2FA')
            ->icon(Heroicon::OutlinedDevicePhoneMobile)
            ->color('warning')
            ->visible(fn (User $record): bool => $record->hasTwoFactorEnabled() && self::canManage())
            ->requiresConfirmation()
            ->modalHeading('Clear their two-factor setup?')
            ->modalDescription('Use this when somebody has lost their device. Until they set it up again, their account is protected by a password alone.')
            ->action(function (User $record): void {
                app(SetStaffAccess::class)->clearTwoFactor($record, auth()->user());

                Notification::make()->success()->title('Two-factor cleared')->send();
            });
    }

    /**
     * A role's display name from its stored identifier.
     *
     * Named rather than inline: the column hands over a plain string and the
     * lookup is easier to read with a name on it.
     */
    private static function roleLabel(string $name): string
    {
        return Role::query()->where('name', $name)->first()?->displayName() ?? $name;
    }

    private static function deactivatorName(User $record): string
    {
        $actor = $record->deactivatedBy;

        // The relation is nullable — `deactivated_by` is nullOnDelete, so the
        // administrator who switched somebody off may themselves be gone.
        return $actor === null ? 'unknown' : $actor->name;
    }

    /** Their roles, written out, for a confirmation that says what it will do. */
    private static function roleList(User $record): string
    {
        // getRoleNames() rather than the relation: spatie types `roles` as a
        // collection of Model, so mapping over it cannot see displayName().
        return $record->getRoleNames()
            ->map(fn (string $name): string => self::roleLabel($name))
            ->implode(', ');
    }

    private static function canManage(): bool
    {
        return auth()->user()?->can('update_role') ?? false;
    }
}
