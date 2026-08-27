<?php

declare(strict_types=1);

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

it('shows all four membership prices', function (): void {
    $response = $this->get('/');

    foreach (['$299', '$499', '$799', '$999'] as $price) {
        $response->assertSee($price, escape: false);
    }
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
        ->toContain('>22</span>')
        ->toContain('>Sep<')
        ->toContain('Sep 22, 2026');
});

it('provides a skip link and a labelled main landmark', function (): void {
    $this->get('/')
        ->assertSee('Skip to content')
        ->assertSee('id="main"', escape: false);
});
