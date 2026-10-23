<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

/*
 * The staff bar on the public site — a signed-in admin can jump back to the
 * panel. Only active staff see it; customers (no §8 role) and a deactivated
 * leaver, who can no longer reach the panel, do not.
 */

it('shows the admin bar to active staff on the public site', function (): void {
    $this->seed(RolePermissionSeeder::class);
    $staff = User::factory()->create();
    $staff->assignRole(UserRole::SuperAdmin->value);

    $this->actingAs($staff)->get('/webinars')
        ->assertSuccessful()
        ->assertSee('Admin view')
        ->assertSee('Admin dashboard');
});

it('hides the admin bar from customers', function (): void {
    $this->actingAs(User::factory()->create())
        ->get('/webinars')
        ->assertSuccessful()
        ->assertDontSee('Admin view');
});

it('hides the admin bar from guests', function (): void {
    $this->get('/webinars')
        ->assertSuccessful()
        ->assertDontSee('Admin view');
});

it('hides the admin bar from a deactivated staff member', function (): void {
    $this->seed(RolePermissionSeeder::class);
    $staff = User::factory()->create(['deactivated_at' => now()]);
    $staff->assignRole(UserRole::Finance->value);

    $this->actingAs($staff)->get('/webinars')
        ->assertSuccessful()
        ->assertDontSee('Admin view');
});
