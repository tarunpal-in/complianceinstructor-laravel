<?php

declare(strict_types=1);

use App\Enums\WebinarType;
use App\Models\Webinar;
use App\Support\HomepageContent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;

/*
 * The hero's dynamic bits — the rotating "Next live webinar" dates (real, from
 * the catalogue) and the admin-editable hero image.
 */

// Live dates -------------------------------------------------------------------

it('returns the next upcoming live webinar dates, soonest first', function (): void {
    $soon = Webinar::factory()->scheduled()->create(['scheduled_at' => now()->addDays(10)->setTime(17, 0)]);
    $later = Webinar::factory()->scheduled()->create(['scheduled_at' => now()->addDays(40)->setTime(17, 0)]);
    // Excluded: a past live session and an upcoming recorded one.
    Webinar::factory()->scheduled()->create(['scheduled_at' => now()->subWeek()]);
    Webinar::factory()->scheduled()->create(['type' => WebinarType::Recorded, 'scheduled_at' => now()->addWeek()]);

    $dates = HomepageContent::liveWebinarDates();

    expect($dates)->toBe([$soon->scheduledDateLabel(), $later->scheduledDateLabel()]);
});

it('caps the live dates at three', function (): void {
    Webinar::factory()->count(5)->scheduled()->create([
        'scheduled_at' => now()->addDays(fake()->numberBetween(2, 60)),
    ]);

    expect(HomepageContent::liveWebinarDates())->toHaveCount(3);
});

it('shows a real upcoming live date in the hero badge', function (): void {
    $webinar = Webinar::factory()->scheduled()->create(['scheduled_at' => Carbon::parse('2026-10-30 17:00', 'UTC')]);

    $this->get('/')
        ->assertSuccessful()
        ->assertSee($webinar->scheduledDateLabel());
});

// Hero image -------------------------------------------------------------------

it('uses an admin-supplied hero image when given, else the default', function (): void {
    // One image renders static; the prop is now a list (the hero supports a
    // multi-image slider), so a single-item array is the single-image case.
    $custom = Blade::render('<x-home.hero :image-urls="$u" />', ['u' => ['https://cdn.test/hero.jpg']]);
    expect($custom)->toContain('https://cdn.test/hero.jpg');

    $default = Blade::render('<x-home.hero />');
    expect($default)->toContain('images/home/seminar.jpg');
});

it('renders the hero image slider when several images are supplied', function (): void {
    $slider = Blade::render('<x-home.hero :image-urls="$u" />', ['u' => [
        'https://cdn.test/a.jpg', 'https://cdn.test/b.jpg',
    ]]);

    expect($slider)->toContain('hero-slides')
        ->toContain('heroSlides({ count: 2 })')
        ->toContain('https://cdn.test/a.jpg')
        ->toContain('https://cdn.test/b.jpg');
});
