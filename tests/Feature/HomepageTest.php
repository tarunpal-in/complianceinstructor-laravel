<?php

declare(strict_types=1);

use App\Models\MembershipPlan;
use Database\Seeders\MembershipPlanSeeder;

/**
 * CLAUDE.md §10 — front-end pages get smoke coverage: "Assert 200 and key
 * content on every public route. Catches broken views after refactors for
 * almost nothing."
 */
it('renders the homepage', function (): void {
    $this->get('/')->assertSuccessful();
});

it('renders every section of the comp', function (string $marker): void {
    $this->get('/')->assertSee($marker, escape: false);
})->with([
    'Stay Ahead',
    'How It Works',
    'Upcoming Webinars',
    'Webinar Categories',
    'Expert-Led Online Sessions',
    'Experienced Faculty',
    'Accreditation Partners',
    'Success Stories',
    'Membership Plans',
    'Request a Callback',
]);

/**
 * The pricing cards read `membership_plans` (§5.5), so this needs the seeder.
 * That is the point: the section is now the client's data, not a comp's copy.
 */
it('shows all four membership prices', function (): void {
    $this->seed(MembershipPlanSeeder::class);

    $response = $this->get('/');

    foreach (['$299', '$499', '$799', '$999'] as $price) {
        $response->assertSee($price, escape: false);
    }
});

it('builds each pricing card from the plan record', function (): void {
    $this->seed(MembershipPlanSeeder::class);

    $response = $this->get('/');

    $response
        ->assertSee('Regular', escape: false)
        ->assertSee('/ 3 months', escape: false)
        // The quota badge comes from `plan_entitlements`, so the number a
        // customer reads is the number they are granted.
        ->assertSee('3 Live Webinars', escape: false)
        ->assertSee('10 Live Webinars', escape: false)
        ->assertSee('Certificate of Completion', escape: false)
        ->assertSee('MOST POPULAR', escape: false);
});

it('shows the highlighted tier from the record, not the markup', function (): void {
    $this->seed(MembershipPlanSeeder::class);

    MembershipPlan::query()->update(['is_featured' => false]);
    MembershipPlan::query()->where('slug', 'regular')->update(['is_featured' => true]);

    $html = $this->get('/')->getContent();

    // The badge sits inside the Regular card now — the first one on the page.
    expect($html)->toContain('class="plan popular"')
        ->and(substr_count((string) $html, 'MOST POPULAR'))->toBe(1)
        ->and(strpos((string) $html, 'MOST POPULAR'))->toBeLessThan((int) strpos((string) $html, 'Plus'));
});

/**
 * The catalogue can be empty on a fresh install, and a heading over four blank
 * boxes is worse than no section at all.
 */
it('drops the pricing grid when no plan is on sale', function (): void {
    $this->seed(MembershipPlanSeeder::class);

    MembershipPlan::query()->update(['is_active' => false]);

    $this->get('/')
        ->assertSuccessful()
        ->assertDontSee('plans-grid', escape: false)
        // The callback offer is not a plan, so it stays.
        ->assertSee('Request a Callback', escape: false);
});

/**
 * The comp carries copy from two other brands — "CourseMinistry" (the
 * predecessor being replaced) and "Comply Masters". Neither may ever ship.
 */
it('carries no leftover brand names from the comp', function (string $brand): void {
    $this->get('/')->assertDontSee($brand, escape: false);
})->with(['CourseMinistry', 'Comply Masters']);

/**
 * The comp shows a card badged "26 AUG" whose own body text reads "Aug 22".
 * Badge and body both derive from one Carbon instance now, so they cannot drift.
 */
it('derives the webinar date badge and body from one date', function (): void {
    $html = $this->get('/')->getContent();

    // First card: badge day + month, then the same date spelled out in the body.
    expect($html)
        ->toContain('<strong>22</strong>')
        ->toContain('<span>SEP</span>')
        ->toContain('Sep 22, 2026');
});

it('provides a skip link and a labelled main landmark', function (): void {
    // The comp anchors the brand link and skip link to <main id="top">.
    $this->get('/')
        ->assertSee('Skip to content')
        ->assertSee('<main id="top">', escape: false);
});
