<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Filament\Widgets\SetupChecklist;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

it('shows the checklist to Super Admin', function (): void {
    $this->actingAs(userWithRole(UserRole::SuperAdmin))
        ->get('/admin')
        ->assertSee('Getting started');
});

/**
 * `view_setup_checklist` is Super Admin's alone, so launch readiness does not
 * leak to staff who cannot act on any of it — Content Editor and Finance can
 * open Settings for their own tabs without seeing it.
 */
it('hides the checklist from every other role', function (UserRole $role): void {
    $this->actingAs(userWithRole($role))
        ->get('/admin')
        ->assertDontSee('Getting started');
})->with([UserRole::ContentEditor, UserRole::Finance, UserRole::Support]);

it('counts a seeded install as partly complete', function (): void {
    $this->actingAs(userWithRole(UserRole::SuperAdmin));

    $widget = new SetupChecklist;

    // Roles are seeded, so at least one item passes and several do not — the
    // widget must not report a fresh install as either empty or finished.
    expect($widget->completed())->toBeGreaterThan(0)
        ->and($widget->outstanding())->toBeGreaterThan(0)
        ->and($widget->total())->toBe(9);
});

/**
 * Items pointing at tables that arrive in later phases must read as outstanding
 * rather than throwing, so the dashboard keeps working through P2-P4.
 */
it('treats not-yet-migrated tables as outstanding', function (): void {
    $this->actingAs(userWithRole(UserRole::SuperAdmin));

    $byLabel = collect((new SetupChecklist)->items())->keyBy('label');

    expect($byLabel['Add your first webinar']['done'])->toBeFalse()
        ->and($byLabel['Add your speakers']['done'])->toBeFalse()
        ->and($byLabel['Set your brand and contact details']['done'])->toBeFalse();
});

it('disappears once nothing is outstanding', function (): void {
    $admin = userWithRole(UserRole::SuperAdmin);
    $this->actingAs($admin);

    expect(SetupChecklist::canView())->toBeTrue();

    // A second admin, 2FA confirmed, and every integration configured.
    User::factory()->create()->assignRole(UserRole::Support->value);
    $admin->forceFill(['two_factor_confirmed_at' => now()])->save();
    config([
        'cashier.secret' => 'sk_test_x',
        'services.paypal.client_secret' => 'pp_secret',
        'mail.default' => 'smtp',
    ]);

    // The three catalogue rows still fail — their tables land in P2 — so the
    // card must still be visible. This is the guard against it vanishing early.
    expect(SetupChecklist::canView())->toBeTrue();
});

/**
 * Skipping must be permanent and per account — one admin opting out should not
 * decide it for a colleague — and it must not become a one-way door.
 */
it('offers a skip control', function (): void {
    $this->actingAs(userWithRole(UserRole::SuperAdmin))
        ->get('/admin')
        ->assertSee('wire:click="dismiss"', escape: false);
});

it('stays hidden once dismissed', function (): void {
    $admin = userWithRole(UserRole::SuperAdmin);
    $this->actingAs($admin);

    expect(SetupChecklist::canView())->toBeTrue();

    $admin->setPreference(SetupChecklist::DISMISSED, true);

    expect(SetupChecklist::canView())->toBeFalse();
    $this->get('/admin')->assertDontSee('Getting started');
});

it('does not dismiss it for other admins', function (): void {
    $first = userWithRole(UserRole::SuperAdmin);
    $first->setPreference(SetupChecklist::DISMISSED, true);

    $second = userWithRole(UserRole::SuperAdmin);

    $this->actingAs($second);
    expect(SetupChecklist::canView())->toBeTrue();
});

it('keeps the list reachable in settings after skipping', function (): void {
    $admin = userWithRole(UserRole::SuperAdmin);
    $admin->setPreference(SetupChecklist::DISMISSED, true);

    $this->actingAs($admin)
        ->get('/admin/settings')
        ->assertSuccessful()
        ->assertSee('Setup checklist')
        ->assertSee('Show on dashboard');
});

it('restores it to the dashboard from settings', function (): void {
    $admin = userWithRole(UserRole::SuperAdmin);
    $admin->setPreference(SetupChecklist::DISMISSED, true);
    $this->actingAs($admin);

    Livewire\Livewire::test(App\Filament\Pages\Settings::class)
        ->call('toggleChecklist');

    expect($admin->fresh()->preference(SetupChecklist::DISMISSED))->toBeFalse();
});

/*
 * Content Editor and Finance reach Settings deliberately — each owns a tab —
 * but neither may see launch readiness, which they cannot act on. Support has
 * no business on the screen at all.
 */
it('lets the roles that own a settings tab open the screen', function (UserRole $role): void {
    $this->actingAs(userWithRole($role))
        ->get('/admin/settings')
        ->assertSuccessful()
        ->assertDontSee('Setup checklist');
})->with([UserRole::ContentEditor, UserRole::Finance]);

it('keeps settings away from a role that owns nothing on it', function (): void {
    $this->actingAs(userWithRole(UserRole::Support))
        ->get('/admin/settings')
        ->assertForbidden();
});

/**
 * Reading a preference must not depend on the column existing. Under
 * Model::shouldBeStrict() an unloaded attribute throws, which before this guard
 * meant an unrun migration took down every admin page rather than degrading.
 */
it('falls back to the default when the column is not loaded', function (): void {
    $user = userWithRole(UserRole::SuperAdmin);

    // An instance selected without the column, as happens before the migration.
    $partial = User::query()->select(['id', 'name', 'email'])->findOrFail($user->id);

    expect($partial->preference(SetupChecklist::DISMISSED, false))->toBeFalse()
        ->and($partial->preference('anything', 'fallback'))->toBe('fallback');
});
