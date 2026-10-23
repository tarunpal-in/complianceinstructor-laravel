<?php

declare(strict_types=1);

use App\Models\Accreditation;
use App\Models\Industry;
use App\Models\Speaker;
use App\Models\Webinar;
use Database\Seeders\CorePageSeeder;
use Illuminate\Support\Facades\Storage;

/*
 * The public faculty — §6 "Speakers | Index & profile" (design-ui/All Speakers
 * Page.png). Smoke coverage per §10 plus the filters, which are the page's
 * reason to exist.
 */

beforeEach(function (): void {
    Storage::fake('public');
    // The hero now comes from the editable Speakers core page.
    $this->seed(CorePageSeeder::class);
});

/** A speaker with one webinar carrying the given accreditation + industry. */
function facultyMember(string $name, string $code = 'HRCI', ?Industry $industry = null, bool $active = true): Speaker
{
    $speaker = Speaker::factory()->create(['name' => $name, 'is_active' => $active]);

    // A unique slug rather than the factory's fixed list, so a caller's explicit
    // industry can never collide with a helper-made one.
    $webinar = Webinar::factory()->scheduled()->create([
        'speaker_id' => $speaker->id,
        'industry_id' => ($industry ?? Industry::factory()->create([
            'name' => 'Field '.Illuminate\Support\Str::random(6),
            'slug' => 'field-'.Illuminate\Support\Str::lower(Illuminate\Support\Str::random(10)),
        ]))->id,
    ]);

    $accreditation = Accreditation::firstOrCreate(['code' => $code], ['name' => $code, 'is_active' => true]);
    $webinar->accreditations()->attach($accreditation->id, ['credits' => 1.50]);

    return $speaker;
}

it('renders the speakers index with its hero', function (): void {
    $this->get('/speakers')
        ->assertSuccessful()
        ->assertSee('Learn from the Best in Compliance', escape: false)
        ->assertSee('JOIN OUR FACULTY', escape: false);
});

it('lists active speakers with their accrediting bodies and hides inactive ones', function (): void {
    facultyMember('Ada Lovelace', 'HRCI');
    facultyMember('Grace Hopper', 'SHRM', active: false);

    $this->get('/speakers')
        ->assertSee('Ada Lovelace', escape: false)
        ->assertSee('HRCI', escape: false)
        ->assertDontSee('Grace Hopper', escape: false);
});

it('counts the instructors found', function (): void {
    facultyMember('One Speaker');
    facultyMember('Two Speaker');

    $this->get('/speakers')->assertSee('2</strong> instructors found', escape: false);
});

it('filters by a search term', function (): void {
    facultyMember('Marie Curie');
    facultyMember('Isaac Newton');

    $this->get('/speakers?q=Curie')
        ->assertSee('Marie Curie', escape: false)
        ->assertDontSee('Isaac Newton', escape: false);
});

it('filters by topic (industry)', function (): void {
    $payroll = Industry::factory()->create(['name' => 'Payroll', 'slug' => 'payroll']);
    facultyMember('Payroll Pro', industry: $payroll);
    facultyMember('Safety Specialist');

    $this->get('/speakers?topic=payroll')
        ->assertSee('Payroll Pro', escape: false)
        ->assertDontSee('Safety Specialist', escape: false);
});

it('filters by credit (accrediting body)', function (): void {
    facultyMember('HR Person', 'HRCI');
    facultyMember('CPA Person', 'CPE');

    $this->get('/speakers?credit=CPE')
        ->assertSee('CPA Person', escape: false)
        ->assertDontSee('HR Person', escape: false);
});

it('renders a speaker profile and links to it', function (): void {
    $speaker = Speaker::factory()->create(['name' => 'Jason Dinesen', 'bio' => 'The 1099 Guy.', 'is_active' => true]);

    $this->get('/speakers')->assertSee(route('speakers.show', $speaker), escape: false);

    $this->get(route('speakers.show', $speaker))
        ->assertSuccessful()
        ->assertSee('Jason Dinesen', escape: false)
        ->assertSee('The 1099 Guy.', escape: false);
});

it('shows the profile stats, credits, expertise, topics and webinars', function (): void {
    $industry = Industry::factory()->create(['name' => 'Banking & Finance']);
    $speaker = Speaker::factory()->create([
        'name' => 'Robert Chen',
        'is_active' => true,
        'rating' => 4.8,
        'reviews_count' => 980,
        'years_teaching' => 14,
        'expertise' => ['BSA/AML Compliance', 'Fraud Prevention'],
    ]);

    $cpe = Accreditation::firstOrCreate(['code' => 'CPE'], ['name' => 'Continuing Professional Education', 'is_active' => true]);
    $webinar = Webinar::factory()->scheduled()->create([
        'speaker_id' => $speaker->id,
        'industry_id' => $industry->id,
        'title' => 'Red Flags, SARs & Emerging Risks',
    ]);
    $webinar->accreditations()->attach($cpe->id, ['credits' => 1.50]);

    $this->get(route('speakers.show', $speaker))
        ->assertSuccessful()
        ->assertSee('4.8 / 5', escape: false)                       // rating (hidden when unset)
        ->assertSee('980', escape: false)                           // reviews
        ->assertSee('14 years', escape: false)                      // years teaching
        ->assertSee('Fraud Prevention', escape: false)              // expertise tag
        ->assertSee('Banking & Finance')                            // topic domain (derived)
        ->assertSee('Continuing Professional Education', escape: false) // accredited credit
        ->assertSee('Red Flags, SARs & Emerging Risks');            // webinar row
});

it('omits the rating row when a speaker has no rating', function (): void {
    $speaker = Speaker::factory()->create(['is_active' => true, 'rating' => null]);

    $this->get(route('speakers.show', $speaker))
        ->assertSuccessful()
        ->assertDontSee('Overall Rating', escape: false);
});

it('404s the profile of an inactive speaker', function (): void {
    $speaker = Speaker::factory()->create(['is_active' => false]);

    $this->get(route('speakers.show', $speaker))->assertNotFound();
});

it('does not serve the index at a page slug', function (): void {
    // `speakers` is reserved, so the page catch-all never answers /speakers.
    expect(App\Models\Page::RESERVED_SLUGS)->toContain('speakers');
});
