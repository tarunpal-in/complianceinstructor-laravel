<?php

declare(strict_types=1);

use App\Contracts\Seoable;
use App\Enums\CorePageKey;
use App\Models\CorePage;
use App\Models\CorePageSection;
use Database\Seeders\CorePageSeeder;

/* Phase 1 — the core-pages foundation: schema, model, SEO contract and seeder. */

it('seeds the four fixed core pages', function (): void {
    $this->seed(CorePageSeeder::class);

    expect(CorePage::query()->count())->toBe(4)
        ->and(CorePage::query()->pluck('key')->map(fn (CorePageKey $k): string => $k->value)->sort()->values()->all())
        ->toBe(['contact', 'industries', 'membership', 'speakers']);
});

it('does not overwrite an edited hero when re-seeded', function (): void {
    $this->seed(CorePageSeeder::class);

    CorePage::for(CorePageKey::Speakers)->update(['hero_title' => 'Our hand-picked experts']);

    $this->seed(CorePageSeeder::class); // a second deploy must not clobber the edit

    expect(CorePage::query()->count())->toBe(4)
        ->and(CorePage::for(CorePageKey::Speakers)->hero_title)->toBe('Our hand-picked experts');
});

it('resolves a page by key with its key cast to the enum', function (): void {
    $this->seed(CorePageSeeder::class);

    $membership = CorePage::for(CorePageKey::Membership);

    expect($membership)->not->toBeNull()
        ->and($membership->key)->toBe(CorePageKey::Membership)
        ->and(CorePage::for('membership')->is($membership))->toBeTrue();
});

it('falls the membership CTA back to sensible defaults, and honours overrides', function (): void {
    $withCta = CorePage::factory()->forKey(CorePageKey::Membership)
        ->create(['cta_label' => 'Join now', 'cta_url' => '/pricing']);
    $blank = CorePage::factory()->forKey(CorePageKey::Speakers)
        ->create(['cta_label' => null, 'cta_url' => null]);

    expect($withCta->ctaLabel())->toBe('Join now')
        ->and($withCta->ctaUrl())->toBe('/pricing')
        ->and($blank->ctaLabel('View memberships'))->toBe('View memberships')
        ->and($blank->ctaUrl())->toBe('/speakers'); // the key's own path
});

it('derives SEO defaults from the hero and is a Seoable', function (): void {
    $page = CorePage::factory()->forKey(CorePageKey::Contact)->create([
        'hero_title' => 'Get in touch',
        'hero_subtitle' => 'We reply within a day.',
        'hero_media_id' => null,
    ]);

    expect($page)->toBeInstanceOf(Seoable::class)
        ->and($page->defaultSeoTitle())->toBe('Get in touch')
        ->and($page->defaultSeoDescription())->toBe('We reply within a day.')
        ->and($page->defaultSeoOgImageUrl())->toBeNull()   // no hero image picked
        ->and($page->heroImageUrl())->toBeNull();
});

it('holds ordered, visibility-toggled copy blocks that cascade on delete', function (): void {
    $page = CorePage::factory()->forKey(CorePageKey::Industries)->create();

    $page->sections()->createMany([
        ['heading' => 'Second', 'body' => 'b', 'sort_order' => 1, 'is_visible' => true],
        ['heading' => null, 'body' => 'first body', 'sort_order' => 0, 'is_visible' => false],
    ]);

    $sections = $page->fresh()->sections;
    expect($sections->pluck('heading')->all())->toBe([null, 'Second'])   // ordered by sort_order
        ->and($sections->first()->hasHeading())->toBeFalse();

    $page->delete();
    expect(CorePageSection::query()->count())->toBe(0); // cascaded
});
