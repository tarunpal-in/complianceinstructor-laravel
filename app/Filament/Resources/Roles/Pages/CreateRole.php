<?php

declare(strict_types=1);

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\Roles\RoleResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateRole extends CreateRecord
{
    protected static string $resource = RoleResource::class;

    protected static ?string $title = 'Add a role';

    /**
     * Anything created here is the client's, never the seeder's.
     *
     * `is_system` is set explicitly rather than left to the column default, so
     * the one property that decides whether the seeder will overwrite this role
     * is stated at the point it is created rather than inherited silently.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $data['is_system'] = false;
        $data['guard_name'] = 'web';

        return static::getModel()::create($data);
    }
}
