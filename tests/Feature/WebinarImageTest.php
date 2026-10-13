<?php

declare(strict_types=1);

use App\Actions\Media\DeleteLibraryMedia;
use App\Models\Media;
use App\Models\Webinar;
use Illuminate\Support\Facades\Storage;

/*
 * Webinar card and background images are now references into the media library
 * (picked through the media manager), not per-webinar uploads. These cover the
 * three things that change: resolving the URL, rendering it, and the usage
 * tracking the delete guard depends on.
 */

beforeEach(fn () => Storage::fake('public'));

it('resolves the picked card and background images', function (): void {
    $card = Media::factory()->create();
    $banner = Media::factory()->create();

    $webinar = Webinar::factory()->create([
        'card_media_id' => $card->id,
        'banner_media_id' => $banner->id,
    ]);

    expect($webinar->cardImageUrl())->toBe($card->detailImageUrl())
        ->and($webinar->bannerImageUrl())->toBe($banner->detailImageUrl())
        ->and($webinar->cardImageUrl())->not->toBeNull();
});

it('has no image when nothing is picked', function (): void {
    $webinar = Webinar::factory()->create();

    expect($webinar->cardImageUrl())->toBeNull()
        ->and($webinar->bannerImageUrl())->toBeNull();
});

it('uses the background then the card as the social image', function (): void {
    $card = Media::factory()->create();
    $banner = Media::factory()->create();

    $both = Webinar::factory()->create(['card_media_id' => $card->id, 'banner_media_id' => $banner->id]);
    $cardOnly = Webinar::factory()->create(['card_media_id' => $card->id]);

    expect($both->defaultSeoOgImageUrl())->toBe($banner->detailImageUrl())
        ->and($cardOnly->defaultSeoOgImageUrl())->toBe($card->detailImageUrl());
});

it('renders the picked background on the detail page', function (): void {
    $banner = Media::factory()->create();
    $webinar = Webinar::factory()->scheduled()->create(['banner_media_id' => $banner->id]);

    $this->get(route('webinars.show', $webinar))
        ->assertSuccessful()
        ->assertSee($banner->detailImageUrl(), escape: false);
});

it('records a usage so the library delete guard protects the file', function (): void {
    $media = Media::factory()->create();
    $webinar = Webinar::factory()->create(['banner_media_id' => $media->id]);

    // The saved event wrote the usage row…
    expect($media->usages()->count())->toBe(1);

    // …so the library cannot delete a file a webinar still points at.
    $blockers = app(DeleteLibraryMedia::class)->blockers($media);

    expect($blockers)->toHaveCount(1)
        ->and($blockers[0])->toContain($webinar->title);
});

it('releases the usage when the webinar changes its pick', function (): void {
    $first = Media::factory()->create();
    $second = Media::factory()->create();

    $webinar = Webinar::factory()->create(['banner_media_id' => $first->id]);
    $webinar->update(['banner_media_id' => $second->id]);

    expect($first->usages()->count())->toBe(0)
        ->and($second->usages()->count())->toBe(1);
});

it('clears usages when the webinar is deleted', function (): void {
    $media = Media::factory()->create();
    $webinar = Webinar::factory()->create(['card_media_id' => $media->id]);

    $webinar->forceDelete();

    expect($media->usages()->count())->toBe(0);
});
