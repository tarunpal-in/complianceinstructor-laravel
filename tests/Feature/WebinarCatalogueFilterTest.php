<?php

declare(strict_types=1);

use App\Livewire\WebinarCatalogue;
use App\Models\Accreditation;
use App\Models\Industry;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Webinar;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;

/*
 * The public catalogue and its filters — §6 / §7 P2. The grid reads the public
 * catalogue only; filters are URL-bound so a filtered view is shareable.
 */

function publicWebinar(Industry $industry, string $title, array $attributes = []): Webinar
{
    return Webinar::factory()->scheduled()->create([
        'title' => $title,
        'industry_id' => $industry->getKey(),
        ...$attributes,
    ]);
}

// The page ---------------------------------------------------------------------

it('renders the catalogue page', function (): void {
    $hr = Industry::factory()->create(['name' => 'Human Resources', 'slug' => 'human-resources', 'is_active' => true]);
    publicWebinar($hr, 'FMLA Compliance Essentials');

    $this->get(route('webinars.index'))
        ->assertSuccessful()
        ->assertSee('Webinars &amp; Training', escape: false)
        ->assertSee('FMLA Compliance Essentials');
});

it('shows an industry landing page scoped to that industry', function (): void {
    $hr = Industry::factory()->create(['name' => 'Human Resources', 'slug' => 'human-resources', 'is_active' => true]);

    $this->get(route('industries.show', 'human-resources'))
        ->assertSuccessful()
        ->assertSee('Human Resources');
});

it('404s an inactive industry', function (): void {
    Industry::factory()->create(['slug' => 'secret-topic', 'is_active' => false]);

    $this->get(route('industries.show', 'secret-topic'))->assertNotFound();
});

// Filtering --------------------------------------------------------------------

it('filters by format (type)', function (): void {
    $hr = Industry::factory()->create(['slug' => 'hr', 'is_active' => true]);
    publicWebinar($hr, 'Live FMLA Briefing', ['type' => App\Enums\WebinarType::Live]);
    publicWebinar($hr, 'Recorded FMLA Deep Dive', ['type' => App\Enums\WebinarType::Recorded]);

    Livewire::test(WebinarCatalogue::class)
        ->set('type', 'live')
        ->assertSee('Live FMLA Briefing')
        ->assertDontSee('Recorded FMLA Deep Dive');
});

it('filters by the header search term', function (): void {
    $hr = Industry::factory()->create(['slug' => 'hr', 'is_active' => true]);
    publicWebinar($hr, 'FMLA Compliance Essentials');
    publicWebinar($hr, 'OSHA Recordkeeping Basics');

    Livewire::test(WebinarCatalogue::class)
        ->set('q', 'FMLA')
        ->assertSee('FMLA Compliance Essentials')
        ->assertDontSee('OSHA Recordkeeping Basics');
});

it('reads the search term from the ?q query string', function (): void {
    $hr = Industry::factory()->create(['slug' => 'hr', 'is_active' => true]);
    publicWebinar($hr, 'FMLA Compliance Essentials');
    publicWebinar($hr, 'OSHA Recordkeeping Basics');

    $this->get('/webinars?q=FMLA')
        ->assertSuccessful()
        ->assertSee('FMLA Compliance Essentials')
        ->assertDontSee('OSHA Recordkeeping Basics');
});

it('filters by topic (industry)', function (): void {
    $hr = Industry::factory()->create(['name' => 'Human Resources', 'slug' => 'human-resources', 'is_active' => true]);
    $payroll = Industry::factory()->create(['name' => 'Payroll & Tax', 'slug' => 'payroll-tax', 'is_active' => true]);
    publicWebinar($hr, 'HR Policy Update');
    publicWebinar($payroll, 'Payroll Year-End Close');

    Livewire::test(WebinarCatalogue::class)
        ->set('topics', ['human-resources'])
        ->assertSee('HR Policy Update')
        ->assertDontSee('Payroll Year-End Close');
});

it('filters by accrediting body', function (): void {
    $hr = Industry::factory()->create(['slug' => 'hr', 'is_active' => true]);
    $hrci = Accreditation::factory()->create(['code' => 'HRCI', 'is_active' => true]);

    $accredited = publicWebinar($hr, 'HRCI-Credited Session');
    $accredited->accreditations()->attach($hrci->getKey(), ['credits' => 1.5]);
    publicWebinar($hr, 'Uncredited Session');

    Livewire::test(WebinarCatalogue::class)
        ->set('credits', ['HRCI'])
        ->assertSee('HRCI-Credited Session')
        ->assertDontSee('Uncredited Session');
});

it('filters by duration', function (): void {
    $hr = Industry::factory()->create(['slug' => 'hr', 'is_active' => true]);
    publicWebinar($hr, 'Quick 45-Minute Primer', ['duration_minutes' => 45]);
    publicWebinar($hr, 'Full Two-Hour Workshop', ['duration_minutes' => 120]);

    Livewire::test(WebinarCatalogue::class)
        ->set('duration', 'under60')
        ->assertSee('Quick 45-Minute Primer')
        ->assertDontSee('Full Two-Hour Workshop');
});

it('offers to clear filters when nothing matches', function (): void {
    $hr = Industry::factory()->create(['slug' => 'hr', 'is_active' => true]);
    publicWebinar($hr, 'The Only Webinar');

    Livewire::test(WebinarCatalogue::class)
        ->set('duration', 'under60')
        ->set('credits', ['APA'])
        ->assertSee('No webinars match these filters')
        ->call('clearFilters')
        ->assertSee('The Only Webinar');
});

it('shows the newest webinars first by default', function (): void {
    $hr = Industry::factory()->create(['slug' => 'hr', 'is_active' => true]);
    $old = publicWebinar($hr, 'Old 2021 Session', ['scheduled_at' => now()->subYears(4)]);
    $recent = publicWebinar($hr, 'Recent Session', ['scheduled_at' => now()->subWeek()]);
    $upcoming = publicWebinar($hr, 'Upcoming Session', ['scheduled_at' => now()->addMonth()]);

    Livewire::test(WebinarCatalogue::class)
        ->assertViewHas('webinars', fn ($p) => $p->pluck('id')->all() === [$upcoming->id, $recent->id, $old->id]);
});

it('sorts by real sales when Most Popular is selected, counting settled orders only', function (): void {
    $hr = Industry::factory()->create(['slug' => 'hr', 'is_active' => true]);
    $popular = publicWebinar($hr, 'Best Seller');
    $quiet = publicWebinar($hr, 'Rarely Bought');

    // Two settled purchases for the best seller.
    $paid = Order::factory()->paid()->create();
    OrderItem::factory()->count(2)->create(['order_id' => $paid->id, 'webinar_id' => $popular->id]);

    // Five lines on an unpaid order must NOT count towards popularity.
    $pending = Order::factory()->pending()->create();
    OrderItem::factory()->count(5)->create(['order_id' => $pending->id, 'webinar_id' => $quiet->id]);

    Livewire::test(WebinarCatalogue::class)
        ->set('sort', 'popular')
        ->assertViewHas('webinars', fn ($p) => $p->first()->id === $popular->id);
});

it('paginates the grid after twelve cards', function (): void {
    $hr = Industry::factory()->create(['slug' => 'hr', 'is_active' => true]);
    foreach (range(1, 15) as $n) {
        publicWebinar($hr, "Paginated Webinar {$n}");
    }

    Livewire::test(WebinarCatalogue::class)
        ->assertViewHas('total', 15)                                   // count is the whole result set
        ->assertViewHas('webinars', fn ($p) => $p->count() === 12)     // first page holds twelve
        ->assertSeeHtml('cat-pagination')                              // the pager renders
        ->call('nextPage')
        ->assertSet('paginators.page', 2)
        ->assertViewHas('webinars', fn ($p) => $p->count() === 3);     // the remainder
});

it('returns to page one when a filter changes', function (): void {
    $hr = Industry::factory()->create(['slug' => 'hr', 'is_active' => true]);
    foreach (range(1, 15) as $n) {
        publicWebinar($hr, "Bulk Webinar {$n}");
    }

    Livewire::test(WebinarCatalogue::class)
        ->call('nextPage')
        ->assertSet('paginators.page', 2)
        ->set('q', 'Bulk Webinar 1')   // a filter change must not strand the viewer on page 2
        ->assertSet('paginators.page', 1);
});

it('shows the scheduled time in both PT and ET, never the raw IANA zone', function (): void {
    // 17:00 UTC → 10:00 AM PDT (PT) and 1:00 PM EDT (ET).
    $hr = Industry::factory()->create(['slug' => 'hr', 'is_active' => true]);
    $webinar = publicWebinar($hr, 'Dual-Zone Session', [
        'scheduled_at' => Illuminate\Support\Carbon::parse('2025-08-22 17:00:00', 'UTC'),
        'timezone' => 'America/Los_Angeles',
    ]);

    expect($webinar->scheduledTimeLabel())->toBe('10:00 AM PT / 1:00 PM ET')
        ->and($webinar->scheduledDateLabel())->toBe('Aug 22, 2025');

    $this->get(route('webinars.index'))
        ->assertSee('10:00 AM PT / 1:00 PM ET')
        ->assertDontSee('AMERICA/LOS_ANGELES', escape: false);
});

it('links the homepage category cards to their industry pages', function (): void {
    $html = Blade::render('<x-home.categories :categories="$categories" />', [
        'categories' => [
            ['name' => 'Human Resources', 'slug' => 'human-resources', 'count' => 12, 'image' => 'seminar'],
        ],
    ]);

    expect($html)->toContain(route('industries.show', 'human-resources'))
        ->and($html)->not->toContain('showNotice');
});

it('presets the topic on an industry page', function (): void {
    $hr = Industry::factory()->create(['name' => 'Human Resources', 'slug' => 'human-resources', 'is_active' => true]);
    $payroll = Industry::factory()->create(['slug' => 'payroll-tax', 'is_active' => true]);
    publicWebinar($hr, 'HR Only Session');
    publicWebinar($payroll, 'Payroll Only Session');

    Livewire::test(WebinarCatalogue::class, ['industrySlug' => 'human-resources'])
        ->assertSet('topics', ['human-resources'])
        ->assertSee('HR Only Session')
        ->assertDontSee('Payroll Only Session');
});
