<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Accreditation;
use App\Models\Industry;
use App\Models\Speaker;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

/**
 * §8 — the sidebar, resource pages and individual actions all gate on named
 * permissions rather than on a role. Content Editor owns the catalogue; Finance
 * may read it but not change it; Support sees none of it.
 */
it('opens the catalogue lists to roles that may read them', function (string $path, UserRole $role): void {
    $this->actingAs(userWithRole($role))
        ->get($path)
        ->assertSuccessful();
})->with([
    '/admin/speakers',
    '/admin/industries',
    '/admin/accreditations',
])->with([UserRole::SuperAdmin, UserRole::ContentEditor]);

it('keeps the catalogue away from Support', function (string $path): void {
    $this->actingAs(userWithRole(UserRole::Support))
        ->get($path)
        ->assertForbidden();
})->with(['/admin/speakers', '/admin/industries', '/admin/accreditations']);

/**
 * Finance can read webinars and packages — enough to make sense of an order —
 * but holds no speaker or industry permissions at all. Least privilege: nothing
 * about a refund needs the speaker's biography.
 */
it('keeps speakers away from Finance', function (): void {
    $this->actingAs(userWithRole(UserRole::Finance))
        ->get('/admin/speakers')
        ->assertForbidden();
});

it('lets Content Editor create a speaker', function (): void {
    $this->actingAs(userWithRole(UserRole::ContentEditor))
        ->get('/admin/speakers/create')
        ->assertSuccessful();
});

it('lists existing records', function (): void {
    Speaker::factory()->count(3)->create();
    Industry::factory()->count(2)->create();
    Accreditation::factory()->create(['code' => 'HRCI']);

    $this->actingAs(userWithRole(UserRole::SuperAdmin));

    $this->get('/admin/speakers')->assertSuccessful();
    $this->get('/admin/industries')->assertSuccessful();
    $this->get('/admin/accreditations')->assertSee('HRCI');
});

/**
 * The lists count each record's webinars. Counted in the resource query rather
 * than per row: Model::shouldBeStrict() turns the N+1 into an exception, so a
 * missing withCount() fails loudly rather than quietly costing 25 queries.
 */
it('counts webinars without lazy loading', function (): void {
    $speaker = Speaker::factory()->create();
    App\Models\Webinar::factory()->count(2)->create(['speaker_id' => $speaker->id]);

    $this->actingAs(userWithRole(UserRole::SuperAdmin))
        ->get('/admin/speakers')
        ->assertSuccessful();

    expect(App\Filament\Resources\Speakers\SpeakerResource::getEloquentQuery()->first()->webinars_count)
        ->toBe(2);
});
