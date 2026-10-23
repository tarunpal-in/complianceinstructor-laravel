<?php

declare(strict_types=1);

use App\Models\Page;
use App\Models\PageSection;
use Illuminate\Support\Facades\Storage;

/*
 * The "Text with image" page block (§2 / §5.6). A new block type is one Blade
 * partial and one case in PageBlocks — no model, no table — and renders prose
 * beside a public-disk image, left or right, falling back to full-width text
 * when no image is set.
 */

it('renders prose beside its image, on the chosen side, with alt text', function (): void {
    Storage::fake('public');

    $page = Page::factory()->published()->create(['slug' => 'our-mission']);
    PageSection::factory()->for($page)->type('media_text', [
        'heading' => 'Our mission',
        'body' => '<p>Staying current, made straightforward.</p>',
        'image' => 'pages/mission.jpg',
        'image_position' => 'left',
        'image_alt' => 'A trainer leading a session',
    ])->create();

    $this->get('/our-mission')
        ->assertSuccessful()
        ->assertSee('Our mission')
        ->assertSee('Staying current, made straightforward', escape: false)
        ->assertSee('pb-media-text', escape: false)
        ->assertSee('image-left', escape: false)
        ->assertSee('A trainer leading a session', escape: false) // the alt text
        ->assertSee(Storage::disk('public')->url('pages/mission.jpg'), escape: false);
});

it('falls back to full-width text when no image is set', function (): void {
    $page = Page::factory()->published()->create(['slug' => 'text-only']);
    PageSection::factory()->for($page)->type('media_text', [
        'heading' => 'Who we serve',
        'body' => '<p>No picture here.</p>',
    ])->create();

    $this->get('/text-only')
        ->assertSuccessful()
        ->assertSee('No picture here', escape: false)
        ->assertDontSee('pb-media-text-image', escape: false)
        ->assertDontSee('has-image', escape: false);
});
