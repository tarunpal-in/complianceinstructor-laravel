<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

/**
 * CLAUDE.md §10 — authorization is tested exhaustively: one test per policy per
 * role. The failure mode here is a breach, and the predecessor shipped exactly
 * this bug (§9: routes declared outside the middleware group).
 *
 * Filament lets every authenticated user into a panel unless the User model
 * implements FilamentUser::canAccessPanel(). These tests are what stop that
 * method being deleted or weakened without anyone noticing.
 */
beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

it('redirects guests to the admin login', function (): void {
    $this->get('/admin')->assertRedirect('/admin/login');
});

it('denies a customer with no admin role', function (): void {
    $customer = User::factory()->create();

    $this->actingAs($customer)->get('/admin')->assertForbidden();
});

it('allows each admin role in', function (UserRole $role): void {
    $user = User::factory()->create();
    $user->assignRole($role->value);

    $this->actingAs($user)->get('/admin')->assertSuccessful();
})->with(UserRole::cases());

it('does not let a customer become an admin by having permissions alone', function (): void {
    $customer = User::factory()->create();
    $customer->givePermissionTo('view_any_order');

    // Panel access is role-based; a stray permission grant must not open /admin.
    $this->actingAs($customer)->get('/admin')->assertForbidden();
});
