<?php

declare(strict_types=1);

use App\Actions\Staff\InviteStaffMember;
use App\Actions\Staff\SetStaffAccess;
use App\Enums\UserRole;
use App\Filament\Resources\Roles\Pages\CreateRole;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Staff\Pages\EditStaff;
use App\Filament\Resources\Staff\Pages\InviteStaff;
use App\Filament\Resources\Staff\Pages\ListStaff;
use App\Models\Role;
use App\Models\User;
use App\Support\PermissionCatalogue;
use App\Support\StaffLockout;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

function staff(UserRole $role): User
{
    return userWithRole($role);
}

// Locking everybody out ------------------------------------------------------------------

/*
 * The four guards. This is the one screen where a bug means nobody can get
 * into the application at all — not a wrong figure, but a business locked out
 * of its own admin with no way back short of a developer and database access.
 */

it('refuses to deactivate the only Super Admin', function (): void {
    $only = staff(UserRole::SuperAdmin);
    $other = staff(UserRole::Finance);

    expect(StaffLockout::canDeactivate($only, $other))->toBeFalse()
        ->and(StaffLockout::reasonCannotDeactivate($only, $other))
        ->toContain('only active Super Admin');
});

it('allows it once somebody else holds the role', function (): void {
    $first = staff(UserRole::SuperAdmin);
    $second = staff(UserRole::SuperAdmin);

    expect(StaffLockout::canDeactivate($first, $second))->toBeTrue();
});

/** A deactivated Super Admin is no way back in, so it must not count as one. */
it('does not count a deactivated Super Admin as a way back in', function (): void {
    $active = staff(UserRole::SuperAdmin);
    $retired = staff(UserRole::SuperAdmin);

    app(SetStaffAccess::class)->deactivate($retired, 'Left', $active);

    expect(StaffLockout::isLastActiveSuperAdmin($active))->toBeTrue()
        ->and(StaffLockout::activeSuperAdminCount())->toBe(1);
});

it('refuses to let somebody deactivate themselves', function (): void {
    $admin = staff(UserRole::SuperAdmin);
    staff(UserRole::SuperAdmin);

    expect(StaffLockout::reasonCannotDeactivate($admin, $admin))
        ->toContain('cannot deactivate your own account');
});

/*
 * The quietest way to lose access: the save succeeds, the page reloads, and
 * half the panel is gone.
 */
it('refuses to let somebody remove their own Super Admin role', function (): void {
    $admin = staff(UserRole::SuperAdmin);
    staff(UserRole::SuperAdmin);

    expect(StaffLockout::reasonCannotAssign($admin, [UserRole::Finance->value], $admin))
        ->toContain('cannot remove your own Super Admin role');
});

it('refuses to take the role off the last Super Admin', function (): void {
    $only = staff(UserRole::SuperAdmin);
    $other = staff(UserRole::Support);

    expect(StaffLockout::reasonCannotAssign($only, [UserRole::Support->value], $other))
        ->toContain('only active Super Admin');
});

it('allows a role change that keeps Super Admin', function (): void {
    $only = staff(UserRole::SuperAdmin);

    expect(StaffLockout::canAssign($only, [UserRole::SuperAdmin->value, UserRole::Finance->value], $only))
        ->toBeTrue();
});

it('stops the save, not just the button', function (): void {
    $admin = staff(UserRole::SuperAdmin);
    $finance = Role::query()->where('name', UserRole::Finance->value)->sole();

    $this->actingAs($admin);

    Livewire\Livewire::test(EditStaff::class, ['record' => $admin->getKey()])
        ->fillForm(['name' => $admin->name, 'email' => $admin->email, 'roles' => [$finance->getKey()]])
        ->call('save')
        ->assertHasFormErrors(['roles']);

    expect($admin->fresh()->hasRole(UserRole::SuperAdmin->value))->toBeTrue();
});

// Access is a lifecycle, not a side effect --------------------------------------------------

it('stops a deactivated member reaching the panel', function (): void {
    $admin = staff(UserRole::SuperAdmin);
    $editor = staff(UserRole::ContentEditor);

    app(SetStaffAccess::class)->deactivate($editor, 'Left the company', $admin);

    $this->actingAs($editor->fresh())
        ->get('/admin')
        ->assertForbidden();
});

/*
 * The reason deactivation exists rather than deletion. Seven columns across six
 * tables point at a staff member, all nullOnDelete — deleting a leaver turns
 * "who issued this certificate" into "nobody".
 */
it('keeps their roles so the record of what they had survives', function (): void {
    $admin = staff(UserRole::SuperAdmin);
    $editor = staff(UserRole::ContentEditor);

    app(SetStaffAccess::class)->deactivate($editor, 'Left', $admin);
    $editor->refresh();

    expect($editor->hasRole(UserRole::ContentEditor->value))->toBeTrue()
        ->and($editor->isStaff())->toBeTrue()
        ->and($editor->describeAccess())->toBe('Deactivated');
});

it('records who switched them off and why', function (): void {
    $admin = staff(UserRole::SuperAdmin);
    $editor = staff(UserRole::ContentEditor);

    app(SetStaffAccess::class)->deactivate($editor, 'Left on 30 September', $admin);
    $editor->refresh();

    expect($editor->deactivation_reason)->toBe('Left on 30 September')
        ->and($editor->deactivatedBy?->is($admin))->toBeTrue();
});

it('puts them back exactly as they were', function (): void {
    $admin = staff(UserRole::SuperAdmin);
    $editor = staff(UserRole::ContentEditor);

    app(SetStaffAccess::class)->deactivate($editor, 'Left', $admin);
    app(SetStaffAccess::class)->reinstate($editor);
    $editor->refresh();

    expect($editor->isDeactivated())->toBeFalse()
        ->and($editor->deactivation_reason)->toBeNull()
        ->and($editor->canAccessPanel(Filament\Facades\Filament::getPanel('admin')))->toBeTrue();
});

it('leaves a customer alone', function (): void {
    $customer = User::factory()->create();

    expect(StaffLockout::reasonCannotDeactivate($customer))->toContain('no admin access');
});

// Inviting -----------------------------------------------------------------------------------

/*
 * §9's first critical finding was an unauthenticated password-change endpoint.
 * Fortify owns password setting; nothing on this screen may write one.
 */
it('never sets a password, and sends a reset link instead', function (): void {
    Notification::fake();

    $invited = app(InviteStaffMember::class)('Dana Whitfield', 'dana@example.com', [UserRole::Support->value]);

    expect($invited->hasRole(UserRole::Support->value))->toBeTrue();

    Notification::assertSentTo($invited, ResetPassword::class);
});

it('refuses to invite somebody to no role at all', function (): void {
    expect(fn () => app(InviteStaffMember::class)('Dana', 'dana@example.com', []))
        ->toThrow(RuntimeException::class, 'at least one role');
});

it('refuses an invitation with no name or address', function (): void {
    expect(fn () => app(InviteStaffMember::class)('  ', 'dana@example.com', [UserRole::Support->value]))
        ->toThrow(RuntimeException::class);
});

/** Promoting an existing customer must not reset the password they already use. */
it('gives an existing account access without touching its password', function (): void {
    $customer = User::factory()->create(['email' => 'buyer@example.com']);
    $before = $customer->password;

    $promoted = app(InviteStaffMember::class)('Buyer', 'buyer@example.com', [UserRole::Finance->value]);

    expect($promoted->is($customer))->toBeTrue()
        ->and($promoted->fresh()->password)->toBe($before)
        ->and($promoted->hasRole(UserRole::Finance->value))->toBeTrue();
});

it('brings a deactivated account back when it is invited again', function (): void {
    $admin = staff(UserRole::SuperAdmin);
    $editor = staff(UserRole::ContentEditor);

    app(SetStaffAccess::class)->deactivate($editor, 'Left', $admin);

    app(InviteStaffMember::class)($editor->name, $editor->email, [UserRole::ContentEditor->value], $admin);

    expect($editor->fresh()->isDeactivated())->toBeFalse();
});

it('invites from the admin screen', function (): void {
    Notification::fake();

    $this->actingAs(staff(UserRole::SuperAdmin));

    $support = Role::query()->where('name', UserRole::Support->value)->sole();

    Livewire\Livewire::test(InviteStaff::class)
        ->fillForm(['name' => 'Marcus Reed', 'email' => 'marcus@example.com', 'roles' => [$support->getKey()]])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(User::query()->where('email', 'marcus@example.com')->sole()->hasRole(UserRole::Support->value))
        ->toBeTrue();
});

// Two-factor -----------------------------------------------------------------------------------

it('knows two-factor is only on once it has been confirmed', function (): void {
    $admin = staff(UserRole::SuperAdmin);

    expect($admin->hasTwoFactorEnabled())->toBeFalse();

    // Fortify writes the secret the moment somebody starts setting it up; an
    // abandoned attempt is no protection at all.
    $admin->forceFill(['two_factor_secret' => 'started', 'two_factor_confirmed_at' => null])->save();

    expect($admin->fresh()->hasTwoFactorEnabled())->toBeFalse();

    $admin->forceFill(['two_factor_confirmed_at' => now()])->save();

    expect($admin->fresh()->hasTwoFactorEnabled())->toBeTrue();
});

it('clears a lost authenticator', function (): void {
    $editor = staff(UserRole::ContentEditor);
    $editor->forceFill([
        'two_factor_secret' => 'x',
        'two_factor_recovery_codes' => 'y',
        'two_factor_confirmed_at' => now(),
    ])->save();

    app(SetStaffAccess::class)->clearTwoFactor($editor);

    expect($editor->fresh()->hasTwoFactorEnabled())->toBeFalse()
        ->and($editor->fresh()->two_factor_secret)->toBeNull();
});

// Who may manage staff --------------------------------------------------------------------------

it('keeps the staff screen to roles holding the role permission', function (): void {
    $this->actingAs(staff(UserRole::SuperAdmin))->get('/admin/staff')->assertSuccessful();

    foreach ([UserRole::ContentEditor, UserRole::Finance, UserRole::Support] as $role) {
        $this->actingAs(staff($role))->get('/admin/staff')->assertForbidden();
    }
});

it('keeps the roles screen to the same permission', function (): void {
    $this->actingAs(staff(UserRole::SuperAdmin))->get('/admin/roles')->assertSuccessful();
    $this->actingAs(staff(UserRole::Finance))->get('/admin/roles')->assertForbidden();
});

it('shows staff and hides customers', function (): void {
    $editor = staff(UserRole::ContentEditor);
    $customer = User::factory()->create();

    Livewire\Livewire::actingAs(staff(UserRole::SuperAdmin))
        ->test(ListStaff::class)
        ->assertCanSeeTableRecords([$editor])
        ->assertCanNotSeeTableRecords([$customer]);
});

// Roles ------------------------------------------------------------------------------------------

/*
 * The seeder uses syncPermissions(), so a UI edit to a system role would be
 * silently reverted on the next deploy — two sources of truth disagreeing.
 */
it('marks the four built-in roles as system', function (): void {
    foreach (UserRole::cases() as $case) {
        $role = Role::query()->where('name', $case->value)->sole();

        expect($role->isSystem())->toBeTrue()
            ->and($role->description)->toBe($case->description());
    }
});

it('refuses to save a built-in role', function (): void {
    $this->actingAs(staff(UserRole::SuperAdmin));

    $finance = Role::query()->where('name', UserRole::Finance->value)->sole();
    $before = $finance->permissions()->count();

    Livewire\Livewire::test(EditRole::class, ['record' => $finance->getKey()])
        ->fillForm(['description' => 'Rewritten'])
        ->call('save')
        ->assertHasFormErrors();

    expect($finance->fresh()->permissions()->count())->toBe($before);
});

it('refuses to delete a built-in role', function (): void {
    $finance = Role::query()->where('name', UserRole::Finance->value)->sole();

    expect($finance->isDeletable())->toBeFalse()
        ->and($finance->deletionBlockReason())->toContain('built-in');
});

it('creates a custom role that the seeder will not touch', function (): void {
    $this->actingAs(staff(UserRole::SuperAdmin));

    Livewire\Livewire::test(CreateRole::class)
        ->fillForm(['name' => 'Marketing', 'description' => 'Campaigns and the mailing list'])
        ->call('create')
        ->assertHasNoFormErrors();

    $role = Role::query()->where('name', 'marketing')->sole();

    expect($role->isSystem())->toBeFalse();

    // Reseeding must leave it exactly as it was.
    $this->seed(RolePermissionSeeder::class);

    expect(Role::query()->where('name', 'marketing')->exists())->toBeTrue();
});

it('refuses to delete a role somebody still holds', function (): void {
    $role = Role::create(['name' => 'marketing', 'guard_name' => 'web', 'is_system' => false]);
    User::factory()->create()->assignRole($role);

    expect($role->isDeletable())->toBeFalse()
        ->and($role->deletionBlockReason())->toContain('still hold');
});

it('allows deleting a role nobody holds', function (): void {
    $role = Role::create(['name' => 'marketing', 'guard_name' => 'web', 'is_system' => false]);

    expect($role->isDeletable())->toBeTrue();
});

it('reads a role name rather than showing its identifier', function (): void {
    $finance = Role::query()->where('name', UserRole::Finance->value)->sole();
    $custom = Role::create(['name' => 'campaign_manager', 'guard_name' => 'web']);

    expect($finance->displayName())->toBe('Finance')
        ->and($custom->displayName())->toBe('Campaign Manager');
});

// The permission catalogue ------------------------------------------------------------------------

/*
 * The seeder used to carry its own copy of the subjects and privileged
 * operations, so a new permission had to be added twice or it would exist
 * without appearing on any role — the same shape as the settings that lived in
 * code with no screen.
 */
it('seeds exactly what the catalogue declares', function (): void {
    $seeded = Spatie\Permission\Models\Permission::query()->pluck('name')->sort()->values()->all();
    $declared = collect(PermissionCatalogue::all())->sort()->values()->all();

    expect($seeded)->toBe($declared);
});

it('puts every permission in exactly one group', function (): void {
    $grouped = [];

    foreach (PermissionCatalogue::grouped() as $permissions) {
        foreach (array_keys($permissions) as $permission) {
            $grouped[] = $permission;
        }
    }

    expect(count($grouped))->toBe(count(array_unique($grouped)))
        ->and(collect($grouped)->sort()->values()->all())
        ->toBe(collect(PermissionCatalogue::all())->sort()->values()->all());
});

it('warns about the permissions that move money or award credit', function (string $permission): void {
    expect(PermissionCatalogue::warning($permission))->not->toBeNull();
})->with(['refund_payment', 'issue_certificate', 'grant_entitlement', 'export_data']);

it('describes a permission in words rather than in its identifier', function (): void {
    expect(PermissionCatalogue::label('update_membership_plan'))->toBe('Change Plans')
        ->and(PermissionCatalogue::label('refund_payment'))->toBe('Refund a payment');
});

/*
 * The permission was seeded, granted to Support, and implemented nowhere — so
 * it read as a capability Support had. It returns when it is built.
 */
it('no longer grants an impersonation permission that does nothing', function (): void {
    expect(Spatie\Permission\Models\Permission::query()->where('name', 'impersonate_user')->exists())
        ->toBeFalse()
        ->and(staff(UserRole::Support)->can('impersonate_user'))->toBeFalse();
});
