<?php

declare(strict_types=1);

use App\Enums\CorePageKey;
use App\Enums\UserRole;
use App\Filament\Resources\CorePages\CorePageResource;
use App\Filament\Resources\CorePages\Pages\EditCorePage;
use App\Filament\Resources\CorePages\Pages\ListCorePages;
use App\Models\CorePage;
use Database\Seeders\CorePageSeeder;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->seed(CorePageSeeder::class);
});

it('lists the four fixed core pages for an admin', function (): void {
    $this->actingAs(userWithRole(UserRole::SuperAdmin));

    Livewire::test(ListCorePages::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords(CorePage::all());
});

it('is a fixed set — never creatable or deletable', function (): void {
    expect(CorePageResource::canCreate())->toBeFalse()
        ->and(CorePageResource::canDelete(CorePage::first()))->toBeFalse()
        ->and(array_keys(CorePageResource::getPages()))->toBe(['index', 'edit']); // no create page
});

it('edits a core page hero', function (): void {
    $this->actingAs(userWithRole(UserRole::SuperAdmin));
    $page = CorePage::for(CorePageKey::Speakers);

    Livewire::test(EditCorePage::class, ['record' => $page->getKey()])
        ->assertSuccessful()
        ->fillForm([
            'hero_eyebrow' => 'OUR EXPERTS',
            'hero_title' => 'Meet the faculty',
            'hero_subtitle' => 'Practitioners, not presenters.',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($page->fresh())
        ->hero_title->toBe('Meet the faculty')
        ->hero_eyebrow->toBe('OUR EXPERTS');
});

it('saves the membership CTA on the membership page', function (): void {
    $this->actingAs(userWithRole(UserRole::SuperAdmin));
    $page = CorePage::for(CorePageKey::Membership);

    Livewire::test(EditCorePage::class, ['record' => $page->getKey()])
        ->fillForm(['cta_label' => 'Explore memberships', 'cta_url' => '/membership'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($page->fresh())
        ->ctaLabel()->toBe('Explore memberships')
        ->ctaUrl()->toBe('/membership');
});

it('lets a content editor in and keeps the unauthorised out', function (): void {
    // No authenticated user.
    expect(CorePageResource::canViewAny())->toBeFalse();

    $this->actingAs(userWithRole(UserRole::ContentEditor));
    expect(CorePageResource::canViewAny())->toBeTrue()
        ->and(CorePageResource::canEdit(CorePage::first()))->toBeTrue();

    // Finance has no content access.
    $this->actingAs(userWithRole(UserRole::Finance));
    expect(CorePageResource::canViewAny())->toBeFalse();
});
