<?php

declare(strict_types=1);

namespace App\Filament\Resources\Posts\Pages;

use App\Filament\Concerns\ResolvesSlugOnSave;
use App\Filament\Resources\Posts\PostResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePost extends CreateRecord
{
    use ResolvesSlugOnSave;

    protected static string $resource = PostResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->resolveSlug($data);
    }

    /**
     * Land on the edit page rather than the list.
     *
     * The publish gate and the preview link both live there, and an article is
     * almost never finished in one sitting — being bounced to a list after
     * saving a draft means finding it again to carry on.
     */
    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }

    protected function afterCreate(): void
    {
        $this->notifyIfSlugChanged();
    }
}
