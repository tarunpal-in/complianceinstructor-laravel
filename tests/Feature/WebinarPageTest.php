<?php

declare(strict_types=1);

use App\Models\Accreditation;
use App\Models\Industry;
use App\Models\Offering;
use App\Models\Setting;
use App\Models\Speaker;
use App\Models\Webinar;
use Database\Seeders\SettingSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('public');
    $this->seed(SettingSeeder::class);
});

// Visibility ------------------------------------------------------------------

it('renders a published webinar', function (): void {
    $webinar = publishedWebinar();

    $this->get(route('webinars.show', $webinar))
        ->assertSuccessful()
        ->assertSee('FMLA Compliance 2026: Avoiding Costly Mistakes')
        ->assertSee('Discover how the H.O.P.E. framework reshapes leadership culture.');
});

/**
 * A draft keeps its slug, so without the guard route-model binding would
 * resolve it and quietly publish an unfinished session. 404 rather than 403 —
 * that an unreleased session exists is not public information.
 */
it('hides a draft webinar behind a 404', function (): void {
    $this->get(route('webinars.show', Webinar::factory()->create()))->assertNotFound();
});

it('hides an archived webinar behind a 404', function (): void {
    $webinar = publishedWebinar();
    $webinar->update(['status' => App\Enums\WebinarStatus::Archived]);

    $this->get(route('webinars.show', $webinar))->assertNotFound();
});

// The purchase panel ----------------------------------------------------------

/**
 * §5.3 makes `offerings` the only place a price is read from, so the panel must
 * render those rows and nothing else. A bundle's label is composed from its
 * formats, which is what makes the comp's seven options possible.
 */
it('lists every active offering with its composed label and price', function (): void {
    $webinar = publishedWebinar();

    Offering::factory()->live()->priced(19900)->for($webinar, 'offerable')->create();
    Offering::factory()->transcript()->priced(17900)->for($webinar, 'offerable')->create();
    Offering::factory()->everything()->priced(27900)->for($webinar, 'offerable')->create();

    $response = $this->get(route('webinars.show', $webinar));

    $response->assertSee('Live Webinar')
        ->assertSee('Transcript (PDF)')
        ->assertSee('$199')
        ->assertSee('$179')
        ->assertSee('$279');
});

it('leaves an inactive offering out of the panel', function (): void {
    $webinar = publishedWebinar();

    Offering::factory()->live()->priced(19900)->for($webinar, 'offerable')->create();
    Offering::factory()->download()->priced(59900)->inactive()->for($webinar, 'offerable')->create();

    $this->get(route('webinars.show', $webinar))
        ->assertSee('$199')
        ->assertDontSee('$599');
});

/**
 * The publish gate refuses a webinar with no active offering, so this is only
 * reachable by switching every option off afterwards. Saying so beats an empty
 * box with a dead button.
 */
it('says booking is closed when nothing is on sale', function (): void {
    $this->get(route('webinars.show', publishedWebinar()))
        ->assertSee('Booking for this session is not open yet.');
});

/**
 * §14 #1 — the browser is never the source of a price. The panel may total up
 * for display, but the amounts it totals are printed by the server from
 * `offerings.price_cents`, and no total is ever posted back.
 */
it('never renders a form that could post a price', function (): void {
    $webinar = publishedWebinar();
    Offering::factory()->live()->priced(19900)->for($webinar, 'offerable')->create();

    $html = $this->get(route('webinars.show', $webinar))->getContent();

    expect($html)->not->toContain('name="price"')
        ->and($html)->not->toContain('name="total"')
        ->and($html)->not->toContain('final_amount');
});

// Tooltips --------------------------------------------------------------------

/**
 * docs/webinars-module.md §2.5 — hover-only tooltips fail WCAG 2.1 §1.4.13:
 * unreachable by keyboard, and on touch a tap either does nothing or fires the
 * row. §7 P9 audits for AA, so the markup has to carry the affordance.
 */
it('gives each purchase option a keyboard-reachable tooltip', function (): void {
    $webinar = publishedWebinar();
    $offering = Offering::factory()->live()->priced(19900)->for($webinar, 'offerable')->create();

    $this->get(route('webinars.show', $webinar))
        ->assertSee('<button', escape: false)
        ->assertSee('aria-describedby="offering-help-'.$offering->id.'"', escape: false)
        ->assertSee('role="tooltip"', escape: false)
        ->assertSee('What is included in Live Webinar?', escape: false);
});

it('shows the site-wide format description in the tooltip', function (): void {
    Setting::set('formats.live.description', 'Attend on the day and ask questions live.');

    $webinar = publishedWebinar();
    Offering::factory()->live()->priced(19900)->for($webinar, 'offerable')->create();

    $this->get(route('webinars.show', $webinar))
        ->assertSee('Attend on the day and ask questions live.');
});

// Content ---------------------------------------------------------------------

it('renders each section in order and skips the invisible ones', function (): void {
    $webinar = publishedWebinar(sections: [
        ['heading' => 'Webinar Overview', 'body' => 'First overview paragraph.'],
        ['heading' => 'Session Highlights', 'body' => 'What the session covers.'],
        ['heading' => 'Why You Should Attend', 'body' => 'Hidden reasons.', 'is_visible' => false],
    ]);

    $this->get(route('webinars.show', $webinar))
        ->assertSee('Webinar Overview')
        ->assertSee('Session Highlights')
        ->assertSee('First overview paragraph.')
        ->assertDontSee('Why You Should Attend')
        ->assertDontSee('Hidden reasons.');
});

it('links the type and industry crumbs in the breadcrumb', function (): void {
    $webinar = publishedWebinar();

    expect($webinar->industry)->not->toBeNull();

    $this->get(route('webinars.show', $webinar))
        ->assertSuccessful()
        ->assertSee('href="'.e(route('webinars.index', ['type' => $webinar->type->value])).'"', escape: false)
        ->assertSee('href="'.e(route('industries.show', $webinar->industry)).'"', escape: false);
});

it('anchors the overview section so the hero "Click to Know More" scrolls to it', function (): void {
    $webinar = publishedWebinar(sections: [
        ['heading' => 'Webinar Overview', 'body' => 'First overview paragraph.'],
        ['heading' => 'Session Highlights', 'body' => 'What the session covers.'],
    ]);

    $this->get(route('webinars.show', $webinar))
        ->assertSuccessful()
        // The hero button links to #overview, and the first section carries that
        // id as its scroll target, so the jump lands on the overview.
        ->assertSee('href="#overview"', escape: false)
        ->assertSee('id="overview"', escape: false);

    expect($webinar->contentSections()[0]['id'])->toBe('overview');
});

it('renders a section with no heading as body only', function (): void {
    $webinar = publishedWebinar(sections: [
        ['heading' => 'Webinar Overview', 'body' => 'The overview.'],
        ['heading' => null, 'body' => 'You will get the recording link within 24-48 hours.'],
    ]);

    // The notice body shows, but a heading-less section earns no tab link.
    $this->get(route('webinars.show', $webinar))
        ->assertSee('You will get the recording link within 24-48 hours.')
        ->assertSee('The overview.');
});

it('sanitizes dangerous markup out of a section rather than rendering it', function (): void {
    $webinar = publishedWebinar(sections: [
        ['heading' => 'Webinar Overview', 'body' => '<p>Safe copy.</p><script>alert(1)</script>'],
    ]);

    $this->get(route('webinars.show', $webinar))
        ->assertSee('Safe copy.')
        ->assertDontSee('<script>alert(1)</script>', escape: false);
});

it('shows the speaker with their credentials', function (): void {
    $speaker = Speaker::factory()->create([
        'name' => 'Jason Dinesen',
        'title' => 'Executive Leadership Coach',
        'credentials' => 'CPA · SHRM-SCP',
        'bio' => 'A Des Moines-based CPA.',
    ]);

    $this->get(route('webinars.show', publishedWebinar(['speaker_id' => $speaker->id])))
        ->assertSee('Jason Dinesen')
        ->assertSee('Executive Leadership Coach')
        ->assertSee('CPA · SHRM-SCP');
});

it('lists the accrediting bodies in the hero', function (): void {
    $webinar = publishedWebinar();
    $webinar->accreditations()->attach(Accreditation::factory()->create(['code' => 'HRCI']), ['credits' => 1.5]);
    $webinar->accreditations()->attach(Accreditation::factory()->create(['code' => 'SHRM']), ['credits' => 1.5]);

    $this->get(route('webinars.show', $webinar))->assertSee('HRCI &amp; SHRM', escape: false);
});

it('falls back to the site-wide attendee instructions', function (): void {
    Setting::set('webinars.default_instructions', 'Your joining kit arrives 24 hours before.');

    $this->get(route('webinars.show', publishedWebinar()))
        ->assertSee('Important Notice for Our Live Attendees')
        ->assertSee('Your joining kit arrives 24 hours before.');
});

it('prefers a per-webinar instruction override', function (): void {
    Setting::set('webinars.default_instructions', 'The site-wide text.');

    $this->get(route('webinars.show', publishedWebinar(['attendee_instructions' => 'Bring your own kit.'])))
        ->assertSee('Bring your own kit.')
        ->assertDontSee('The site-wide text.');
});

// The rail --------------------------------------------------------------------

it('prefers upcoming sessions over past ones in the rail', function (): void {
    $industry = Industry::factory()->create();
    $webinar = publishedWebinar(['industry_id' => $industry->id]);

    // Four upcoming sessions fill the rail; a past one is left out.
    Webinar::factory()->count(4)->scheduled()->create([
        'industry_id' => $industry->id,
        'scheduled_at' => now()->addWeeks(2),
    ]);
    Webinar::factory()->completed()->create([
        'industry_id' => $industry->id,
        'title' => 'Expired Past Session',
    ]);

    $this->get(route('webinars.show', $webinar))
        ->assertSee('Similar Webinars')
        ->assertDontSee('Expired Past Session');
});

it('fills the rail with other webinars from the same industry first', function (): void {
    $industry = Industry::factory()->create();

    $webinar = publishedWebinar(['industry_id' => $industry->id]);
    Webinar::factory()->scheduled()->create([
        'industry_id' => $industry->id,
        'title' => 'Wage And Hour Essentials',
    ]);
    Webinar::factory()->scheduled()->create(['title' => 'Unrelated Session']);

    $response = $this->get(route('webinars.show', $webinar));

    $response->assertSee('Similar Webinars')
        ->assertSee('Wage And Hour Essentials')
        // Topped up from the rest of the catalogue, because an empty rail in a
        // column that reserves a third of the width looks broken.
        ->assertSee('Unrelated Session');
});

it('keeps the current webinar out of its own rail', function (): void {
    $industry = Industry::factory()->create();
    $webinar = publishedWebinar(['title' => 'The Only One', 'industry_id' => $industry->id]);
    Webinar::factory()->scheduled()->create(['industry_id' => $industry->id, 'title' => 'A Different Session']);

    // Scoped to the rail: the title legitimately appears in <title>, the
    // Open Graph tag, the breadcrumb and the H1, so counting it across the
    // whole document would prove nothing.
    $html = (string) $this->get(route('webinars.show', $webinar))->getContent();
    $rail = Str::between($html, '<aside class="wd-rail"', '</aside>');

    expect($rail)->toContain('A Different Session')
        ->and($rail)->not->toContain('The Only One');
});

it('leaves drafts out of the rail', function (): void {
    $industry = Industry::factory()->create();
    $webinar = publishedWebinar(['industry_id' => $industry->id]);
    Webinar::factory()->create(['industry_id' => $industry->id, 'title' => 'Not Ready Yet']);

    $this->get(route('webinars.show', $webinar))->assertDontSee('Not Ready Yet');
});
