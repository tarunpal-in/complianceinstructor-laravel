<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Media;
use App\Models\MediaDocument;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * Pulls the text and page count out of a PDF so the library can be searched by
 * contents rather than by file name.
 *
 * This is what decides whether a document library gets used. A compliance
 * catalogue accumulates hundreds of near-identically named PDFs; matching on
 * file name alone means the client cannot find anything they did not upload this
 * week.
 *
 * Queued, per §4 rule 7 — parsing a 200-page manual must not block the request
 * that uploaded it.
 */
class ExtractDocumentText implements ShouldQueue
{
    use Queueable;

    /**
     * Parsing is memory-hungry and pathological PDFs exist. Anything larger is
     * recorded as skipped rather than allowed to exhaust a queue worker.
     */
    private const MAX_PARSE_BYTES = 50 * 1024 * 1024;

    /**
     * A 400-page manual would otherwise put megabytes of text in a row that is
     * only ever LIKE-scanned. The first million characters find the document.
     */
    private const MAX_TEXT_LENGTH = 1_000_000;

    public int $tries = 2;

    public int $timeout = 300;

    public function __construct(public int $mediaId) {}

    public function handle(): void
    {
        $media = Media::find($this->mediaId);

        if ($media === null || ! $media->kind()->isSearchableDocument()) {
            return;
        }

        if ($media->size > self::MAX_PARSE_BYTES) {
            $this->record($media, null, null, 'File too large to index.');

            return;
        }

        $local = $this->copyToTemp($media);

        if ($local === null) {
            $this->record($media, null, null, 'File could not be read from storage.');

            return;
        }

        try {
            $document = (new Parser)->parseFile($local);

            $this->record(
                $media,
                count($document->getPages()),
                $this->normalise($document->getText()),
                null,
            );
        } catch (Throwable $e) {
            // A malformed or encrypted PDF is a normal thing to be handed, not
            // an incident. Record it so the panel can say why search will not
            // find this file, and do not retry into a failure.
            Log::info('PDF text extraction failed.', [
                'media_id' => $media->id,
                'message' => $e->getMessage(),
            ]);

            $this->record($media, null, null, Str::limit($e->getMessage(), 180));
        } finally {
            @unlink($local);
        }
    }

    /**
     * The file may be on S3 (§11), so it is streamed to a temp path rather than
     * assumed to have a local one.
     */
    private function copyToTemp(Media $media): ?string
    {
        try {
            $source = Storage::disk($media->disk)->readStream($media->getPathRelativeToRoot());

            if ($source === null) {
                return null;
            }

            $path = tempnam(sys_get_temp_dir(), 'media-extract-');

            if ($path === false) {
                return null;
            }

            $target = fopen($path, 'wb');

            if ($target === false) {
                return null;
            }

            stream_copy_to_stream($source, $target);
            fclose($target);
            fclose($source);

            return $path;
        } catch (Throwable $e) {
            Log::warning('Could not stage a media file for extraction.', [
                'media_id' => $media->id,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * PDF text arrives with the layout baked in as runs of whitespace. Collapsing
     * it keeps the column small and stops a search for "annual report" missing a
     * match split across a line break.
     */
    private function normalise(string $text): string
    {
        $text = (string) preg_replace('/\s+/u', ' ', $text);

        return Str::limit(trim($text), self::MAX_TEXT_LENGTH, '');
    }

    private function record(Media $media, ?int $pages, ?string $text, ?string $error): void
    {
        MediaDocument::updateOrCreate(
            ['media_id' => $media->id],
            [
                'page_count' => $pages,
                'extracted_text' => $text,
                'extracted_at' => now(),
                'extract_error' => $error,
            ],
        );
    }
}
