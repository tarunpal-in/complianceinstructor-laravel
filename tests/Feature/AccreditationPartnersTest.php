<?php

declare(strict_types=1);

use App\Models\Accreditation;
use App\Support\HomepageContent;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Blade;

/*
 * The homepage "Accreditation Partners" section pulls active accrediting bodies
 * and shows each one's uploaded logo — §5.3.
 */

it('lists active accrediting bodies, excluding inactive ones', function (): void {
    Accreditation::factory()->create(['code' => 'HRCI', 'is_active' => true]);
    Accreditation::factory()->create(['code' => 'OLD', 'is_active' => false]);

    expect(collect(HomepageContent::accreditations())->pluck('code'))
        ->toContain('HRCI')
        ->not->toContain('OLD');
});

it('carries the uploaded logo url when a body has one', function (): void {
    $body = Accreditation::factory()->create(['code' => 'HRCI', 'name' => 'HR Certification Institute', 'is_active' => true]);
    $body->addMedia(UploadedFile::fake()->image('hrci.png', 240, 120))->toMediaCollection('logo');

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
    $body->addMedia(UploadedFile::fake()->image('hrci.png', 240, 120))->toMediaCollection('logo');

    $html = Blade::render('<x-home.accreditation />');

    expect($html)->toContain('credential-logo');
});

it('falls back to the default partners when none exist', function (): void {
    $html = Blade::render('<x-home.accreditation />');

    expect($html)->toContain('Society for Human Resource Mgmt');
});
