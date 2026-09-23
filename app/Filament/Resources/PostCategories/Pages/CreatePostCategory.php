<?php

declare(strict_types=1);

namespace App\Filament\Resources\PostCategories\Pages;

use App\Filament\Concerns\ResolvesSlugOnSave;
use App\Filament\Resources\PostCategories\PostCategoryResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePostCategory extends CreateRecord
{
    use ResolvesSlugOnSave;

    protected static string $resource = PostCategoryResource::class;

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
