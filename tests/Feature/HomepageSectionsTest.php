<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Filament\Pages\HomepageSections;
use App\Filament\Resources\Pages\PageResource;
use App\Models\Page;
use Database\Seeders\HomePageSeeder;
use Database\Seeders\RolePermissionSeeder;

/*
 * The homepage is becoming a builder-driven Page (§2 / §5.6). These cover the
 * hero slice: the seeded homepage renders from the block pixel-for-pixel, the
 * fallback still works on an unseeded install, and the "Homepage Sections"
 * editor is gated and round-trips.
 */

/** The Builder field shape Filament expects for one block. */
function homeBlock(string $type, array $data = [], bool $visible = true): array
{
    return ['type' => $type, 'data' => [...$data, 'is_visible' => $visible]];
}

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

// Rendering -----------------------------------------------------------------

it('seeds the homepage as a Page with a hero block', function (): void {
    $this->seed(HomePageSeeder::class);

    $home = Page::where('slug', 'home')->sole();

    expect($home->sections()->where('type', 'home_hero')->count())->toBe(1);
});

it('renders the hero from the block, matching the comp copy', function (): void {
    $this->seed(HomePageSeeder::class);

    $this->get('/')
        ->assertSuccessful()
        ->assertSee('Stay Ahead', escape: false)
        ->assertSee('Learn from', escape: false)
        ->assertSee('the Best.', escape: false)
        ->assertSee('#1 COMPLIANCE TRAINING PLATFORM', escape: false)
        ->assertSee('Start Learning Now', escape: false)
        ->assertSee('View Courses', escape: false)
        ->assertSee('1,200+', escape: false)
        ->assertSee('Webinars Delivered', escape: false);
});

it('renders process, benefits and stories from their blocks', function (): void {
    $this->seed(HomePageSeeder::class);

    $this->get('/')
        ->assertSuccessful()
        // process
        ->assertSee('How It Works', escape: false)
        ->assertSee('Create Your Free Account', escape: false)
        // benefits
        ->assertSee('Expert-Led Online Sessions', escape: false)
        // stories
        ->assertSee('Success Stories', escape: false)
        ->assertSee('Sandra Mitchell', escape: false)
        ->assertSee('from 312 reviews', escape: false);
});

it('numbers the process steps by position', function (): void {
    $this->seed(HomePageSeeder::class);

    // Derived from order, so a reorder renumbers rather than stranding "03".
    expect($this->get('/')->getContent())
        ->toContain('<span class="step-number">01</span>')
        ->toContain('<span class="step-number">03</span>');
});

it('exposes the homepage blocks in render order in the editor', function (): void {
    $types = collect(App\Filament\Pages\Schemas\HomeBlocks::all())
        ->map(fn ($block): string => $block->getName())
        ->all();

    expect($types)->toBe([
        'home_hero', 'home_process', 'home_webinars', 'home_categories',
        'home_benefits', 'home_faculty', 'home_accreditation', 'home_stories',
        'home_pricing',
    ]);
});

// Dynamic sections — live data ----------------------------------------------

it('shows upcoming webinars from the catalogue in the webinars block', function (): void {
    $this->seed(HomePageSeeder::class);

    $speaker = App\Models\Speaker::factory()->create(['name' => 'Rosa Klein', 'is_active' => true]);
    App\Models\Webinar::factory()->scheduled()->create([
        'title' => 'Garnishment Compliance 2027',
        'speaker_id' => $speaker->id,
        'scheduled_at' => now()->addMonth(),
    ]);

    $this->get('/')
        ->assertSuccessful()
        ->assertSee('Garnishment Compliance 2027', escape: false)
        ->assertSee('Rosa Klein', escape: false);
});

it('hand-picks webinars in manual mode', function (): void {
    $this->seed(HomePageSeeder::class);

    $shown = App\Models\Webinar::factory()->scheduled()->create(['title' => 'Chosen Session', 'scheduled_at' => now()->addWeek()]);
    App\Models\Webinar::factory()->scheduled()->create(['title' => 'Other Session', 'scheduled_at' => now()->addWeek()]);

    Page::where('slug', 'home')->sole()
        ->sections()->where('type', 'home_webinars')
        ->update(['data->source' => 'manual', 'data->items' => [$shown->id]]);

    $this->get('/')
        ->assertSee('Chosen Session', escape: false)
        ->assertDontSee('Other Session', escape: false);
});

it('shows active industries in the categories block', function (): void {
    $this->seed(HomePageSeeder::class);

    App\Models\Industry::factory()->create(['name' => 'Aviation Safety', 'is_active' => true]);

    $this->get('/')->assertSee('Aviation Safety', escape: false);
});

it('shows active speakers in the faculty block', function (): void {
    $this->seed(HomePageSeeder::class);

    App\Models\Speaker::factory()->create(['name' => 'Ibrahim Osei', 'is_active' => true]);

    $this->get('/')->assertSee('Ibrahim Osei', escape: false);
});

it('omits a dynamic section when it has no records', function (): void {
    // Seeded home page, empty catalogue: the webinars section renders nothing
    // rather than the comp placeholders (those are the unseeded fallback only).
    $this->seed(HomePageSeeder::class);

    $this->get('/')->assertDontSee('FMLA Compliance 2026: Avoiding Costly Mistakes', escape: false);
});

// Content sections driven by the block --------------------------------------

it('renders accreditation and pricing copy from their blocks', function (): void {
    $this->seed(HomePageSeeder::class);
    $this->seed(Database\Seeders\MembershipPlanSeeder::class);

    $this->get('/')
        ->assertSee('Accreditation Partners', escape: false)
        ->assertSee('Society for Human Resource Mgmt', escape: false)
        ->assertSee('Membership Plans', escape: false)
        ->assertSee('Request a Callback', escape: false);
});

it('escapes an accreditation badge rather than trusting its markup', function (): void {
    $this->seed(HomePageSeeder::class);

    Page::where('slug', 'home')->sole()
        ->sections()->where('type', 'home_accreditation')
        ->update(['data->partners' => [['code' => 'XSS', 'name' => 'Test', 'badge' => '<script>alert(1)</script>']]]);

    $this->get('/')
        ->assertSee('XSS', escape: false)
        ->assertDontSee('<script>alert(1)</script>', escape: false);
});

it('falls back to the comp hero when the homepage is not seeded', function (): void {
    // No HomePageSeeder — the home Page does not exist.
    expect(Page::where('slug', 'home')->exists())->toBeFalse();

    $this->get('/')->assertSuccessful()->assertSee('Stay Ahead', escape: false);
});

it('hides the hero entirely when its block is turned off', function (): void {
    $this->seed(HomePageSeeder::class);

    Page::where('slug', 'home')->sole()
        ->sections()->where('type', 'home_hero')->update(['is_visible' => false]);

    // Hidden means gone, not replaced by the default — the fallback only fires
    // on an unseeded install.
    $this->get('/')->assertSuccessful()->assertDontSee('#1 COMPLIANCE TRAINING PLATFORM', escape: false);
});

// Routing / isolation -------------------------------------------------------

it('does not serve the home singleton at /home', function (): void {
    $this->seed(HomePageSeeder::class);

    // The home Page is a Draft served only at `/`, and `home` is reserved.
    $this->get('/home')->assertNotFound();
});

it('keeps the home singleton out of the ordinary Pages list', function (): void {
    $this->seed(HomePageSeeder::class);

    expect(Page::where('slug', 'home')->exists())->toBeTrue()
        ->and(PageResource::getEloquentQuery()->where('slug', 'home')->exists())->toBeFalse();
});

// The editor ----------------------------------------------------------------

it('gates the editor on the page permission', function (): void {
    $this->actingAs(userWithRole(UserRole::ContentEditor));
    expect(HomepageSections::canAccess())->toBeTrue();

    $this->actingAs(userWithRole(UserRole::Finance));
    expect(HomepageSections::canAccess())->toBeFalse();
});

it('round-trips a save without stacking sections', function (): void {
    $this->seed(HomePageSeeder::class);

    Livewire\Livewire::actingAs(userWithRole(UserRole::ContentEditor))
        ->test(HomepageSections::class)
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Page::where('slug', 'home')->sole()->sections()->where('type', 'home_hero')->count())->toBe(1);
});

it('saves an edited hero and shows it on the homepage', function (): void {
    $this->seed(HomePageSeeder::class);

    Livewire\Livewire::actingAs(userWithRole(UserRole::ContentEditor))
        ->test(HomepageSections::class)
        ->fillForm([
            'sections' => [
                homeBlock('home_hero', [
                    'eyebrow' => 'TRUSTED BY TEAMS',
                    'heading_lead' => 'Train Smarter —',
                    'heading_highlight' => 'Stay Compliant',
                    'heading_tail' => 'All Year.',
                    'lede' => 'A fresh lede for the homepage hero.',
                    'primary_label' => 'Get Started',
                    'secondary_label' => 'See Plans',
                    'secondary_url' => '/#memberships',
                    'stats' => [['value' => '900+', 'label' => 'Courses']],
                    'bodies' => [['label' => 'HRCI']],
                ]),
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $this->get('/')
        ->assertSee('TRUSTED BY TEAMS', escape: false)
        ->assertSee('Train Smarter', escape: false)
        ->assertSee('Stay Compliant', escape: false)
        ->assertSee('Get Started', escape: false)
        ->assertDontSee('Start Learning Now', escape: false);
});

it('backfills the designed sections if the home page is emptied', function (): void {
    $this->seed(HomePageSeeder::class);

    Page::where('slug', 'home')->sole()->sections()->delete();

    // Mounting the editor restores the curated set, since the client cannot add
    // blocks back from a locked builder.
    Livewire\Livewire::actingAs(userWithRole(UserRole::ContentEditor))
        ->test(HomepageSections::class);

    expect(Page::where('slug', 'home')->sole()->sections()->where('type', 'home_hero')->exists())->toBeTrue();
});
