<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Filament\Resources\Industries\Pages\EditIndustry;
use App\Filament\Resources\Posts\Pages\EditPost;
use App\Filament\Resources\Speakers\Pages\EditSpeaker;
use App\Models\Industry;
use App\Models\Media;
use App\Models\MediaFolder;
use App\Models\Post;
use App\Models\Speaker;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

/*
 * Speaker portraits, industry cards and blog covers are now picked from the Media
 * library (MediaPicker → *_media_id), the same reference model webinar images and
 * accreditation logos use. These cover the accessor resolving a picked file and
 * the form linking one.
 */

function libraryImage(string $name = 'pick.png'): Media
{
    return MediaFolder::uncategorised()
        ->addMedia(UploadedFile::fake()->image($name, 300, 200))
        ->toMediaCollection(Media::LIBRARY_COLLECTION);
}

it('resolves a picked library portrait for a speaker', function (): void {
    $media = libraryImage();
    $speaker = Speaker::factory()->create(['photo_media_id' => $media->getKey()]);

    expect($speaker->portraitUrl())->toBe($media->detailImageUrl())->not->toBeNull();
});

it('resolves a picked library card for an industry', function (): void {
    $media = libraryImage();
    $industry = Industry::factory()->create(['card_media_id' => $media->getKey()]);

    expect($industry->cardImageUrl())->toBe($media->detailImageUrl())->not->toBeNull();
});

it('resolves a picked library cover for a post', function (): void {
    $media = libraryImage();
    $post = Post::factory()->create(['cover_media_id' => $media->getKey()]);

    expect($post->coverUrl())->toBe($media->detailImageUrl())->not->toBeNull();
});

it('returns null when nothing is picked and no legacy upload exists', function (): void {
    expect(Speaker::factory()->create()->portraitUrl())->toBeNull()
        ->and(Industry::factory()->create()->cardImageUrl())->toBeNull()
        ->and(Post::factory()->create()->coverUrl())->toBeNull();
});

it('links a library portrait through the speaker form', function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->actingAs(userWithRole(UserRole::SuperAdmin));

    $media = libraryImage();
    $speaker = Speaker::factory()->create();

    Livewire::test(EditSpeaker::class, ['record' => $speaker->getRouteKey()])
        ->set('data.photo_media_id', $media->getKey())
        ->call('save')
        ->assertHasNoFormErrors();

    expect($speaker->fresh()->photo_media_id)->toBe($media->getKey());
});

it('links a library card through the industry form', function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->actingAs(userWithRole(UserRole::SuperAdmin));

    $media = libraryImage();
    $industry = Industry::factory()->create();

    Livewire::test(EditIndustry::class, ['record' => $industry->getRouteKey()])
        ->set('data.card_media_id', $media->getKey())
        ->call('save')
        ->assertHasNoFormErrors();

    expect($industry->fresh()->card_media_id)->toBe($media->getKey());
});

it('links a library cover through the post form', function (): void {
    $this->seed(RolePermissionSeeder::class);
    $admin = userWithRole(UserRole::SuperAdmin);
    $this->actingAs($admin);

    $media = libraryImage();
    // Authored by a staff user, so the form's author Select stays valid on save.
    $post = Post::factory()->create(['user_id' => $admin->getKey()]);

    Livewire::test(EditPost::class, ['record' => $post->getRouteKey()])
        ->set('data.cover_media_id', $media->getKey())
        ->call('save')
        ->assertHasNoFormErrors();

    expect($post->fresh()->cover_media_id)->toBe($media->getKey());
});
