<?php

declare(strict_types=1);

namespace App\Filament\Forms\Components;

use App\Enums\MediaKind;
use App\Models\Media;
use App\Models\MediaFolder;
use Filament\Forms\Components\Field;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Picks a file from the library through a visual modal browser, storing its id.
 *
 * A reference rather than a copy — docs/media-manager.md §3.1. Replacing the file
 * in the library then updates everywhere it is picked, which is the whole point
 * of having a library.
 *
 * The field shows a thumbnail of the current choice and a "Choose" button that
 * opens a grid of the library's images to pick from — a real picker, not a list
 * of file names. Used by the accreditation logo, the webinar card/banner, the
 * brand logo in Settings and the page-builder image blocks.
 */
class MediaPicker extends Field
{
    protected string $view = 'filament.forms.components.media-picker';

    protected MediaKind $kind = MediaKind::Image;

    public static function make(?string $name = null, ?MediaKind $kind = MediaKind::Image): static
    {
        $static = parent::make($name);
        $static->kind = $kind ?? MediaKind::Image;

        return $static;
    }

    /**
     * The library files to choose from — exposed to the view as $getLibraryItems().
     *
     * @return array<int, array{id: int, name: string, thumb: string|null}>
     */
    public function getLibraryItems(): array
    {
        return self::rows(self::query($this->kind)->limit(300)->get());
    }

    /**
     * The currently selected file, so the preview can render before any picking.
     *
     * @return array{id: int, name: string, thumb: string|null}|null
     */
    public function getSelectedItem(): ?array
    {
        $id = $this->getState();

        if (blank($id)) {
            return null;
        }

        $media = Media::query()->find($id);

        return $media !== null ? self::row($media) : null;
    }

    /**
     * @param  Collection<int, Media>  $media
     * @return array<int, array{id: int, name: string, thumb: string|null}>
     */
    private static function rows(Collection $media): array
    {
        return $media->map(fn (Media $item): array => self::row($item))->values()->all();
    }

    /**
     * One library file as the view's shape — shared with the quick-upload control
     * so a just-uploaded file arrives in exactly the form the grid expects.
     *
     * @return array{id: int, name: string, thumb: string|null}
     */
    public static function row(Media $media): array
    {
        return ['id' => $media->getKey(), 'name' => $media->name, 'thumb' => self::previewUrl($media)];
    }

    /** The generated thumb when ready, otherwise the original so a visual always shows. */
    private static function previewUrl(Media $media): ?string
    {
        if ($media->kind() !== MediaKind::Image) {
            return $media->thumbnailUrl();
        }

        return $media->thumbnailUrl() ?? $media->getUrl();
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
    private static function query(MediaKind $kind): Builder
    {
        return Media::query()
            ->where('model_type', MediaFolder::class)
            ->where('collection_name', Media::LIBRARY_COLLECTION)
            ->when($kind === MediaKind::Image, fn (Builder $query): Builder => $query
                ->where(fn (Builder $inner): Builder => $inner
                    ->where('mime_type', 'like', 'image/%')
                    ->orWhere('file_name', 'like', '%.svg')))
            // Newest upload first, so a file just added through the picker's own
            // upload control sits at the top where the eye lands. The id breaks
            // ties for a bulk import that shares a second.
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }
}
