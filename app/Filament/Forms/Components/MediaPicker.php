<?php

declare(strict_types=1);

namespace App\Filament\Forms\Components;

use App\Enums\MediaKind;
use App\Models\Media;
use App\Models\MediaFolder;
use Filament\Forms\Components\Select;
use Illuminate\Database\Eloquent\Builder;

/**
 * Picks a file from the library, storing its id — with a thumbnail of each
 * option and of the current choice, so it reads as a visual picker rather than
 * a list of file names.
 *
 * A reference rather than a copy — docs/media-manager.md §3.1. Replacing the
 * file in the library then updates everywhere it is picked, which is the whole
 * point of having a library.
 *
 * The page builder's blocks use this at P6. Brand settings and the accreditation
 * logo use it now, which is also what makes the delete guard real: the site logo
 * is exactly the file a client is most likely to tidy away by accident.
 */
class MediaPicker
{
    public static function make(string $name, ?MediaKind $kind = MediaKind::Image): Select
    {
        return Select::make($name)
            ->searchable()
            ->preload()
            // Option labels carry an <img>, so HTML is allowed — every value in
            // it is escaped in self::label(), so an admin-authored file name
            // cannot inject markup.
            ->allowHtml()
            ->placeholder('Nothing selected')
            ->options(fn (): array => self::labels(self::query($kind)->limit(50)->get()))
            // Searched rather than fully preloaded past fifty: a mature library
            // is thousands of files and a select holding all of them is a slow
            // page and an unusable dropdown.
            ->getSearchResultsUsing(fn (string $search): array => self::labels(self::query($kind)
                ->where(fn (Builder $query): Builder => $query
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('file_name', 'like', "%{$search}%"))
                ->limit(50)
                ->get()))
            ->getOptionLabelUsing(fn (mixed $value): ?string => ($media = Media::query()->find($value)) !== null
                ? self::label($media)
                : null);
    }

    /**
     * Thumbnail-and-name labels keyed by media id.
     *
     * @param  \Illuminate\Support\Collection<int, Media>  $media
     * @return array<int, string>
     */
    private static function labels(\Illuminate\Support\Collection $media): array
    {
        return $media->mapWithKeys(fn (Media $item): array => [$item->getKey() => self::label($item)])->all();
    }

    /** One option's HTML: a small thumbnail (when the file is an image) plus its name. */
    private static function label(Media $media): string
    {
        $name = e($media->name);
        $url = self::previewUrl($media);

        if ($url === null) {
            return '<span>'.$name.'</span>';
        }

        return '<span style="display:inline-flex;align-items:center;gap:8px;">'
            .'<img src="'.e($url).'" alt="" loading="lazy" '
            .'style="height:28px;width:28px;border-radius:5px;object-fit:cover;background:#eef2f7;flex:none;">'
            .'<span>'.$name.'</span></span>';
    }

    /** The best small image URL: the generated thumb if present, else the original. */
    private static function previewUrl(Media $media): ?string
    {
        $isImage = str_starts_with((string) $media->mime_type, 'image/')
            || str_ends_with(strtolower((string) $media->file_name), '.svg');

        if (! $isImage) {
            return null;
        }

        return $media->hasGeneratedConversion('thumb') ? $media->getUrl('thumb') : $media->getUrl();
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
