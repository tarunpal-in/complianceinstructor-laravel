<?php

declare(strict_types=1);

namespace App\Filament\Resources\Industries\Pages;

use App\Filament\Concerns\ResolvesSlugOnSave;
use App\Filament\Resources\Industries\IndustryResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditIndustry extends EditRecord
{
    use ResolvesSlugOnSave;

    protected static string $resource = IndustryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
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
