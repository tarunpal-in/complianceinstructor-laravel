<?php

declare(strict_types=1);

use App\Livewire\LibraryQuickUpload;
use App\Models\Media;
use App\Models\MediaFolder;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

/*
 * The MediaPicker modal can upload a new file straight into the library. The
 * upload goes through StoreLibraryMedia (so hashing and naming are consistent),
 * then announces the stored file on a channel named for the picker — which is
 * how, with two pickers on a screen, only the one that uploaded reacts.
 */

function libraryImageCount(): int
{
    return Media::query()
        ->where('model_type', MediaFolder::class)
        ->where('collection_name', Media::LIBRARY_COLLECTION)
        ->count();
}

it('stores an uploaded image in the library and announces it to its picker', function (): void {
    $this->actingAs(User::factory()->create());

    $before = libraryImageCount();

    Livewire::test(LibraryQuickUpload::class, ['channel' => 'library-uploaded-data-logo-media-id'])
        ->set('file', UploadedFile::fake()->image('new-badge.png', 200, 200))
        ->assertHasNoErrors()
        ->assertDispatched('library-uploaded-data-logo-media-id');

    $media = Media::query()
        ->where('collection_name', Media::LIBRARY_COLLECTION)
        ->latest('id')
        ->first();

    expect(libraryImageCount())->toBe($before + 1)
        ->and($media)->not->toBeNull()
        // StoreLibraryMedia tidies the display name: "new-badge" → "New Badge".
        ->and($media->name)->toBe('New Badge');
});

it('rejects a non-image upload and announces nothing', function (): void {
    $this->actingAs(User::factory()->create());

    $before = libraryImageCount();

    Livewire::test(LibraryQuickUpload::class, ['channel' => 'library-uploaded-test'])
        ->set('file', UploadedFile::fake()->create('handbook.pdf', 20, 'application/pdf'))
        ->assertHasErrors(['file'])
        ->assertNotDispatched('library-uploaded-test');

    expect(libraryImageCount())->toBe($before);
});
