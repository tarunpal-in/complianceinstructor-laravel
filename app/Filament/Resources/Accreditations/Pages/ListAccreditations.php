<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accreditations\Pages;

use App\Filament\Resources\Accreditations\AccreditationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAccreditations extends ListRecords
{
    protected static string $resource = AccreditationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
