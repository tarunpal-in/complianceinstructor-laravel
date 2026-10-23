<?php

declare(strict_types=1);

namespace App\Filament\Resources\CorePages\Pages;

use App\Filament\Resources\CorePages\CorePageResource;
use Filament\Resources\Pages\ListRecords;

class ListCorePages extends ListRecords
{
    protected static string $resource = CorePageResource::class;

    /** No create button — the four core pages are a fixed, seeded set. */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
