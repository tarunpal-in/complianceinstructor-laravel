<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pages\Pages;

use App\Filament\Concerns\ResolvesSlugOnSave;
use App\Filament\Resources\Pages\PageResource;
use App\Models\Page;
use Filament\Resources\Pages\CreateRecord;

class CreatePage extends CreateRecord
{
    use ResolvesSlugOnSave, SyncsPageSections;

    protected static string $resource = PageResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Blocks are rows in `page_sections`, not a column on `pages`, so they
        // come out of the payload here and are written in afterCreate().
        return $this->resolveSlug($this->liftSections($data));
    }

    protected function afterCreate(): void
    {
        /** @var Page $page */
        $page = $this->getRecord();

        $this->writeSections($page);
        $this->notifyIfSlugChanged();
    }
}
