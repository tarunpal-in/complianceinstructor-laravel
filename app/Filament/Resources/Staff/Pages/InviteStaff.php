<?php

declare(strict_types=1);

namespace App\Filament\Resources\Staff\Pages;

use App\Actions\Staff\InviteStaffMember;
use App\Filament\Resources\Staff\StaffResource;
use App\Models\Role;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class InviteStaff extends CreateRecord
{
    protected static string $resource = StaffResource::class;

    protected static ?string $title = 'Invite somebody';

    /**
     * Through the action, so no screen ever writes a password.
     *
     * The account is created with an unusable random one and Fortify sends a
     * reset link. §9's first critical finding was an endpoint that let somebody
     * set an administrator's password; a form field doing the same thing from
     * inside the panel is the same hole with a login in front of it.
     */
    protected function handleRecordCreation(array $data): Model
    {
        /*
         * From the live form state, not $data. Filament strips a field
         * declared with relationship() out of $data and saves it separately
         * afterwards — so reading $data here would see no roles at all and
         * invite somebody to nothing.
         */
        /** @var array<int, int|string> $roleIds */
        $roleIds = $this->data['roles'] ?? [];

        $roles = Role::query()->whereKey($roleIds)->pluck('name')->all();

        return app(InviteStaffMember::class)(
            name: (string) $data['name'],
            email: (string) $data['email'],
            roles: $roles,
            actor: auth()->user(),
        );
    }

    protected function getRedirectUrl(): string
    {
        return StaffResource::getUrl('index');
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Invitation sent';
    }
}
