<?php

declare(strict_types=1);

namespace App\Filament\Forms\Components;

use App\Enums\MediaKind;
use App\Models\Media;
use App\Models\MediaFolder;
use Filament\Forms\Components\Select;
use Illuminate\Database\Eloquent\Builder;

/**
 * Picks a file from the library, storing its id.
 *
 * A reference rather than a copy — docs/media-manager.md §3.1. Replacing the
 * file in the library then updates everywhere it is picked, which is the whole
 * point of having a library.
 *
 * The page builder's blocks use this at P6. Brand settings use it now, which is
 * also what makes the delete guard real: the site logo is exactly the file a
 * client is most likely to tidy away by accident.
 */
class MediaPicker
{
    public static function make(string $name, ?MediaKind $kind = MediaKind::Image): Select
    {
        return Select::make($name)
            ->searchable()
            ->preload()
            ->placeholder('Nothing selected')
            ->options(fn (): array => self::query($kind)->limit(50)->pluck('name', 'id')->all())
            // Searched rather than fully preloaded past fifty: a mature library
            // is thousands of files and a select holding all of them is a slow
            // page and an unusable dropdown.
            ->getSearchResultsUsing(fn (string $search): array => self::query($kind)
                ->where(fn (Builder $query): Builder => $query
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('file_name', 'like', "%{$search}%"))
                ->limit(50)
                ->pluck('name', 'id')
                ->all())
            ->getOptionLabelUsing(fn (mixed $value): ?string => Media::query()->find($value)?->name);
    }

    /**
     * Library files only.
     *
     * A webinar's banner belongs to that webinar and is cropped for it; offering
     * it here would let the site logo be set to something a content editor later
     * changes on an unrelated record.
     *
     * @return Builder<Media>
     */
    private static function query(?MediaKind $kind): Builder
    {
        return Media::query()
            ->where('model_type', MediaFolder::class)
            ->where('collection_name', Media::LIBRARY_COLLECTION)
            ->when($kind === MediaKind::Image, fn (Builder $query): Builder => $query
                ->where(fn (Builder $inner): Builder => $inner
                    ->where('mime_type', 'like', 'image/%')
                    ->orWhere('file_name', 'like', '%.svg')))
            ->orderBy('name');
    }
}
