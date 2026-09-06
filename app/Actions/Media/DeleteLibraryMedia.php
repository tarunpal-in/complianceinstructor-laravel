<?php

declare(strict_types=1);

namespace App\Actions\Media;

use App\Models\Media;

/**
 * Deletes a library file, or refuses and says why.
 *
 * The classic media-manager disaster is deleting the homepage hero because
 * nothing said it was in use. Refusing with "used in 3 places, here they are"
 * is better than a cascade that leaves three pages with broken images — and far
 * better than a soft delete, which would leave the file serving happily from
 * disk while the client believes it is gone.
 */
class DeleteLibraryMedia
{
    /**
     * @return array<int, string> Reasons it was refused; empty means deleted.
     */
    public function __invoke(Media $media, bool $force = false): array
    {
        if (! $force) {
            $blockers = $this->blockers($media);

            if ($blockers !== []) {
                return $blockers;
            }
        }

        // Spatie's observer removes the original, the conversions and the
        // responsive images; the media_documents and media_usages rows go with
        // the foreign key.
        $media->delete();

        return [];
    }

    /**
     * @return array<int, string>
     */
    public function blockers(Media $media): array
    {
        return $media->usages()
            ->with('usable')
            ->get()
            ->map(fn ($usage): string => $usage->describe())
            ->all();
    }
}
