<?php

declare(strict_types=1);

namespace App\Filament\Resources\Speakers\Pages;

use App\Filament\Concerns\ResolvesSlugOnSave;
use App\Filament\Resources\Speakers\SpeakerResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSpeaker extends CreateRecord
{
    use ResolvesSlugOnSave;

    protected static string $resource = SpeakerResource::class;

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
