<?php

declare(strict_types=1);

namespace App\Actions\Media;

use App\Jobs\ExtractDocumentText;
use App\Models\Media;
use App\Models\MediaFolder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Puts one uploaded file into the library.
 *
 * §4 rule 2 — "Controllers route; Actions do". Every path that adds a library
 * file goes through here, so hashing, de-duplication and text extraction cannot
 * be forgotten by a caller.
 */
class StoreLibraryMedia
{
    public function __invoke(UploadedFile $file, ?MediaFolder $folder = null): StoredMedia
    {
        $folder ??= MediaFolder::uncategorised();

        // Hashed before the file is added, because adding it moves it. Streamed
        // rather than read, so a 60 MB deck does not become 60 MB of memory.
        $hash = (string) hash_file('sha256', $file->getRealPath());

        $existing = $this->existingByHash($hash);

        if ($existing !== null) {
            return new StoredMedia($existing, wasDuplicate: true);
        }

        $originalName = $file->getClientOriginalName();

        /** @var Media $media */
        $media = $folder
            ->addMedia($file)
            // The display name drops the extension and tidies the separators a
            // camera or a CMS export leaves behind; the stored file name keeps
            // its own sanitised form.
            ->usingName(Str::of(pathinfo($originalName, PATHINFO_FILENAME))
                ->replace(['-', '_'], ' ')
                ->squish()
                ->title()
                ->value())
            ->usingFileName(Str::slug(pathinfo($originalName, PATHINFO_FILENAME))
                .'.'.Str::lower($file->getClientOriginalExtension()))
            ->withCustomProperties([
                'alt' => '',
                'caption' => '',
                'uploaded_by' => auth()->id(),
            ])
            ->toMediaCollection(Media::LIBRARY_COLLECTION);

        $media->forceFill(['hash' => $hash])->save();

        if ($media->kind()->isSearchableDocument()) {
            ExtractDocumentText::dispatch($media->id);
        }

        return new StoredMedia($media, wasDuplicate: false);
    }

    /**
     * Only library files are deduplicated against.
     *
     * A webinar banner that happens to be byte-identical to a library image is a
     * different thing with a different owner and its own crop; collapsing the two
     * would delete one record's image when the other was tidied up.
     */
    private function existingByHash(string $hash): ?Media
    {
        return Media::query()
            ->where('hash', $hash)
            ->where('model_type', MediaFolder::class)
            ->where('collection_name', Media::LIBRARY_COLLECTION)
            ->first();
    }
}
