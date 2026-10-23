<?php

declare(strict_types=1);

use App\Enums\CorePageKey;
use App\Models\CorePage;
use App\Models\Industry;
use App\Support\Navigation;
use Database\Seeders\CorePageSeeder;

/* Phase 4 — the new industries listing page and the repointed nav. */

beforeEach(fn () => $this->seed(CorePageSeeder::class));

it('renders the listing with the editable hero and only active industries', function (): void {
    Industry::factory()->create(['name' => 'Human Resources', 'slug' => 'human-resources', 'is_active' => true]);
    Industry::factory()->create(['name' => 'Retired Topic', 'slug' => 'retired', 'is_active' => false]);

    $this->get('/industries')
        ->assertSuccessful()
        ->assertSee('Compliance training for your field', escape: false)       // seeded hero title
        ->assertSee('Human Resources')
        ->assertSee(route('industries.show', 'human-resources'), escape: false) // card links to the landing
        ->assertDontSee('Retired Topic');                                       // inactive excluded
});

it('reflects an edited industries hero', function (): void {
    CorePage::for(CorePageKey::Industries)->update(['hero_title' => 'Browse every field']);

    $this->get('/industries')
        ->assertSee('Browse every field', escape: false)
        ->assertDontSee('Compliance training for your field', escape: false);
});

it('shows the industries copy blocks', function (): void {
    CorePage::for(CorePageKey::Industries)->sections()->create([
        'heading' => 'Why industry pages',
        'body' => '<p>Credits tailored to your field.</p>',
        'sort_order' => 0,
        'is_visible' => true,
    ]);

    $this->get('/industries')
        ->assertSee('Why industry pages')
        ->assertSee('Credits tailored to your field.');
});

it('points the Industry nav item at the listing by default', function (): void {
    $item = collect(Navigation::defaultHeader())->firstWhere('label', 'Industry');

    expect($item['url'])->toBe('/industries');
});
