<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

/*
 * The header "My Account" link goes to the learner dashboard for everyone now —
 * customers and staff alike can open it (2026-10-22). Staff reach the admin panel
 * from the staff bar at the top of the page, not this link.
 */

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

it('links a customer to their account area', function (): void {
    $this->actingAs(User::factory()->create())
        ->get('/')
        ->assertSuccessful()
        ->assertSee('My Account')
        ->assertSee(route('filament.account.pages.upcoming-webinars'));
});

it('links a staff member to the learner dashboard too, with admin on the staff bar', function (): void {
    $this->actingAs(userWithRole(UserRole::SuperAdmin))
        ->get('/')
        ->assertSuccessful()
        // The navbar button is the learner dashboard for staff as well…
        ->assertSee('My Account')
        ->assertSee(route('filament.account.pages.upcoming-webinars'))
        // …and the admin panel is reachable from the staff bar at the top.
        ->assertSee('Admin dashboard');
});
