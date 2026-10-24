<?php

declare(strict_types=1);

use App\Models\Media;
use App\Models\MediaFolder;
use App\Models\Page;
use Database\Seeders\HomePageSeeder;
use Illuminate\Http\UploadedFile;

/*
 * The homepage hero image(s) are now picked from the Media library — the block
 * stores repeater rows of {media_id} rather than uploaded paths. This covers a
 * picked library image reaching the hero.
 */

it('shows a picked library image in the homepage hero', function (): void {
    $this->seed(HomePageSeeder::class);

    $media = MediaFolder::uncategorised()
        ->addMedia(UploadedFile::fake()->image('hero.png', 940, 628))
        ->toMediaCollection(Media::LIBRARY_COLLECTION);

    $hero = Page::where('slug', 'home')->sole()
        ->sections()->where('type', 'home_hero')->sole();

    $data = $hero->data;
    $data['images'] = [['media_id' => $media->getKey()]];
    $hero->update(['data' => $data]);

    $this->get('/')
        ->assertSuccessful()
        ->assertSee($media->detailImageUrl(), escape: false);
});
