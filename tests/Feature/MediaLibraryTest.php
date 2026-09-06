<?php

declare(strict_types=1);

use App\Actions\Media\ReplaceLibraryMedia;
use App\Actions\Media\StoreLibraryMedia;
use App\Enums\MediaKind;
use App\Jobs\ExtractDocumentText;
use App\Models\Media;
use App\Models\MediaFolder;
use App\Models\Webinar;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('public');
});

// Classification --------------------------------------------------------------

/**
 * §14 #12 bans comparing loose strings for anything that decides behaviour, and
 * this enum decides whether a file gets a thumbnail, gets indexed, or gets an
 * icon. Browsers send application/octet-stream often enough that the extension
 * fallback is doing real work rather than being defensive padding.
 */
it('classifies a file by mime, falling back to the extension', function (string $mime, string $extension, MediaKind $expected): void {
    expect(MediaKind::forMime($mime, $extension))->toBe($expected);
})->with([
    ['image/png', 'png', MediaKind::Image],
    ['image/svg+xml', 'svg', MediaKind::Image],
    ['application/pdf', 'pdf', MediaKind::Pdf],
    ['application/octet-stream', 'pdf', MediaKind::Pdf],
    ['application/octet-stream', 'jpg', MediaKind::Image],
    ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'docx', MediaKind::Document],
    ['application/octet-stream', 'xlsx', MediaKind::Document],
    ['application/x-msdownload', 'exe', MediaKind::Other],
]);

it('only expects a thumbnail for images and PDFs', function (): void {
    expect(MediaKind::Image->isPreviewable())->toBeTrue()
        ->and(MediaKind::Pdf->isPreviewable())->toBeTrue()
        ->and(MediaKind::Document->isPreviewable())->toBeFalse()
        ->and(MediaKind::Other->isPreviewable())->toBeFalse();
});

// Upload ----------------------------------------------------------------------

it('stores an upload in a folder as a library item', function (): void {
    $folder = MediaFolder::factory()->create(['name' => 'Brand']);

    $result = app(StoreLibraryMedia::class)(
        UploadedFile::fake()->image('Company Logo.png'),
        $folder,
    );

    expect($result->wasDuplicate)->toBeFalse()
        ->and($result->media->isLibraryItem())->toBeTrue()
        ->and($result->media->collection_name)->toBe(Media::LIBRARY_COLLECTION)
        ->and($result->media->model_id)->toBe($folder->id)
        ->and($result->media->name)->toBe('Company Logo')
        ->and($result->media->file_name)->toBe('company-logo.png')
        ->and($result->media->hash)->toHaveLength(64);

    Storage::disk('public')->assertExists($result->media->id.'/company-logo.png');
});

it('falls back to the uncategorised folder when none is given', function (): void {
    $result = app(StoreLibraryMedia::class)(UploadedFile::fake()->image('loose.png'));

    expect($result->media->model_id)->toBe(MediaFolder::uncategorised()->id);
});

/**
 * The predecessor reached 926 unindexed files in public/uploads. Returning the
 * existing row rather than storing the same bytes twice is what stops that
 * happening again — and it must report the duplicate, because a bulk upload
 * that silently reuses six files looks like it lost them.
 */
it('returns the existing file when the same bytes are uploaded again', function (): void {
    $folder = MediaFolder::factory()->create();

    $first = app(StoreLibraryMedia::class)(
        UploadedFile::fake()->createWithContent('handout.pdf', 'identical bytes'),
        $folder,
    );

    $second = app(StoreLibraryMedia::class)(
        UploadedFile::fake()->createWithContent('handout-copy.pdf', 'identical bytes'),
        $folder,
    );

    expect($second->wasDuplicate)->toBeTrue()
        ->and($second->media->id)->toBe($first->media->id)
        ->and(Media::query()->count())->toBe(1);
});

/**
 * A webinar banner that happens to match a library image is a different thing
 * with a different owner and its own crop. Collapsing the two would delete one
 * record's image when the other was tidied up.
 */
it('does not deduplicate a library file against one attached to a record', function (): void {
    $webinar = Webinar::factory()->create();

    Media::factory()->pdf()->attachedTo($webinar, 'banner')->create(['hash' => hash('sha256', 'shared')]);

    $result = app(StoreLibraryMedia::class)(
        UploadedFile::fake()->createWithContent('banner.pdf', 'shared'),
        MediaFolder::factory()->create(),
    );

    expect($result->wasDuplicate)->toBeFalse()
        ->and(Media::query()->count())->toBe(2);
});

it('queues text extraction for a PDF but not for an image', function (): void {
    Queue::fake();
    $folder = MediaFolder::factory()->create();

    app(StoreLibraryMedia::class)(UploadedFile::fake()->createWithContent('policy.pdf', '%PDF-1.4'), $folder);
    Queue::assertPushed(ExtractDocumentText::class, 1);

    app(StoreLibraryMedia::class)(UploadedFile::fake()->image('photo.png'), $folder);
    Queue::assertPushed(ExtractDocumentText::class, 1);
});

// Replace ---------------------------------------------------------------------

/**
 * The whole reason to have a library rather than scattered uploads: a revised
 * handout updates every page using it, because the id and the URL do not move.
 */
it('replaces the bytes of a file without changing its id or URL', function (): void {
    $stored = app(StoreLibraryMedia::class)(
        UploadedFile::fake()->createWithContent('terms.pdf', 'version one'),
        MediaFolder::factory()->create(),
    );

    $originalId = $stored->media->id;
    $originalUrl = $stored->media->getUrl();

    $replaced = app(ReplaceLibraryMedia::class)(
        $stored->media,
        UploadedFile::fake()->createWithContent('terms-v2.pdf', 'version two, which is longer'),
    );

    expect($replaced->id)->toBe($originalId)
        ->and($replaced->getUrl())->toBe($originalUrl)
        ->and($replaced->size)->toBe(strlen('version two, which is longer'))
        ->and($replaced->hash)->toBe(hash('sha256', 'version two, which is longer'));

    expect(Storage::disk('public')->get($originalId.'/terms.pdf'))->toBe('version two, which is longer');
});

it('drops the stale extraction when a document is replaced', function (): void {
    $stored = app(StoreLibraryMedia::class)(
        UploadedFile::fake()->createWithContent('guide.pdf', 'version one'),
        MediaFolder::factory()->create(),
    );

    // updateOrCreate, not create: the queue runs synchronously under test, so
    // ExtractDocumentText has already written a row for this upload.
    $stored->media->document()->updateOrCreate([], [
        'extracted_text' => 'text belonging to the file that was replaced',
        'extracted_at' => now(),
    ]);

    app(ReplaceLibraryMedia::class)(
        $stored->media,
        UploadedFile::fake()->createWithContent('guide.pdf', 'version two'),
    );

    expect($stored->media->fresh()?->document?->extracted_text)
        ->not->toBe('text belonging to the file that was replaced');
});

// Alt text --------------------------------------------------------------------

/**
 * §7 P9 audits for WCAG AA, where an image without alt text is a failure. A PDF
 * is a link rather than an image, so it must not appear on that worklist.
 */
it('flags images without alt text and ignores documents', function (): void {
    $image = Media::factory()->create();
    $described = Media::factory()->withAlt('A speaker at a podium')->create();
    $pdf = Media::factory()->pdf()->create();

    expect($image->needsAltText())->toBeTrue()
        ->and($described->needsAltText())->toBeFalse()
        ->and($pdf->needsAltText())->toBeFalse();
});

it('reads and writes alt text through custom properties', function (): void {
    $media = Media::factory()->create();

    $media->alt = 'A lecture hall';
    $media->save();

    expect($media->fresh()?->alt)->toBe('A lecture hall')
        ->and($media->fresh()?->getCustomProperty('alt'))->toBe('A lecture hall');
});
