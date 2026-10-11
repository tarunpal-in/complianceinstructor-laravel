<?php

declare(strict_types=1);

namespace App\Filament\Resources\Redirects\Pages;

use App\Filament\Resources\Redirects\RedirectResource;
use App\Seo\RedirectImporter;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class ListRedirects extends ListRecords
{
    protected static string $resource = RedirectResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->importAction(),
            CreateAction::make(),
        ];
    }

    /**
     * Bulk-load the old site's URLs — the migration's biggest SEO task.
     *
     * Gated on create, since it creates redirects. Each row is validated exactly
     * as the form validates one, so an import can never load a chain, a loop or a
     * source that shadows a live page; those rows are skipped and counted, and
     * the first few reasons are shown so the CSV can be fixed.
     */
    private function importAction(): Action
    {
        return Action::make('import')
            ->label('Import CSV')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->color('gray')
            ->visible(fn (): bool => auth()->user()?->can('create_redirect') ?? false)
            ->modalHeading('Import redirects from a CSV')
            ->modalDescription('One redirect per row: old path, new path, and optionally 301 or 302. A header row is fine. Rows that would chain, loop or hide a live page are skipped.')
            ->schema([
                FileUpload::make('csv')
                    ->label('CSV file')
                    ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv'])
                    ->storeFiles(false)
                    ->required(),
            ])
            ->action(function (array $data): void {
                $file = $data['csv'];

                if (! $file instanceof TemporaryUploadedFile) {
                    Notification::make()->danger()->title('No file received')->send();

                    return;
                }

                $result = RedirectImporter::fromFile($file->getRealPath());

                $body = "{$result['imported']} imported, {$result['skipped']} skipped.";

                if ($result['problems'] !== []) {
                    $body .= "\n\n".implode("\n", array_slice($result['problems'], 0, 8));
                }

                Notification::make()
                    ->title('Import finished')
                    ->body($body)
                    ->success()
                    ->send();
            });
    }
}
