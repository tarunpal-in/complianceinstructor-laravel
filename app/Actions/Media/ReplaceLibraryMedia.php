<?php

declare(strict_types=1);

namespace App\Actions\Media;

use App\Jobs\ExtractDocumentText;
use App\Models\Media;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\Conversions\FileManipulator;
use Spatie\MediaLibrary\MediaCollections\Filesystem;

/**
 * Swaps the bytes behind an existing media row, keeping its id.
 *
 * This is the reason to have a library at all rather than scattered uploads: a
 * revised handout or a new logo updates every page using it at once, because
 * nothing referencing it has to change.
 *
 * Deleting and re-uploading would produce a new id and a new URL, leaving every
 * reference pointing at a file that no longer exists — which is the problem the
 * library exists to solve, reintroduced.
 */
class ReplaceLibraryMedia
{
    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly FileManipulator $fileManipulator,
    ) {}

    public function __invoke(Media $media, UploadedFile $file): Media
    {
        $hash = (string) hash_file('sha256', $file->getRealPath());

        // Keeping the base name means the public URL is unchanged whenever the
        // extension is unchanged, which is the common case — a revised PDF or a
        // re-exported PNG. A format change necessarily moves the URL.
        $fileName = Str::slug(pathinfo((string) $media->file_name, PATHINFO_FILENAME))
            .'.'.Str::lower($file->getClientOriginalExtension());

        // Old original, conversions and responsive images all go first;
        // otherwise a smaller replacement leaves the previous thumbnails behind
        // and the grid keeps showing the file that was replaced.
        $this->filesystem->removeAllFiles($media);

        $media->forceFill([
            'file_name' => $fileName,
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'hash' => $hash,
            'generated_conversions' => [],
            'responsive_images' => [],
        ])->save();

        $this->filesystem->add($file->getRealPath(), $media, $fileName);

        $this->fileManipulator->createDerivedFiles($media);

        $media->refresh();

        // The old extraction describes a file that no longer exists. Clearing it
        // rather than leaving it stale means a search cannot match a document on
        // text that has since been replaced.
        $media->document?->delete();

        if ($media->kind()->isSearchableDocument()) {
            ExtractDocumentText::dispatch($media->id);
        }

        return $media;
    }
}
