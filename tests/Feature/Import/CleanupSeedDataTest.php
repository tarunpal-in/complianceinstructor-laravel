<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\ImportMap;
use App\Models\Industry;
use App\Models\User;
use App\Models\Webinar;
use Database\Seeders\RolePermissionSeeder;

/*
 * The demo-seed cleanup — removes rows with no import-map entry, keeps imported
 * records, staff and infrastructure. Destructive, so its guard rails are tested.
 */

it('removes seeded rows and keeps imported ones, staff and the admin', function (): void {
    $this->seed(RolePermissionSeeder::class);

    // Imported (mapped) records — must survive.
    $importedWebinar = Webinar::factory()->create();
    ImportMap::remember('webinar', 100, $importedWebinar);
    $importedIndustry = Industry::factory()->create();
    ImportMap::remember('industry', 10, $importedIndustry);
    $importedUser = User::factory()->create();
    ImportMap::remember('user', 1, $importedUser);

    // Seeded demo records — must go.
    $demoWebinar = Webinar::factory()->create();
    $demoIndustry = Industry::factory()->create(['name' => 'Human Resources']);
    $demoCustomer = User::factory()->create();

    // Staff — no map, but a role, so kept.
    $admin = User::factory()->create();
    $admin->assignRole(UserRole::SuperAdmin->value);

    $this->artisan('migration:cleanup-seed', ['--execute' => true])->assertSuccessful();

    // Kept.
    expect(Webinar::query()->whereKey($importedWebinar->getKey())->exists())->toBeTrue()
        ->and(Industry::query()->whereKey($importedIndustry->getKey())->exists())->toBeTrue()
        ->and(User::query()->whereKey($importedUser->getKey())->exists())->toBeTrue()
        ->and(User::query()->whereKey($admin->getKey())->exists())->toBeTrue();

    // Removed (hard delete, not just soft).
    expect(Webinar::withTrashed()->whereKey($demoWebinar->getKey())->exists())->toBeFalse()
        ->and(Industry::withTrashed()->whereKey($demoIndustry->getKey())->exists())->toBeFalse()
        ->and(User::query()->whereKey($demoCustomer->getKey())->exists())->toBeFalse();
});

it('changes nothing without --execute', function (): void {
    $demoWebinar = Webinar::factory()->create();

    $this->artisan('migration:cleanup-seed')->assertSuccessful();

    expect(Webinar::query()->whereKey($demoWebinar->getKey())->exists())->toBeTrue();
});
