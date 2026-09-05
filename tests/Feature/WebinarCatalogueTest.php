<?php

declare(strict_types=1);

use App\Enums\Format;
use App\Enums\WebinarStatus;
use App\Enums\WebinarType;
use App\Models\Accreditation;
use App\Models\Offering;
use App\Models\Setting;
use App\Models\Webinar;

/**
 * An offering granting several formats is the whole reason formats live on a
 * pivot (docs/webinars-module.md §2.1). §5.2 keys entitlements on
 * (user, webinar, format), so a three-format bundle must be able to say so —
 * otherwise a customer pays $279 and receives one format.
 */
it('lets one offering grant three formats', function (): void {
    $offering = Offering::factory()->everything()->create();

    expect($offering->formatEnums())->toHaveCount(3)
        ->and($offering->grants(Format::Live))->toBeTrue()
        ->and($offering->grants(Format::Recorded))->toBeTrue()
        ->and($offering->grants(Format::Transcript))->toBeTrue()
        ->and($offering->grants(Format::Download))->toBeFalse();
});

it('composes a bundle label from its formats', function (): void {
    expect(Offering::factory()->everything()->create()->label())
        ->toBe('Live Webinar + Recorded + Transcript (PDF)')
        ->and(Offering::factory()->recorded()->create()->label())
        ->toBe('Recorded');
});

/**
 * A duplicated format would grant the same entitlement twice and, once quotas
 * exist (§5.5), consume two of an allowance for one purchase.
 */
it('refuses the same format twice on one offering', function (): void {
    $offering = Offering::factory()->recorded()->create();

    $offering->formats()->create(['format' => Format::Recorded]);
})->throws(Illuminate\Database\QueryException::class);

it('charges the add-on price only when asked for it', function (): void {
    $offering = Offering::factory()->recorded()->create();

    expect($offering->priceCents())->toBe(19900)
        ->and($offering->priceCents(asAddon: true))->toBe(5500);
});

/**
 * A missing add-on price must fall back to the full price. Returning zero, or
 * null coerced to zero, would make the item free.
 */
it('falls back to the full price when no add-on price is set', function (): void {
    $offering = Offering::factory()->live()->create();

    expect($offering->isAvailableAsAddon())->toBeFalse()
        ->and($offering->priceCents(asAddon: true))->toBe(19900);
});

// The publish gate ----------------------------------------------------------

it('will not publish a webinar with nothing to buy', function (): void {
    $webinar = Webinar::factory()->create();
    $webinar->accreditations()->attach(Accreditation::factory()->create(), ['credits' => 1.5]);

    expect($webinar->publishableProblems())
        ->toContain('It has no active offering, so there is nothing to buy.');
});

it('will not publish a webinar awarding no credits', function (): void {
    $webinar = Webinar::factory()->create();
    Offering::factory()->live()->for($webinar, 'offerable')->create();

    expect($webinar->publishableProblems())
        ->toContain('It awards no accreditation credits.');
});

it('will not publish a live webinar with no date', function (): void {
    $webinar = Webinar::factory()->create(['scheduled_at' => null]);

    expect($webinar->publishableProblems())
        ->toContain('A live webinar needs a date and time.');
});

/**
 * The check that stops the platform taking money for a session that will never
 * happen: a recorded webinar has no live event to attend.
 */
it('will not let a recorded webinar sell the live format', function (): void {
    $webinar = Webinar::factory()->recorded()->create();
    Offering::factory()->live()->for($webinar, 'offerable')->create();

    expect($webinar->refresh()->publishableProblems())
        ->toContain('A Recorded webinar cannot sell the Live Webinar format.');
});

it('clears every problem once the webinar is complete', function (): void {
    $webinar = Webinar::factory()->create();
    Offering::factory()->everything()->for($webinar, 'offerable')->create();
    $webinar->accreditations()->attach(Accreditation::factory()->create(), ['credits' => 1.5]);

    expect($webinar->refresh()->publishableProblems())->toBeEmpty();
});

// Instructions --------------------------------------------------------------

it('falls back to the site-wide instructions', function (): void {
    Setting::set('webinars.default_instructions', 'Joining details arrive 24 hours before.', 'string');

    expect(Webinar::factory()->create()->resolvedInstructions())
        ->toBe('Joining details arrive 24 hours before.');
});

it('prefers the webinar’s own instructions', function (): void {
    Setting::set('webinars.default_instructions', 'Site-wide text.', 'string');

    $webinar = Webinar::factory()->create(['attendee_instructions' => 'This one is different.']);

    expect($webinar->resolvedInstructions())->toBe('This one is different.');
});

// Scopes --------------------------------------------------------------------

it('hides drafts and archives from the public scope', function (): void {
    Webinar::factory()->create(['status' => WebinarStatus::Draft]);
    Webinar::factory()->create(['status' => WebinarStatus::Archived]);
    Webinar::factory()->scheduled()->create();
    Webinar::factory()->completed()->create();

    // Completed stays public: a finished webinar still sells its recording,
    // which is most of the catalogue's long tail.
    expect(Webinar::query()->public()->count())->toBe(2);
});

it('orders upcoming webinars soonest first', function (): void {
    $later = Webinar::factory()->create(['scheduled_at' => now()->addMonths(2)]);
    $sooner = Webinar::factory()->create(['scheduled_at' => now()->addWeek()]);
    Webinar::factory()->create(['scheduled_at' => now()->subWeek()]);

    expect(Webinar::query()->upcoming()->pluck('id')->all())
        ->toBe([$sooner->id, $later->id]);
});

it('separates live and recorded by type', function (): void {
    Webinar::factory()->count(2)->create();
    Webinar::factory()->recorded()->create();

    expect(Webinar::query()->ofType(WebinarType::Live)->count())->toBe(2)
        ->and(Webinar::query()->ofType(WebinarType::Recorded)->count())->toBe(1);
});
