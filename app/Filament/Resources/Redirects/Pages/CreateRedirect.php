<?php

declare(strict_types=1);

namespace App\Filament\Resources\Redirects\Pages;

use App\Filament\Resources\Redirects\RedirectResource;
use Filament\Resources\Pages\CreateRecord;

class CreateRedirect extends CreateRecord
{
    protected static string $resource = RedirectResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
