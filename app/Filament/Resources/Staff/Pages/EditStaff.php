<?php

declare(strict_types=1);

namespace App\Filament\Resources\Staff\Pages;

use App\Actions\Staff\SetStaffAccess;
use App\Filament\Resources\Staff\StaffResource;
use App\Models\Role;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Throwable;

class EditStaff extends EditRecord
{
    protected static string $resource = StaffResource::class;

    /**
     * Role changes go through the guard, not straight to syncRoles.
     *
     * The dangerous save here is the quiet one: an administrator tidying a
     * role list and taking their own Super Admin off, or removing it from the
     * last account that had it. Both succeed silently against the relationship
     * and leave nobody able to undo them.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var User $record */
        /*
         * From the live form state, not $data. Filament strips a field
         * declared with relationship() out of $data and saves it separately
         * afterwards, so $data['roles'] is always absent here — which would
         * read as "every role removed" and fire the guard on every save.
         */
        /** @var array<int, int|string> $roleIds */
        $roleIds = $this->data['roles'] ?? [];

        $roles = Role::query()->whereKey($roleIds)->pluck('name')->all();

        $record->forceFill([
            'name' => (string) $data['name'],
            'email' => (string) $data['email'],
        ])->save();

        try {
            app(SetStaffAccess::class)->assign($record, $roles, auth()->user());
        } catch (Throwable $exception) {
            Notification::make()->danger()->title('Roles unchanged')->body($exception->getMessage())->send();
        }

        return $record->refresh();
    }
}
