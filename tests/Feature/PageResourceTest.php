<?php

declare(strict_types=1);

use App\Enums\PageStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Pages\Pages\CreatePage;
use App\Filament\Resources\Pages\Pages\EditPage;
use App\Filament\Resources\Pages\Pages\ListPages;
use App\Models\Page;
use App\Models\PageSection;
use Database\Seeders\PageSeeder;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

/** The Builder field's shape for one block. */
function block(string $type, array $data = [], bool $visible = true): array
{
    return ['type' => $type, 'data' => [...$data, 'is_visible' => $visible]];
}

// Authorization ---------------------------------------------------------------

it('opens pages to roles that may edit content', function (UserRole $role): void {
    $this->actingAs(userWithRole($role))
        ->get('/admin/pages')
        ->assertSuccessful();
})->with([UserRole::SuperAdmin, UserRole::ContentEditor]);

/** Marketing copy is not Finance's or Support's to change. */
it('keeps roles without a page permission out', function (UserRole $role): void {
    $this->actingAs(userWithRole($role))
        ->get('/admin/pages')
        ->assertForbidden();
})->with([UserRole::Finance, UserRole::Support]);

// Building a page ---------------------------------------------------------------

/**
 * §2's second leverage decision, in one test: a page is assembled from blocks,
 * and adding a section costs no model, table, controller or route (§14 #14).
 */
it('builds a page out of blocks', function (): void {
    $this->actingAs(userWithRole(UserRole::ContentEditor));

    Livewire\Livewire::test(CreatePage::class)
        ->fillForm([
            'title' => 'About Us',
            'slug' => 'about',
            'status' => PageStatus::Draft->value,
            'layout' => 'standard',
            'sections' => [
                block('hero', ['heading' => 'Training that holds up']),
                block('rich_text', ['body' => '<p>Some copy.</p>']),
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $page = Page::query()->where('slug', 'about')->firstOrFail();

    expect($page->sections->pluck('type')->all())->toBe(['hero', 'rich_text'])
        // Order is stored, so dragging a block changes the page.
        ->and($page->sections->pluck('sort_order')->all())->toBe([0, 1])
        ->and($page->sections->first()?->value('heading'))->toBe('Training that holds up');
});

/** §5.6 asks for rows rather than a JSON column, so a block can be asked about. */
it('stores blocks as rows that can be queried', function (): void {
    Page::factory()->withContent('faq', ['heading' => 'Questions'])->create();
    Page::factory()->withContent('hero', ['heading' => 'Hello'])->create();

    expect(PageSection::query()->where('type', 'faq')->count())->toBe(1);
});

/** Visibility lives on the row, not inside the block's data. */
it('lifts a block\'s visibility onto its row', function (): void {
    $this->actingAs(userWithRole(UserRole::ContentEditor));

    Livewire\Livewire::test(CreatePage::class)
        ->fillForm([
            'title' => 'Mixed',
            'slug' => 'mixed',
            'status' => PageStatus::Draft->value,
            'layout' => 'standard',
            'sections' => [
                block('rich_text', ['body' => '<p>Shown.</p>']),
                block('rich_text', ['body' => '<p>Hidden.</p>'], visible: false),
            ],
        ])
        ->call('create');

    $page = Page::query()->where('slug', 'mixed')->firstOrFail();

    expect($page->sections->pluck('is_visible')->all())->toBe([true, false])
        // And out of the data, where it would be invisible to a query.
        ->and($page->sections->first()?->data)->not->toHaveKey('is_visible')
        ->and($page->describeContent())->toBe('2 blocks · 1 hidden');
});

it('loads a page back into the builder to be edited', function (): void {
    $this->actingAs(userWithRole(UserRole::ContentEditor));

    $page = Page::factory()->withContent('rich_text', ['body' => '<p>Original.</p>'])->create();

    Livewire\Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->assertSuccessful()
        ->fillForm(['sections' => [block('rich_text', ['body' => '<p>Rewritten.</p>'])]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($page->fresh()->sections->first()?->value('body'))->toBe('<p>Rewritten.</p>')
        // Replaced, not stacked.
        ->and($page->fresh()->sections)->toHaveCount(1);
});

// Addresses -----------------------------------------------------------------------

/** A page cannot take a path the application already answers on. */
it('refuses a slug the site already uses', function (string $slug): void {
    $this->actingAs(userWithRole(UserRole::ContentEditor));

    Livewire\Livewire::test(CreatePage::class)
        ->fillForm([
            'title' => 'Sneaky',
            'slug' => $slug,
            'status' => PageStatus::Draft->value,
            'layout' => 'standard',
            'sections' => [block('rich_text', ['body' => '<p>x</p>'])],
        ])
        ->call('create')
        ->assertHasFormErrors(['slug']);
})->with(['admin', 'login', 'webinars', 'horizon']);

/** And the same auto-resolve the rest of the site's slugs get. */
it('numbers a slug another page already holds', function (): void {
    $this->actingAs(userWithRole(UserRole::ContentEditor));

    Page::factory()->create(['slug' => 'about']);

    Livewire\Livewire::test(CreatePage::class)
        ->fillForm([
            'title' => 'About',
            'slug' => 'about',
            'status' => PageStatus::Draft->value,
            'layout' => 'standard',
            'sections' => [block('rich_text', ['body' => '<p>x</p>'])],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Page::query()->where('slug', 'about-2')->exists())->toBeTrue();
});

// Publishing ------------------------------------------------------------------------

/** A page with nothing on it publishes as a blank screen. Refused. */
it('will not publish an empty page', function (): void {
    $this->actingAs(userWithRole(UserRole::ContentEditor));

    $page = Page::factory()->create();

    Livewire\Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->callAction('publish');

    expect($page->fresh()?->isLive())->toBeFalse()
        ->and($page->fresh()?->problems())->toContain('It has no visible content blocks, so it would publish as an empty page.');
});

it('will not publish a page whose only block is hidden', function (): void {
    $page = Page::factory()->create();
    PageSection::factory()->hidden()->create(['page_id' => $page->id]);

    expect($page->fresh()->isPublishable())->toBeFalse();
});

it('publishes a page that has something on it', function (): void {
    $this->actingAs(userWithRole(UserRole::ContentEditor));

    $page = Page::factory()->withContent()->create();

    Livewire\Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->callAction('publish');

    expect($page->fresh()?->isLive())->toBeTrue();
});

it('takes a page down without losing it', function (): void {
    $this->actingAs(userWithRole(UserRole::ContentEditor));

    $page = Page::factory()->withContent()->published()->create();
    $firstPublished = $page->published_at;

    Livewire\Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->callAction('unpublish');

    $page = $page->fresh();

    expect($page?->isLive())->toBeFalse()
        ->and($page?->sections)->toHaveCount(1)
        // The date it first went live survives, so a page put back does not
        // claim to be new.
        ->and($page?->published_at?->toDateTimeString())->toBe($firstPublished?->toDateTimeString());
});

/** Published with a future date is scheduled, whatever the column says. */
it('reads a future date as scheduled', function (): void {
    $scheduled = Page::factory()->withContent()->scheduled()->create();

    expect($scheduled->derivedStatus())->toBe(PageStatus::Scheduled)
        ->and($scheduled->isLive())->toBeFalse()
        ->and(Page::query()->live()->count())->toBe(0);
});

it('separates live pages from drafts and the unfinished', function (): void {
    $live = Page::factory()->withContent()->published()->create();
    $draft = Page::factory()->withContent()->create();
    $empty = Page::factory()->create();

    Livewire\Livewire::actingAs(userWithRole(UserRole::ContentEditor))
        ->test(ListPages::class)
        ->set('activeTab', 'live')
        ->assertCanSeeTableRecords([$live])
        ->assertCanNotSeeTableRecords([$draft, $empty])
        ->set('activeTab', 'empty')
        ->assertCanSeeTableRecords([$empty])
        ->assertCanNotSeeTableRecords([$live, $draft]);
});

/**
 * The View button points at the page itself — the only real preview there is.
 * Shown on every row and disabled with the reason where there is nothing to
 * open, rather than vanishing and reading as a missing feature.
 */
it('offers a view button that opens a live page', function (): void {
    $this->actingAs(userWithRole(UserRole::ContentEditor));

    $live = Page::factory()->withContent()->published()->create(['slug' => 'about']);

    Livewire\Livewire::test(ListPages::class)
        ->assertTableActionEnabled('visit', $live)
        ->assertTableActionHasUrl('visit', '/about', $live);
});

it('points the view button at a preview when the page is not live', function (string $state): void {
    $this->actingAs(userWithRole(UserRole::ContentEditor));

    $page = Page::factory()->withContent()->{$state}()->create();

    Livewire\Livewire::test(ListPages::class)
        ->set('activeTab', 'all')
        ->assertTableActionEnabled('visit', $page)
        ->assertTableActionHasUrl('visit', $page->previewUrl(), $page);
})->with(['draft' => 'withContent', 'scheduled' => 'scheduled']);

// Previewing a draft -------------------------------------------------------------------

/**
 * Writing a page you cannot look at until you publish it is backwards, and
 * publishing to check means the client sees the half-finished version.
 */
it('shows a draft through a signed link', function (): void {
    $page = Page::factory()
        ->withContent('rich_text', ['body' => '<p>Not finished yet.</p>'])
        ->create(['slug' => 'about', 'title' => 'About Us']);

    // Not reachable at its own address.
    $this->get('/about')->assertNotFound();

    $this->get($page->previewUrl())
        ->assertSuccessful()
        ->assertSee('Not finished yet.', escape: false)
        // Loud, because somebody sent this link has no other way to tell.
        ->assertSee('Preview', escape: false)
        ->assertSee('has not been published', escape: false);
});

/** §9 — signed and expiring, never a guessable path. */
it('refuses a preview link that was not signed', function (): void {
    $page = Page::factory()->withContent()->create();

    $this->get('/preview/'.$page->getKey())->assertForbidden();
});

it('refuses a preview link somebody edited', function (): void {
    $page = Page::factory()->withContent()->create();
    $other = Page::factory()->withContent()->create();

    // Swapping the id invalidates the signature, which is the point of signing
    // the whole URL rather than trusting a parameter.
    $tampered = str_replace('/preview/'.$page->getKey(), '/preview/'.$other->getKey(), $page->previewUrl());

    $this->get($tampered)->assertForbidden();
});

it('refuses a preview link that has run out', function (): void {
    $page = Page::factory()->withContent()->create();
    $link = $page->previewUrl();

    $this->travel(Page::PREVIEW_LIFETIME_DAYS + 1)->days();

    $this->get($link)->assertForbidden();
});

/** A preview must show what publishing would show, or it decides nothing. */
it('leaves hidden blocks out of a preview too', function (): void {
    $page = Page::factory()->create(['slug' => 'partial-draft']);

    PageSection::factory()->at(0)->create(['page_id' => $page->id, 'data' => ['body' => '<p>Visible copy.</p>']]);
    PageSection::factory()->at(1)->hidden()->create(['page_id' => $page->id, 'data' => ['body' => '<p>Hidden copy.</p>']]);

    $this->get($page->previewUrl())
        ->assertSee('Visible copy.', escape: false)
        ->assertDontSee('Hidden copy.', escape: false);
});

it('says when a scheduled page goes live', function (): void {
    $page = Page::factory()->withContent()->scheduled()->create();

    $this->get($page->previewUrl())
        ->assertSuccessful()
        ->assertSee('goes live on', escape: false);
});

/** A published page carries no banner — it is the real thing. */
it('shows no preview banner on a live page', function (): void {
    Page::factory()->withContent()->published()->create(['slug' => 'about']);

    $this->get('/about')->assertDontSee('page-preview-banner', escape: false);
});

/** `preview` cannot be taken as a slug, or the route would be ambiguous. */
it('reserves the preview path', function (): void {
    expect(Page::RESERVED_SLUGS)->toContain('preview');
});

// The public page ---------------------------------------------------------------------

it('serves a published page at its address', function (): void {
    Page::factory()
        ->withContent('rich_text', ['heading' => 'About Us', 'body' => '<p>Who we are.</p>'])
        ->published()
        ->create(['slug' => 'about', 'title' => 'About Us']);

    $this->get('/about')
        ->assertSuccessful()
        ->assertSee('About Us', escape: false)
        ->assertSee('Who we are.', escape: false);
});

/** A draft and a page that never existed look the same to a visitor. */
it('hides a page that is not live', function (string $state): void {
    Page::factory()->withContent()->{$state}()->create(['slug' => 'secret']);

    $this->get('/secret')->assertNotFound();
})->with(['draft' => 'withContent', 'scheduled' => 'scheduled']);

it('leaves hidden blocks off the page', function (): void {
    $page = Page::factory()->published()->create(['slug' => 'partial']);

    PageSection::factory()->at(0)->create([
        'page_id' => $page->id,
        'data' => ['body' => '<p>Visible copy.</p>'],
    ]);

    PageSection::factory()->at(1)->hidden()->create([
        'page_id' => $page->id,
        'data' => ['body' => '<p>Hidden copy.</p>'],
    ]);

    $this->get('/partial')
        ->assertSee('Visible copy.', escape: false)
        ->assertDontSee('Hidden copy.', escape: false);
});

/**
 * A block type removed from the code must not take a live page down with it.
 */
it('skips a block type it no longer knows how to draw', function (): void {
    $page = Page::factory()->published()->create(['slug' => 'legacy']);

    PageSection::factory()->at(0)->type('testimonial_slider', ['quote' => 'Gone.'])->create(['page_id' => $page->id]);
    PageSection::factory()->at(1)->create(['page_id' => $page->id, 'data' => ['body' => '<p>Still here.</p>']]);

    $this->get('/legacy')
        ->assertSuccessful()
        ->assertSee('Still here.', escape: false);
});

/** The catch-all is last, so nothing above it is swallowed. */
it('does not swallow the routes that come before it', function (): void {
    Page::factory()->withContent()->published()->create(['slug' => 'about']);

    $this->get('/')->assertSuccessful();
    $this->get('/admin')->assertRedirect();
});

it('renders every block type without falling over', function (string $type, array $data): void {
    $page = Page::factory()->published()->create(['slug' => 'kitchen-sink']);

    PageSection::factory()->type($type, $data)->create(['page_id' => $page->id]);

    $this->get('/kitchen-sink')->assertSuccessful();
})->with([
    'rich text' => ['rich_text', ['body' => '<p>Copy.</p>']],
    'hero' => ['hero', ['heading' => 'Hello', 'button_label' => 'Go', 'button_url' => '/webinars']],
    'feature grid' => ['feature_grid', ['heading' => 'Why us', 'features' => [['title' => 'One', 'body' => 'First']]]],
    'faq' => ['faq', ['heading' => 'Questions', 'questions' => [['question' => 'Why?', 'answer' => 'Because.']]]],
    'call to action' => ['cta', ['heading' => 'Start', 'button_label' => 'Go', 'button_url' => '/webinars']],
    'statistics' => ['stat_band', ['stats' => [['value' => '48,000+', 'label' => 'Trained']]]],
]);

// Seed data is a deliverable (§5.9) --------------------------------------------------

it('seeds pages that exercise the block library', function (): void {
    $this->seed(PageSeeder::class);

    $types = PageSection::query()->pluck('type')->unique();

    expect(Page::query()->count())->toBe(2)
        ->and($types)->toContain('hero', 'stat_band', 'feature_grid', 'cta', 'rich_text', 'faq');

    // And the published one actually renders.
    $this->get('/about')->assertSuccessful();
});

it('seeds pages idempotently', function (): void {
    $this->seed(PageSeeder::class);
    $this->seed(PageSeeder::class);

    expect(Page::query()->count())->toBe(2)
        ->and(PageSection::query()->count())->toBe(6);
});
