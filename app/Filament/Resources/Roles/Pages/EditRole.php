<?php

declare(strict_types=1);

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\Roles\RoleResource;
use App\Models\Role;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    public function getTitle(): string
    {
        /** @var Role $record */
        $record = $this->getRecord();

        return $record->displayName();
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->visible(fn (Role $record): bool => $record->isDeletable()),
        ];
    }

    /**
     * A system role refuses to save.
     *
     * Every field on it is already disabled, so reaching this means a crafted
     * request rather than a mistake — and the fields being disabled is a
     * property of how the form was rendered, while this is the check that
     * decides. RolePermissionSeeder would revert the change on the next deploy
     * regardless, which is a worse way to find out.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Role $record */
        if ($record->isSystem()) {
            throw ValidationException::withMessages([
                'data.name' => 'This is a built-in role. Its permissions are set in code and would be restored on the next deploy.',
            ]);
        }

        return parent::handleRecordUpdate($record, $data);
    }
}
