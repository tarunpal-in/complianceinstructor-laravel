<?php

declare(strict_types=1);

use App\Enums\CorePageKey;
use App\Models\CorePage;
use Database\Seeders\CorePageSeeder;

/* Phase 3 — the shared hero and copy blocks, wired into Speakers and Membership. */

beforeEach(fn () => $this->seed(CorePageSeeder::class));

it('renders the speakers hero from the editable core page', function (): void {
    $this->get('/speakers')
        ->assertSuccessful()
        ->assertSee('Learn from the Best in Compliance', escape: false)
        ->assertSee('JOIN OUR FACULTY', escape: false);
});

it('reflects an edited hero on the public page', function (): void {
    CorePage::for(CorePageKey::Speakers)->update([
        'hero_eyebrow' => 'OUR FACULTY',
        'hero_title' => 'Meet the practitioners',
        'hero_subtitle' => 'Experts who do the work.',
    ]);

    $this->get('/speakers')
        ->assertSee('Meet the practitioners', escape: false)
        ->assertSee('OUR FACULTY', escape: false)
        ->assertSee('Experts who do the work.', escape: false)
        ->assertDontSee('Learn from the Best in Compliance', escape: false);
});

it('renders visible copy blocks and hides invisible ones', function (): void {
    CorePage::for(CorePageKey::Membership)->sections()->createMany([
        ['heading' => 'Why members save', 'body' => '<p>Shown copy block.</p>', 'sort_order' => 0, 'is_visible' => true],
        ['heading' => 'Hidden block', 'body' => '<p>Hidden copy.</p>', 'sort_order' => 1, 'is_visible' => false],
    ]);

    $this->get('/membership')
        ->assertSuccessful()
        ->assertSee('Why members save')
        ->assertSee('Shown copy block.')
        ->assertDontSee('Hidden copy.');
});

it('sanitises dangerous markup out of a copy block', function (): void {
    CorePage::for(CorePageKey::Membership)->sections()->create([
        'heading' => 'Safe',
        'body' => '<p>Keep this.</p><script>alert(1)</script>',
        'sort_order' => 0,
        'is_visible' => true,
    ]);

    $this->get('/membership')
        ->assertSee('Keep this.')
        ->assertDontSee('<script>alert(1)</script>', escape: false);
});

it('drives the page SEO title from the core page hero', function (): void {
    CorePage::for(CorePageKey::Membership)->update(['hero_title' => 'Team memberships']);

    $this->get('/membership')
        ->assertSuccessful()
        ->assertSee('<title>Team memberships', escape: false);
});

it('lists the four core pages in the sitemap', function (): void {
    $xml = $this->get('/sitemap.xml')->assertSuccessful()->getContent();

    expect($xml)->toContain('/speakers')
        ->toContain('/membership')
        ->toContain('/industries')
        ->toContain('/contact');
});

it('drives the Request-a-Callback button on the membership page from the core page', function (): void {
    // Blank by default: the default label as a "we'll be in touch" button, no link.
    $this->get('/membership')
        ->assertSuccessful()
        ->assertSee('Request a Callback')
        ->assertSee('We will be in touch soon');

    // Setting the label and link turns it into a hyperlink.
    CorePage::for(CorePageKey::Membership)->update(['cta_label' => 'Talk to our team', 'cta_url' => '/contact']);

    $this->get('/membership')
        ->assertSee('Talk to our team')
        ->assertSee('class="callback-btn" href="/contact"', escape: false);
});
