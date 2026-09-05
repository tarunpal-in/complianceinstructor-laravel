<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accreditations\Pages;

use App\Filament\Resources\Accreditations\AccreditationResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAccreditation extends EditRecord
{
    protected static string $resource = AccreditationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
