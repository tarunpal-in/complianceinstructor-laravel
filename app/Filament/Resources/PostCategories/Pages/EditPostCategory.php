<?php

declare(strict_types=1);

namespace App\Filament\Resources\PostCategories\Pages;

use App\Filament\Concerns\ResolvesSlugOnSave;
use App\Filament\Resources\PostCategories\PostCategoryResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditPostCategory extends EditRecord
{
    use ResolvesSlugOnSave;

    protected static string $resource = PostCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make(), RestoreAction::make()];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->resolveSlug($data);
    }

    protected function afterSave(): void
    {
        $this->notifyIfSlugChanged();
    }
}
