<?php

declare(strict_types=1);

use App\Models\Industry;
use App\Models\Speaker;
use App\Models\Webinar;
use Database\Seeders\DemoCatalogueSeeder;
use Database\Seeders\HomePageSeeder;

/*
 * The demo catalogue stands in for the client's real one so the seeded review
 * environment (and the homepage's live sections) is not half empty — §4 rule 1
 * / §5.9. I verify it with Pest rather than running migrate:fresh --seed.
 */

it('seeds a publishable, upcoming catalogue', function (): void {
    $this->seed(DemoCatalogueSeeder::class);

    $webinars = Webinar::all();

    expect($webinars)->toHaveCount(8);

    $webinars->each(function (Webinar $w): void {
        expect($w->isPubliclyVisible())->toBeTrue()
            ->and($w->scheduled_at->isFuture())->toBeTrue()
            // Speaker, active offering, accreditation with credits — the publish gate.
            ->and($w->publishableProblems())->toBe([]);
    });

    expect(Industry::where('is_active', true)->count())->toBeGreaterThanOrEqual(4)
        ->and(Speaker::where('is_active', true)->count())->toBeGreaterThanOrEqual(6);
});

it('stands aside when a real webinar already exists', function (): void {
    Webinar::factory()->create();

    $this->seed(DemoCatalogueSeeder::class);

    // The client's own catalogue wins — no demo rows stacked beneath it.
    expect(Webinar::count())->toBe(1);
});

it('populates the homepage live sections', function (): void {
    $this->seed(HomePageSeeder::class);
    $this->seed(DemoCatalogueSeeder::class);

    $this->get('/')
        ->assertSuccessful()
        // A webinar (soonest first), an industry, a speaker — one from each live section.
        ->assertSee('FMLA Compliance 2027: Avoiding Costly Mistakes', escape: false)
        ->assertSee('Human Resources', escape: false)
        ->assertSee('Diane Whitfield', escape: false);
});
