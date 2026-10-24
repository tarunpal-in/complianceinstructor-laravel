<?php

declare(strict_types=1);

use App\Models\Accreditation;
use App\Models\Media;
use App\Models\MediaFolder;
use Database\Seeders\HomePageSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;

/*
 * The homepage renders the `home_accreditation` CMS block, which supplies the
 * partner list. Logos are not in the block — they are picked on the
 * Accreditation records in admin — so the block must pull each one in by code.
 * Without that, a logo assigned in admin never reaches the homepage (the exact
 * bug: "I assigned logos but they still don't show").
 */

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->seed(HomePageSeeder::class);
});

it('shows an admin-assigned logo in the accreditation block', function (): void {
    // The seeded block lists SHRM / CPE / HRCI; give the matching record a logo.
    $hrci = Accreditation::factory()->create(['code' => 'HRCI', 'is_active' => true]);

    $media = MediaFolder::uncategorised()
        ->addMedia(UploadedFile::fake()->image('hrci.png', 240, 120))
        ->toMediaCollection(Media::LIBRARY_COLLECTION);

    $hrci->update(['logo_media_id' => $media->getKey()]);

    $html = $this->get('/')->assertSuccessful()->getContent();

    // The logo must appear in the *visible* scrolling marquee, not only in the
    // visually-hidden list — a $loop/$partner name clash once left the marquee
    // cards empty while the hidden list still carried the logo, hiding the bug.
    $marquee = Illuminate\Support\Str::between($html, 'credential-marquee', 'credential-list');

    expect($marquee)->toContain('credential-logo')
        ->toContain('HR Certification Institute');
});

it('falls back to the code text for a body with no logo', function (): void {
    // No Accreditation records at all: every block row shows its code as text,
    // and no logo image is emitted.
    $this->get('/')
        ->assertSuccessful()
        ->assertSee('HRCI', escape: false)
        ->assertDontSee('credential-logo', escape: false);
});
