<?php

declare(strict_types=1);

namespace App\Filament\Resources\Media\Schemas;

use App\Enums\MediaKind;
use App\Models\Media;
use App\Models\MediaFolder;
use App\Models\Tag;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

/**
 * The edit panel for one file.
 *
 * Everything editable is metadata — the bytes are changed by the Replace action,
 * which keeps the id and URL so that references do not break.
 */
class MediaForm
{
    public static function configure(Schema $schema): Schema
    {
        // One column, so each section stacks full-width down the page instead
        // of tiling two-up. Field pairs inside a section are kept.
        return $schema->columns(1)->components([

            View::make('filament.schemas.media-preview')->columnSpanFull(),

            Section::make()->columns(2)->schema([
                TextInput::make('name')
                    ->label('Title')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull()
                    ->helperText('What this file is called in the library. The stored file name does not change.'),

                Textarea::make('alt')
                    ->label('Alt text')
                    ->rows(2)
                    ->maxLength(255)
                    ->columnSpanFull()
                    // Only images can fail WCAG AA on alt text; asking for it on
                    // a PDF would train the client to type something meaningless.
                    ->visible(fn (?Media $record): bool => $record?->kind() === MediaKind::Image)
                    ->helperText('Describe what the image shows, for screen readers and when it fails to load. Leave empty only if it is purely decorative.'),

                Textarea::make('caption')
                    ->label('Caption')
                    ->rows(2)
                    ->maxLength(500)
                    ->columnSpanFull()
                    ->helperText('Optional. Searchable, and shown by blocks that display one.'),

                Select::make('model_id')
                    ->label('Folder')
                    ->options(fn (): array => MediaFolder::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->required()
                    // An attached file's owner is the webinar or speaker that
                    // uses it. Letting it be moved into a folder here would
                    // silently detach it from that record.
                    ->disabled(fn (?Media $record): bool => $record !== null && ! $record->isLibraryItem())
                    ->helperText(fn (?Media $record): ?string => $record !== null && ! $record->isLibraryItem()
                        ? 'This file belongs to a '.class_basename($record->model_type).' and is managed on that record.'
                        : null),

                Select::make('tags')
                    ->label('Tags')
                    ->relationship('tags', 'name')
                    ->multiple()
                    ->preload()
                    ->searchable()
                    ->createOptionForm([
                        TextInput::make('name')->required()->maxLength(60),
                    ])
                    ->createOptionUsing(fn (array $data): int => Tag::findOrCreateNamed((string) $data['name'])->id)
                    ->helperText('Cuts across folders — a file lives in one folder but can carry several tags.'),
            ]),

            Section::make('File')
                ->collapsed()
                ->schema([
                    Text::make(fn (Media $record): HtmlString => new HtmlString(
                        e($record->file_name).' · '.e($record->kind()->label()).' · '.e($record->readableSize())
                        .($record->document?->page_count !== null ? ' · '.$record->document->page_count.' pages' : '')
                    )),

                    Text::make(fn (Media $record): string => self::indexingNote($record))
                        ->visible(fn (Media $record): bool => $record->kind()->isSearchableDocument()),
                ]),
        ]);
    }

    /**
     * Says why a PDF may not be findable by its contents.
     *
     * A scanned document has no text layer and extracts nothing. That is the
     * expected result rather than a fault — OCR is out of scope — but the client
     * needs telling, otherwise the library looks broken.
     */
    private static function indexingNote(Media $record): string
    {
        $document = $record->document;

        return match (true) {
            $document === null => 'Contents have not been indexed yet.',
            $document->extract_error !== null => 'Contents could not be indexed: '.$document->extract_error,
            $document->hasNoTextLayer() => 'No text found — this looks like a scan, so searching will not match its contents.',
            default => 'Contents are indexed and searchable.',
        };
    }
}
