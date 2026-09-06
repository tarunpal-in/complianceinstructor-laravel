<?php

declare(strict_types=1);

namespace App\Filament\Resources\Media\Pages;

use App\Actions\Media\DeleteLibraryMedia;
use App\Actions\Media\ReplaceLibraryMedia;
use App\Actions\Media\StoreLibraryMedia;
use App\Actions\Media\SyncMediaFolders;
use App\Filament\Resources\Media\MediaResource;
use App\Models\Media;
use App\Models\MediaFolder;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The whole media manager: browse, upload, edit, replace, delete.
 *
 * A library is a browser with modals rather than a create-then-edit flow, so the
 * resource has this one page and no create or edit route.
 */
class ListMedia extends ListRecords
{
    protected static string $resource = MediaResource::class;

    /**
     * Grid or list. Held here rather than in the table so it survives a filter
     * change, and mirrored into the session so it survives a page load — a
     * client who works in list view should not be put back into grid every time.
     */
    public string $viewMode = 'grid';

    public function mount(): void
    {
        parent::mount();

        $this->viewMode = session('media.view_mode') === 'list' ? 'list' : 'grid';
    }

    public function toggleViewMode(): void
    {
        $this->viewMode = $this->viewMode === 'grid' ? 'list' : 'grid';

        session(['media.view_mode' => $this->viewMode]);

        // The columns are chosen when the table is built, so it has to be
        // rebuilt rather than merely re-rendered.
        $this->resetTable();
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->uploadAction(),
            $this->manageFoldersAction(),
            $this->viewModeAction(),
        ];
    }

    // Header actions ----------------------------------------------------------

    private function uploadAction(): Action
    {
        return Action::make('upload')
            ->label('Upload')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->visible(fn (): bool => auth()->user()?->can('create_media') ?? false)
            ->modalHeading('Upload to the library')
            ->modalSubmitActionLabel('Upload')
            ->schema([
                Select::make('folder_id')
                    ->label('Folder')
                    ->options(fn (): array => MediaFolder::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->default(fn (): int => MediaFolder::uncategorised()->id)
                    ->searchable()
                    ->required(),

                FileUpload::make('files')
                    ->label('Files')
                    ->multiple()
                    ->required()
                    ->acceptedFileTypes(self::acceptedMimeTypes())
                    ->maxSize((int) config('media.max_upload_kb'))
                    // Handed to StoreLibraryMedia as uploaded files rather than
                    // stored by Filament: hashing, de-duplication and text
                    // extraction all belong in the action (§4 rule 2).
                    ->storeFiles(false)
                    ->helperText('Images, PDFs and documents. Up to '
                        .round(((int) config('media.max_upload_kb')) / 1024).' MB each.'),
            ])
            ->action(function (array $data): void {
                $folder = MediaFolder::find($data['folder_id']) ?? MediaFolder::uncategorised();

                $stored = 0;
                $duplicates = [];

                foreach ($data['files'] as $file) {
                    if (! $file instanceof TemporaryUploadedFile) {
                        continue;
                    }

                    $result = app(StoreLibraryMedia::class)($file, $folder);

                    if ($result->wasDuplicate) {
                        $duplicates[] = $result->media->name;

                        continue;
                    }

                    $stored++;
                }

                // A bulk upload that silently reuses six existing files looks
                // like it lost them, so duplicates are named rather than counted.
                Notification::make()
                    ->title($stored === 1 ? '1 file uploaded' : "{$stored} files uploaded")
                    ->body($duplicates === []
                        ? null
                        : 'Already in the library, so reused rather than duplicated: '.implode(', ', $duplicates))
                    ->success()
                    ->send();
            });
    }

    /**
     * Create, rename, re-nest, reorder and delete, in one list.
     *
     * A repeater rather than a folder resource of its own: folders are a filing
     * aid for one screen, not a thing worth its own sidebar item and its own
     * CRUD pages.
     */
    private function manageFoldersAction(): Action
    {
        return Action::make('folders')
            ->label('Folders')
            ->icon(Heroicon::OutlinedFolder)
            ->color('gray')
            ->visible(fn (): bool => auth()->user()?->can('update_media') ?? false)
            ->modalHeading('Folders')
            ->modalDescription('Deleting a folder never deletes what is in it — those files move to Uncategorised.')
            ->modalSubmitActionLabel('Save folders')
            ->fillForm(fn (): array => [
                'folders' => MediaFolder::query()
                    ->where('slug', '!=', 'uncategorised')
                    ->orderBy('sort_order')
                    ->orderBy('name')
                    ->get()
                    ->map(fn (MediaFolder $folder): array => [
                        'id' => $folder->id,
                        'name' => $folder->name,
                        'parent_id' => $folder->parent_id,
                    ])
                    ->all(),
            ])
            ->schema([
                Repeater::make('folders')
                    ->hiddenLabel()
                    ->addActionLabel('Add folder')
                    ->reorderable()
                    ->columns(2)
                    ->itemLabel(fn (array $state): ?string => $state['name'] ?? null)
                    ->schema([
                        Hidden::make('id'),

                        TextInput::make('name')
                            ->required()
                            ->maxLength(60),

                        Select::make('parent_id')
                            ->label('Inside')
                            ->placeholder('Top level')
                            // Only top-level folders may be parents. One level of
                            // nesting is a filing system; three is where files
                            // get lost.
                            ->options(fn (): array => MediaFolder::query()
                                ->whereNull('parent_id')
                                ->where('slug', '!=', 'uncategorised')
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all()),
                    ]),
            ])
            ->action(function (array $data): void {
                /** @var array<int, array{id: int|string|null, name: string, parent_id: int|string|null}> $rows */
                $rows = $data['folders'] ?? [];

                $result = app(SyncMediaFolders::class)($rows);

                $parts = array_filter([
                    $result['created'] > 0 ? "{$result['created']} added" : null,
                    $result['renamed'] > 0 ? "{$result['renamed']} renamed" : null,
                    $result['deleted'] > 0 ? "{$result['deleted']} deleted" : null,
                    $result['relocated'] > 0 ? "{$result['relocated']} file(s) moved to Uncategorised" : null,
                ]);

                Notification::make()
                    ->title('Folders saved')
                    ->body($parts === [] ? null : implode(' · ', $parts))
                    ->success()
                    ->send();

                $this->resetTable();
            });
    }

    private function viewModeAction(): Action
    {
        return Action::make('viewMode')
            ->label(fn (): string => $this->viewMode === 'grid' ? 'List view' : 'Grid view')
            ->icon(fn (): Heroicon => $this->viewMode === 'grid'
                ? Heroicon::OutlinedBars3
                : Heroicon::OutlinedSquares2x2)
            ->color('gray')
            ->action(fn () => $this->toggleViewMode());
    }

    // Record actions ----------------------------------------------------------

    /** @return array<int, Action|DeleteAction|EditAction> */
    protected function getTableRecordActions(): array
    {
        return [
            EditAction::make()
                ->modalHeading(fn (Media $record): string => $record->name)
                ->slideOver(),

            $this->replaceAction(),
            $this->copyUrlAction(),
            $this->downloadAction(),
            $this->guardedDeleteAction(),
        ];
    }

    /**
     * Swapping the bytes while keeping the id, which is what keeps every
     * reference working. See ReplaceLibraryMedia.
     */
    private function replaceAction(): Action
    {
        return Action::make('replace')
            ->label('Replace file')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->visible(fn (Media $record): bool => $record->isLibraryItem()
                && (auth()->user()?->can('update_media') ?? false))
            ->modalHeading('Replace this file')
            ->modalDescription('Everything using it updates at once. The link does not change unless the file type does.')
            ->schema([
                FileUpload::make('file')
                    ->label('New file')
                    ->required()
                    ->acceptedFileTypes(self::acceptedMimeTypes())
                    ->maxSize((int) config('media.max_upload_kb'))
                    ->storeFiles(false),
            ])
            ->action(function (Media $record, array $data): void {
                $file = $data['file'];

                if (! $file instanceof TemporaryUploadedFile) {
                    return;
                }

                app(ReplaceLibraryMedia::class)($record, $file);

                Notification::make()
                    ->title('File replaced')
                    ->body('Everywhere it is used now shows the new file.')
                    ->success()
                    ->send();
            });
    }

    private function copyUrlAction(): Action
    {
        return Action::make('copyUrl')
            ->label('Copy URL')
            ->icon(Heroicon::OutlinedLink)
            ->color('gray')
            // Clipboard access is client-side; a round trip to the server to
            // copy a string the page already has would be silly.
            ->alpineClickHandler(fn (Media $record): string => 'window.navigator.clipboard.writeText('
                .\Illuminate\Support\Js::from($record->getFullUrl()).
                '); $tooltip("Copied", { timeout: 1500 })');
    }

    private function downloadAction(): Action
    {
        return Action::make('download')
            ->label('Download')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->action(fn (Media $record): StreamedResponse => $this->streamDownload($record));
    }

    /**
     * Streamed rather than read into memory: a 60 MB deck must not become 60 MB
     * of PHP memory per concurrent download.
     */
    private function streamDownload(Media $record): StreamedResponse
    {
        $disk = Storage::disk($record->disk);
        $path = $record->getPathRelativeToRoot();

        return Response::streamDownload(
            function () use ($disk, $path): void {
                $stream = $disk->readStream($path);

                if ($stream === null) {
                    return;
                }

                fpassthru($stream);
                fclose($stream);
            },
            $record->file_name,
            ['Content-Type' => (string) $record->mime_type],
        );
    }

    /**
     * Refuses rather than cascades.
     *
     * Deleting the homepage hero because nothing said it was in use is the
     * classic media-manager disaster; listing where a file is used is more
     * useful than leaving three pages with broken images.
     */
    private function guardedDeleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->modalHeading('Delete this file?')
            ->modalDescription('This removes the file itself, not just the entry. It cannot be undone.')
            ->using(function (Media $record): void {
                $blockers = app(DeleteLibraryMedia::class)($record);

                if ($blockers === []) {
                    Notification::make()->title('File deleted')->success()->send();

                    return;
                }

                Notification::make()
                    ->title('Still in use — not deleted')
                    ->body('<p class="mb-1">Replace it instead, or remove it from:</p><ul class="list-disc ps-4"><li>'
                        .implode('</li><li>', array_map('e', $blockers)).'</li></ul>')
                    ->danger()
                    ->persistent()
                    ->send();
            });
    }

    // Bulk actions ------------------------------------------------------------

    /** @return array<int, BulkActionGroup> */
    protected function getTableToolbarActions(): array
    {
        return [
            BulkActionGroup::make([
                $this->bulkMoveAction(),
                $this->bulkDeleteAction(),
            ]),
        ];
    }

    private function bulkMoveAction(): BulkAction
    {
        return BulkAction::make('move')
            ->label('Move to folder')
            ->icon(Heroicon::OutlinedFolderArrowDown)
            ->visible(fn (): bool => auth()->user()?->can('update_media') ?? false)
            ->schema([
                Select::make('folder_id')
                    ->label('Folder')
                    ->options(fn (): array => MediaFolder::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->required(),
            ])
            ->action(function (Collection $records, array $data): void {
                // Only library rows move. An attached file's owner is the record
                // that uses it, and reassigning that here would detach a webinar
                // from its own banner.
                $moved = Media::query()
                    ->whereKey($records->modelKeys())
                    ->where('model_type', MediaFolder::class)
                    ->update(['model_id' => $data['folder_id']]);

                $skipped = $records->count() - $moved;

                Notification::make()
                    ->title($moved.' moved')
                    ->body($skipped > 0 ? "{$skipped} left alone — they belong to a record, not the library." : null)
                    ->success()
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    private function bulkDeleteAction(): BulkAction
    {
        return BulkAction::make('delete')
            ->label('Delete')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Delete the selected files?')
            ->modalDescription('Files that are in use will be kept and listed.')
            ->visible(fn (): bool => auth()->user()?->can('delete_media') ?? false)
            ->action(function (Collection $records): void {
                $deleter = app(DeleteLibraryMedia::class);

                $deleted = 0;
                $kept = [];

                // Re-read as Media rather than trusting the selection's type:
                // the guard and the delete both need the subclass, and a fresh
                // read also means a file deleted in another tab is not counted.
                $selected = Media::query()->whereKey($records->modelKeys())->get();

                foreach ($selected as $record) {
                    if ($deleter($record) === []) {
                        $deleted++;

                        continue;
                    }

                    $kept[] = $record->name;
                }

                $notification = Notification::make()
                    ->title($deleted.' deleted')
                    ->body($kept === [] ? null : 'Kept because they are still in use: '.implode(', ', $kept));

                if ($kept === []) {
                    $notification->success()->send();

                    return;
                }

                // Persistent only when something was refused: the client has to
                // read which files survived, and a toast that vanishes in four
                // seconds would leave them believing the whole batch went.
                $notification->warning()->persistent()->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    /**
     * The accept list the browser file picker uses.
     *
     * Video is absent deliberately (docs/media-manager.md §2) — recordings are
     * gigabytes each and live behind an entitlement check, not in a browsable
     * library.
     *
     * @return array<int, string>
     */
    private static function acceptedMimeTypes(): array
    {
        return [
            'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/svg+xml',
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/vnd.oasis.opendocument.text',
            'application/vnd.oasis.opendocument.spreadsheet',
            'text/csv', 'text/plain', 'application/rtf',
        ];
    }
}
