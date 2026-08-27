<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

/**
 * CLAUDE.md §8 — "Filament navigation, resource pages and individual actions
 * all gate on permissions." These assert the gating is by permission, not by
 * role: Content Editor is a full admin yet must not reach the user list.
 */
beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

function userWithRole(UserRole $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role->value);

    return $user;
}

it('shows the dashboard to an admin', function (): void {
    $this->actingAs(userWithRole(UserRole::SuperAdmin))
        ->get('/admin')
        ->assertSuccessful()
        ->assertSee('Compliance Instructor', escape: false);
});

it('lets roles holding view_any_user open the user list', function (UserRole $role): void {
    $this->actingAs(userWithRole($role))
        ->get('/admin/users')
        ->assertSuccessful();
})->with([UserRole::SuperAdmin, UserRole::Finance, UserRole::Support]);

it('denies the user list to Content Editor, who has no user permissions', function (): void {
    $this->actingAs(userWithRole(UserRole::ContentEditor))
        ->get('/admin/users')
        ->assertForbidden();
});

it('denies creating a user to Support, who may only read them', function (): void {
    $this->actingAs(userWithRole(UserRole::Support))
        ->get('/admin/users/create')
        ->assertForbidden();
});

it('allows Super Admin to create a user', function (): void {
    $this->actingAs(userWithRole(UserRole::SuperAdmin))
        ->get('/admin/users/create')
        ->assertSuccessful();
});

/**
 * The topbar layout is a requirement, not an accident of Filament's defaults:
 * theme switcher and global search in the topbar, avatar on the right. The
 * switcher in particular is only there because of a render hook, and the search
 * only appears because UserResource declares searchable attributes — both are
 * easy to remove by accident.
 */
it('renders the topbar chrome on the dashboard', function (): void {
    $response = $this->actingAs(userWithRole(UserRole::SuperAdmin))
        ->get('/admin')
        ->assertSuccessful();

    $html = $response->getContent();

    expect($html)
        ->toContain('fi-topbar')          // topbar itself
        ->toContain('fi-theme-switcher')  // light/dark, lifted out of the dropdown
        ->toContain('fi-global-search')   // search, centre
        ->toContain('fi-user-menu')       // avatar, right
        ->toContain('fi-sidebar');        // left navigation
});

it('exposes users to global search', function (): void {
    expect(App\Filament\Resources\Users\UserResource::getGloballySearchableAttributes())
        ->toBe(['name', 'email']);
});
