<?php

declare(strict_types=1);

namespace App\Filament\Resources\Webinars\Pages;

use App\Filament\Resources\Webinars\WebinarResource;
use Filament\Resources\Pages\CreateRecord;

class CreateWebinar extends CreateRecord
{
    protected static string $resource = WebinarResource::class;
}
