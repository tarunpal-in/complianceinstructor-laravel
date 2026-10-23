<?php

declare(strict_types=1);

namespace App\Filament\Resources\CorePages\Pages;

use App\Filament\Resources\CorePages\CorePageResource;
use App\Models\CorePage;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

/**
 * The sections repeater and the SEO panel save through `->relationship()`, so
 * this needs no fill/save glue — and no delete, the pages being a fixed set.
 */
class EditCorePage extends EditRecord
{
    protected static string $resource = CorePageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('visit')
                ->label('View page')
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->color('gray')
                ->url(fn (CorePage $record): string => $record->key->path())
                ->openUrlInNewTab(),
        ];
    }
}
