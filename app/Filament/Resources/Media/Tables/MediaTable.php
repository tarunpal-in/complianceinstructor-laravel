<?php

declare(strict_types=1);

namespace App\Filament\Resources\Media\Tables;

use App\Enums\MediaKind;
use App\Models\Media;
use App\Models\MediaFolder;
use App\Models\Tag;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\Layout\View as ViewColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The library browser.
 *
 * Two layouts over one query: a grid, because that is how a non-technical client
 * recognises a file, and a list, because a grid is useless for "what is eating
 * four gigabytes". The page holds the choice; see ListMedia.
 */
class MediaTable
{
    public static function configure(Table $table): Table
    {
        $isGrid = self::isGrid($table);

        return $table
            ->columns($isGrid ? self::gridColumns() : self::listColumns())
            // Declared on the table rather than per column so it works in grid
            // mode too, where the only column is a rendered card.
            ->searchable([
                'name',
                'file_name',
                'custom_properties.caption',

                // The one that makes a document library findable: a compliance
                // catalogue fills with near-identically named PDFs, and matching
                // file names alone means the client never finds anything they
                // did not upload this week. docs/media-manager.md §6.2.
                fn (Builder $query, string $search): Builder => $query->orWhereHas(
                    'document',
                    fn (Builder $document): Builder => $document->where('extracted_text', 'like', '%'.$search.'%'),
                ),
            ])
            ->searchPlaceholder('Search names and PDF contents')
            ->contentGrid($isGrid ? ['md' => 3, 'xl' => 5, '2xl' => 6] : null)
            ->paginationPageOptions($isGrid ? [24, 48, 96] : [25, 50, 100])
            ->defaultPaginationPageOption($isGrid ? 24 : 25)
            ->defaultSort('created_at', 'desc')
            ->filters(self::filters(), layout: FiltersLayout::AboveContentCollapsible)
            ->deferFilters(false)
            ->emptyStateHeading('Nothing here yet')
            ->emptyStateDescription('Upload images, PDFs and documents to reuse them across the site.');
    }

    /** @return array<int, ViewColumn> */
    private static function gridColumns(): array
    {
        // Layout\View::make() takes the view itself — unlike Columns\ViewColumn,
        // which takes an attribute name and is given its view separately.
        return [
            ViewColumn::make('filament.tables.columns.media-card'),
        ];
    }

    /** @return array<int, TextColumn> */
    private static function listColumns(): array
    {
        return [
            TextColumn::make('name')
                ->searchable()
                ->sortable()
                ->description(fn (Media $record): string => $record->file_name)
                ->wrap(),

            TextColumn::make('kind')
                ->label('Type')
                ->badge()
                ->state(fn (Media $record): string => $record->kind()->label()),

            TextColumn::make('size')
                ->label('Size')
                ->sortable()
                ->state(fn (Media $record): string => $record->readableSize())
                ->alignEnd(),

            TextColumn::make('document.page_count')
                ->label('Pages')
                ->placeholder('—')
                ->alignEnd()
                ->toggleable(),

            TextColumn::make('model.name')
                ->label('Folder')
                ->placeholder('—')
                // Only meaningful for library rows; an attached file's "folder"
                // is the record it belongs to, which the badge below says better.
                ->state(fn (Media $record): ?string => $record->isLibraryItem()
                    ? $record->model?->getAttribute('name')
                    : class_basename($record->model_type))
                ->badge()
                ->color(fn (Media $record): string => $record->isLibraryItem() ? 'gray' : 'warning'),

            TextColumn::make('usages_count')
                ->label('Used')
                ->counts('usages')
                ->badge()
                ->color(fn (int $state): string => $state > 0 ? 'success' : 'gray')
                ->formatStateUsing(fn (int $state): string => $state === 0 ? 'Unused' : (string) $state)
                ->toggleable(),

            TextColumn::make('created_at')
                ->label('Uploaded')
                ->dateTime('j M Y')
                ->sortable()
                ->toggleable(),
        ];
    }

    /** @return array<int, Filter|SelectFilter|TernaryFilter> */
    private static function filters(): array
    {
        return [
            SelectFilter::make('kind')
                ->label('Type')
                ->options(MediaKind::options())
                // No `kind` column: it is derived from mime and extension, so
                // the filter has to reproduce that in SQL. Kept here rather than
                // denormalised into a column, which would need backfilling and
                // could drift from the enum.
                ->query(function (Builder $query, array $data): Builder {
                    $kind = $data['value'] ?? null;

                    return match ($kind) {
                        MediaKind::Image->value => $query->where(fn (Builder $q): Builder => $q
                            ->where('mime_type', 'like', 'image/%')
                            ->orWhere('file_name', 'like', '%.svg')),
                        MediaKind::Pdf->value => $query->where(fn (Builder $q): Builder => $q
                            ->where('mime_type', 'application/pdf')
                            ->orWhere('file_name', 'like', '%.pdf')),
                        MediaKind::Document->value => $query->where(function (Builder $q): void {
                            foreach (MediaKind::documentExtensions() as $extension) {
                                $q->orWhere('file_name', 'like', "%.{$extension}");
                            }
                        }),
                        MediaKind::Other->value => $query
                            ->where('mime_type', 'not like', 'image/%')
                            ->where('mime_type', '!=', 'application/pdf'),
                        default => $query,
                    };
                }),

            SelectFilter::make('folder')
                ->label('Folder')
                ->options(fn (): array => MediaFolder::query()
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all())
                ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                    ? $query->where('model_type', MediaFolder::class)->where('model_id', $data['value'])
                    : $query),

            SelectFilter::make('tag')
                ->label('Tag')
                ->options(fn (): array => Tag::query()
                    ->where('type', Tag::TYPE_MEDIA)
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all())
                ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                    ? $query->whereHas('tags', fn (Builder $q): Builder => $q->whereKey($data['value']))
                    : $query),

            SelectFilter::make('scope')
                ->label('Scope')
                ->options([
                    'library' => 'Library',
                    'attached' => 'Attached to records',
                ])
                ->default('library')
                // Attached files are shown so storage totals and "which speaker
                // has no portrait" still work, but they are not library items
                // and cannot be moved or reused from here.
                ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                    'library' => $query->where('model_type', MediaFolder::class),
                    'attached' => $query->where('model_type', '!=', MediaFolder::class),
                    default => $query,
                }),

            TernaryFilter::make('missing_alt')
                ->label('Alt text')
                ->placeholder('Any')
                ->trueLabel('Missing alt text')
                ->falseLabel('Has alt text')
                // Only images can fail WCAG on alt text; a PDF is a link.
                ->queries(
                    true: fn (Builder $query): Builder => $query
                        ->where('mime_type', 'like', 'image/%')
                        ->where(fn (Builder $q): Builder => $q
                            ->whereNull('custom_properties->alt')
                            ->orWhere('custom_properties->alt', '')),
                    false: fn (Builder $query): Builder => $query
                        ->whereNotNull('custom_properties->alt')
                        ->where('custom_properties->alt', '!=', ''),
                    blank: fn (Builder $query): Builder => $query,
                ),

            TernaryFilter::make('unused')
                ->label('Usage')
                ->placeholder('Any')
                ->trueLabel('Not used anywhere')
                ->falseLabel('Used somewhere')
                ->queries(
                    true: fn (Builder $query): Builder => $query->doesntHave('usages'),
                    false: fn (Builder $query): Builder => $query->has('usages'),
                    blank: fn (Builder $query): Builder => $query,
                ),

            Filter::make('uploaded')
                ->schema([
                    Select::make('period')
                        ->label('Uploaded')
                        ->placeholder('Any time')
                        ->options([
                            '7' => 'Last 7 days',
                            '30' => 'Last 30 days',
                            '90' => 'Last 90 days',
                            '365' => 'Last year',
                        ]),
                ])
                ->query(fn (Builder $query, array $data): Builder => filled($data['period'] ?? null)
                    ? $query->where('created_at', '>=', now()->subDays((int) $data['period']))
                    : $query),
        ];
    }

    /**
     * The page owns the choice; anything else embedding this table (the picker
     * at P6, for one) gets the grid.
     */
    private static function isGrid(Table $table): bool
    {
        $livewire = $table->getLivewire();

        return ! property_exists($livewire, 'viewMode') || $livewire->viewMode === 'grid';
    }
}
