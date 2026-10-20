<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

/*
 * The header "account" link must send each user to a panel they can open — the
 * customer panel refuses staff (canAccessPanel), which was showing as a 403.
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

it('links a staff member to the admin panel, not the customer area', function (): void {
    $this->actingAs(userWithRole(UserRole::SuperAdmin))
        ->get('/')
        ->assertSuccessful()
        ->assertSee('Admin')
        ->assertDontSee(route('filament.account.pages.upcoming-webinars'));
});
