<?php

declare(strict_types=1);

namespace App\Filament\Resources\Staff\Pages;

use App\Enums\UserRole;
use App\Filament\Resources\Staff\StaffResource;
use App\Models\User;
use App\Support\StaffLockout;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListStaff extends ListRecords
{
    protected static string $resource = StaffResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Invite somebody')];
    }

    /**
     * Warn when there is only one way back in.
     *
     * A single active Super Admin is not an error — a small business often has
     * one — but it is a single point of failure that nobody thinks about until
     * that person is on holiday and something needs changing.
     */
    public function mount(): void
    {
        parent::mount();

        if (StaffLockout::activeSuperAdminCount() === 1) {
            Notification::make()
                ->warning()
                ->title('Only one Super Admin')
                ->body('If that account is lost, nobody can get back into the panel. Consider giving a second person the role.')
                ->send();
        }
    }

    /**
     * Who can get in, who cannot, and who is unprotected.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'active' => Tab::make('Active')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereNull('deactivated_at'))
                ->badge(fn (): int => User::query()->whereHas('roles')->whereNull('deactivated_at')->count()),

            'deactivated' => Tab::make('Deactivated')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereNotNull('deactivated_at'))
                ->badgeColor('danger'),

            /*
             * §3 promises admin 2FA and nothing enforces it. Until something
             * does, this tab is the enforcement: a list somebody can look at
             * and act on.
             */
            'unprotected' => Tab::make('No 2FA')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->whereNull('deactivated_at')
                    ->whereNull('two_factor_confirmed_at'))
                ->badge(fn (): int => User::query()
                    ->whereHas('roles')
                    ->whereNull('deactivated_at')
                    ->whereNull('two_factor_confirmed_at')
                    ->count())
                ->badgeColor('warning'),

            'super_admins' => Tab::make('Super Admins')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->whereHas('roles', fn (Builder $roles): Builder => $roles
                        ->where('name', UserRole::SuperAdmin->value))),

            'all' => Tab::make('Everyone'),
        ];
    }
}
