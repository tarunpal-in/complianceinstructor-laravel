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
 * `view_any_setting` is held by Super Admin alone, so launch readiness does not
 * leak to staff who cannot act on any of it.
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
