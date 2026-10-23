<?php

declare(strict_types=1);

use App\Models\Accreditation;
use App\Models\Media;
use App\Models\MediaFolder;
use App\Support\HomepageContent;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Blade;

/*
 * The homepage "Accreditation Partners" section pulls active accrediting bodies
 * and shows each one's logo — §5.3. The logo is a library file picked with
 * MediaPicker (logo_media_id), the same reference model webinar images use.
 */

/** Give a body a logo by picking a library file, as the admin would. */
function giveBodyLogo(Accreditation $body): void
{
    $media = MediaFolder::uncategorised()
        ->addMedia(UploadedFile::fake()->image('hrci.png', 240, 120))
        ->toMediaCollection(Media::LIBRARY_COLLECTION);

    $body->update(['logo_media_id' => $media->getKey()]);
}

it('lists active accrediting bodies, excluding inactive ones', function (): void {
    Accreditation::factory()->create(['code' => 'HRCI', 'is_active' => true]);
    Accreditation::factory()->create(['code' => 'OLD', 'is_active' => false]);

    expect(collect(HomepageContent::accreditations())->pluck('code'))
        ->toContain('HRCI')
        ->not->toContain('OLD');
});

it('carries the logo url when a body has one', function (): void {
    $body = Accreditation::factory()->create(['code' => 'HRCI', 'name' => 'HR Certification Institute', 'is_active' => true]);
    giveBodyLogo($body);

    $partner = collect(HomepageContent::accreditations())->firstWhere('code', 'HRCI');

    expect($partner['logo_url'])->not->toBeNull();
});

it('renders real accrediting bodies in the section', function (): void {
    Accreditation::factory()->create(['code' => 'APA', 'name' => 'American Payroll Association', 'is_active' => true]);

    $html = Blade::render('<x-home.accreditation />');

    expect($html)->toContain('American Payroll Association')
        ->and($html)->toContain('APA');
});

it('shows the logo image when a body has one', function (): void {
    $body = Accreditation::factory()->create(['code' => 'HRCI', 'name' => 'HR Certification Institute', 'is_active' => true]);
    giveBodyLogo($body);

    $html = Blade::render('<x-home.accreditation />');

    expect($html)->toContain('credential-logo');
});

it('falls back to the default partners when none exist', function (): void {
    $html = Blade::render('<x-home.accreditation />');

    expect($html)->toContain('Society for Human Resource Mgmt');
});
