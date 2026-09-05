<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accreditations\Pages;

use App\Filament\Resources\Accreditations\AccreditationResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAccreditation extends CreateRecord
{
    protected static string $resource = AccreditationResource::class;
}
