<?php

declare(strict_types=1);

namespace App\Filament\Resources\Industries\Pages;

use App\Filament\Concerns\ResolvesSlugOnSave;
use App\Filament\Resources\Industries\IndustryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateIndustry extends CreateRecord
{
    use ResolvesSlugOnSave;

    protected static string $resource = IndustryResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->resolveSlug($data);
    }

    protected function afterCreate(): void
    {
        $this->notifyIfSlugChanged();
    }
}
