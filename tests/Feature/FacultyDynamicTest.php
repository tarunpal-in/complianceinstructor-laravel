<?php

declare(strict_types=1);

use App\Models\Speaker;
use App\Support\HomepageContent;
use Illuminate\Support\Facades\Blade;

/*
 * The homepage "Experienced Faculty" section pulls real speakers, on every
 * render path — including the fallback where the page has no home_faculty block.
 */

it('lists active speakers, excluding inactive ones', function (): void {
    Speaker::factory()->create(['name' => 'Ada Active', 'is_active' => true]);
    Speaker::factory()->create(['name' => 'Ivan Inactive', 'is_active' => false]);

    expect(collect(HomepageContent::activeSpeakers())->pluck('name'))
        ->toContain('Ada Active')
        ->not->toContain('Ivan Inactive');
});

it('renders real speakers in the faculty section, linking to their profile', function (): void {
    $speaker = Speaker::factory()->create(['name' => 'Jordan Realfaculty', 'title' => 'Compliance Lead', 'is_active' => true]);

    $html = Blade::render('<x-home.faculty />');

    expect($html)->toContain('Jordan Realfaculty')
        // Each card links to the speaker's single page.
        ->and($html)->toContain(route('speakers.show', $speaker->slug))
        // The hardcoded demo speakers are not used once real ones exist.
        ->and($html)->not->toContain('Marcus Reed');
});

it('falls back to the demo speakers only when none exist', function (): void {
    $html = Blade::render('<x-home.faculty />');

    expect($html)->toContain('Diane Whitfield');
});
