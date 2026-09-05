<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Enums\WebinarStatus;
use App\Filament\Resources\Webinars\Pages\EditWebinar;
use App\Models\Accreditation;
use App\Models\Offering;
use App\Models\Webinar;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

it('opens the webinar list to roles that may read it', function (UserRole $role): void {
    $this->actingAs(userWithRole($role))
        ->get('/admin/webinars')
        ->assertSuccessful();
})->with([UserRole::SuperAdmin, UserRole::ContentEditor, UserRole::Finance]);

/**
 * Support can read webinars — resolving "why can this learner not see their
 * recording" means looking one up — but must not be able to change what is
 * sold or when it runs.
 */
it('lets Support read a webinar but not create one', function (): void {
    $this->actingAs(userWithRole(UserRole::Support))
        ->get('/admin/webinars')
        ->assertSuccessful();

    $this->actingAs(userWithRole(UserRole::Support))
        ->get('/admin/webinars/create')
        ->assertForbidden();
});

it('lets Finance read but not create a webinar', function (): void {
    $this->actingAs(userWithRole(UserRole::Finance))
        ->get('/admin/webinars/create')
        ->assertForbidden();
});

/**
 * The list eager-loads speaker and industry and counts offerings and
 * accreditations. Model::shouldBeStrict() turns a lazy load into an exception,
 * so this fails loudly if the with()/withCount() is ever dropped.
 */
it('renders the list without lazy loading relations', function (): void {
    Webinar::factory()->count(3)->create();

    $this->actingAs(userWithRole(UserRole::SuperAdmin))
        ->get('/admin/webinars')
        ->assertSuccessful();
});

// The publish gate ----------------------------------------------------------

it('refuses to publish a webinar with nothing to buy', function (): void {
    $webinar = Webinar::factory()->create();
    $webinar->accreditations()->attach(Accreditation::factory()->create(), ['credits' => 1.5]);

    Livewire\Livewire::actingAs(userWithRole(UserRole::SuperAdmin))
        ->test(EditWebinar::class, ['record' => $webinar->getRouteKey()])
        ->callAction('publish');

    expect($webinar->refresh()->status)->toBe(WebinarStatus::Draft);
});

it('refuses to publish a recorded webinar selling the live format', function (): void {
    $webinar = Webinar::factory()->recorded()->create();
    Offering::factory()->live()->for($webinar, 'offerable')->create();
    $webinar->accreditations()->attach(Accreditation::factory()->create(), ['credits' => 1]);

    Livewire\Livewire::actingAs(userWithRole(UserRole::SuperAdmin))
        ->test(EditWebinar::class, ['record' => $webinar->getRouteKey()])
        ->callAction('publish');

    expect($webinar->refresh()->status)->toBe(WebinarStatus::Draft);
});

it('publishes a webinar that is ready', function (): void {
    $webinar = Webinar::factory()->create();
    Offering::factory()->everything()->for($webinar, 'offerable')->create();
    $webinar->accreditations()->attach(Accreditation::factory()->create(), ['credits' => 1.5]);

    Livewire\Livewire::actingAs(userWithRole(UserRole::SuperAdmin))
        ->test(EditWebinar::class, ['record' => $webinar->getRouteKey()])
        ->callAction('publish');

    expect($webinar->refresh()->status)->toBe(WebinarStatus::Scheduled);
});

// Duplicate -----------------------------------------------------------------

/**
 * Webinars repeat several times a year. The copy must carry the content,
 * offerings and credits — and must arrive as a dateless draft so it can never
 * be mistaken for the original or sold on the wrong schedule.
 */
it('duplicates a webinar with its offerings and credits', function (): void {
    $webinar = Webinar::factory()->create(['title' => 'FMLA Compliance 2026']);
    Offering::factory()->everything()->for($webinar, 'offerable')->create();
    Offering::factory()->recorded()->for($webinar, 'offerable')->create();
    $webinar->accreditations()->attach(Accreditation::factory()->create(), ['credits' => 2.5]);

    Livewire\Livewire::actingAs(userWithRole(UserRole::SuperAdmin))
        ->test(EditWebinar::class, ['record' => $webinar->getRouteKey()])
        ->callAction('duplicate');

    $copy = Webinar::query()->where('id', '!=', $webinar->id)->firstOrFail();

    expect($copy->title)->toBe('FMLA Compliance 2026 (copy)')
        ->and($copy->status)->toBe(WebinarStatus::Draft)
        ->and($copy->scheduled_at)->toBeNull()
        ->and($copy->slug)->not->toBe($webinar->slug)
        ->and($copy->offerings)->toHaveCount(2)
        ->and($copy->accreditations->first()->pivot->getAttribute('credits'))->toEqual(2.5);
});

/**
 * The bundle's three formats have to be rebuilt on the copy, not replicated as
 * one row — otherwise the duplicate sells a bundle that grants nothing.
 */
it('carries every format across when duplicating a bundle', function (): void {
    $webinar = Webinar::factory()->create();
    Offering::factory()->everything()->for($webinar, 'offerable')->create();
    $webinar->accreditations()->attach(Accreditation::factory()->create(), ['credits' => 1]);

    Livewire\Livewire::actingAs(userWithRole(UserRole::SuperAdmin))
        ->test(EditWebinar::class, ['record' => $webinar->getRouteKey()])
        ->callAction('duplicate');

    $copy = Webinar::query()->where('id', '!=', $webinar->id)->firstOrFail();

    expect($copy->offerings->first()->formatEnums())->toHaveCount(3);
});

it('gives the copy its own SKU', function (): void {
    $webinar = Webinar::factory()->create();
    $original = Offering::factory()->recorded()->for($webinar, 'offerable')->create();
    $webinar->accreditations()->attach(Accreditation::factory()->create(), ['credits' => 1]);

    Livewire\Livewire::actingAs(userWithRole(UserRole::SuperAdmin))
        ->test(EditWebinar::class, ['record' => $webinar->getRouteKey()])
        ->callAction('duplicate');

    $copy = Webinar::query()->where('id', '!=', $webinar->id)->firstOrFail();

    expect($copy->offerings->first()->sku)->not->toBe($original->sku);
});
