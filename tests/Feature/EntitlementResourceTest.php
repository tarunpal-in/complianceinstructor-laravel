<?php

declare(strict_types=1);

use App\Actions\Entitlements\GrantEntitlement;
use App\Actions\Entitlements\RevokeEntitlement;
use App\Enums\EntitlementSource;
use App\Enums\Format;
use App\Enums\UserRole;
use App\Filament\Resources\Entitlements\EntitlementResource;
use App\Filament\Resources\Entitlements\Pages\ListEntitlements;
use App\Filament\Resources\Entitlements\Pages\ViewEntitlement;
use App\Filament\Resources\Entitlements\RelationManagers\HistoryRelationManager;
use App\Models\Entitlement;
use App\Models\User;
use App\Models\Webinar;
use Database\Seeders\RolePermissionSeeder;
use Spatie\Activitylog\Models\Activity;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

// Authorization ---------------------------------------------------------------

it('opens the access register to roles that resolve access problems', function (UserRole $role): void {
    $this->actingAs(userWithRole($role))
        ->get('/admin/entitlements')
        ->assertSuccessful();
})->with([UserRole::SuperAdmin, UserRole::Finance, UserRole::Support]);

it('keeps the Content Editor out of the access register', function (): void {
    $this->actingAs(userWithRole(UserRole::ContentEditor))
        ->get('/admin/entitlements')
        ->assertForbidden();
});

/**
 * §5.2 — the ledger is append-only, and access is granted by an act that
 * records why. A form here would be a way to grant access no purchase paid for.
 */
it('offers no way to create, edit or delete a grant', function (): void {
    $entitlement = Entitlement::factory()->create();

    expect(EntitlementResource::canCreate())->toBeFalse()
        ->and(EntitlementResource::canEdit($entitlement))->toBeFalse()
        ->and(EntitlementResource::canDelete($entitlement))->toBeFalse();

    $this->actingAs(userWithRole(UserRole::SuperAdmin))
        ->get('/admin/entitlements/create')
        ->assertNotFound();
});

// The register ----------------------------------------------------------------

it('separates live access from what was given back and what was comped', function (): void {
    $active = Entitlement::factory()->create();
    $givenBack = Entitlement::factory()->revoked()->create();
    $comped = Entitlement::factory()->create(['source_type' => EntitlementSource::Manual]);
    $bought = Entitlement::factory()->fromOrder(7)->create();

    Livewire\Livewire::actingAs(userWithRole(UserRole::Support))
        ->test(ListEntitlements::class)
        ->set('activeTab', 'active')
        ->assertCanSeeTableRecords([$active, $comped, $bought])
        ->assertCanNotSeeTableRecords([$givenBack])
        ->set('activeTab', 'given_back')
        ->assertCanSeeTableRecords([$givenBack])
        ->assertCanNotSeeTableRecords([$active])
        ->set('activeTab', 'comped')
        ->assertCanSeeTableRecords([$active, $comped])
        ->assertCanNotSeeTableRecords([$bought]);
});

it('lists expired access separately from access given back', function (): void {
    $expired = Entitlement::factory()->expired()->create();
    $active = Entitlement::factory()->create();

    Livewire\Livewire::actingAs(userWithRole(UserRole::Support))
        ->test(ListEntitlements::class)
        ->set('activeTab', 'expired')
        ->assertCanSeeTableRecords([$expired])
        ->assertCanNotSeeTableRecords([$active]);
});

/** A badge reading 40,000 is decoration; this one only appears when it matters. */
it('flags access about to expire, and stays quiet otherwise', function (): void {
    expect(EntitlementResource::getNavigationBadge())->toBeNull();

    Entitlement::factory()->create(['expires_at' => now()->addDays(3)]);

    expect(EntitlementResource::getNavigationBadge())->toBe('1');
});

// Usage history ---------------------------------------------------------------

/**
 * The row holds the current state; the history holds what happened. A grant
 * given, taken back and given again reads as one row and three events.
 */
it('records every grant, revoke and restore', function (): void {
    $this->actingAs(userWithRole(UserRole::Support));

    $user = User::factory()->create();
    $webinar = Webinar::factory()->create();

    $entitlement = app(GrantEntitlement::class)($user, $webinar, Format::Live, EntitlementSource::Order, 7);
    app(RevokeEntitlement::class)($entitlement, 'Refunded the order');
    app(GrantEntitlement::class)($user, $webinar, Format::Live, EntitlementSource::Order, 7);

    $history = Activity::query()
        ->where('subject_type', $entitlement->getMorphClass())
        ->where('subject_id', $entitlement->getKey())
        ->orderBy('id')
        ->pluck('description')
        ->all();

    expect(Entitlement::query()->count())->toBe(1)
        ->and($history)->toBe(['Access granted', 'Given back', 'Access restored']);
});

it('records who did it', function (): void {
    $staff = userWithRole(UserRole::Support);
    $this->actingAs($staff);

    $entitlement = Entitlement::factory()->create();

    app(RevokeEntitlement::class)($entitlement, 'Could not attend');

    $entry = Activity::query()->latest('id')->first();

    expect($entry?->causer_id)->toBe($staff->id)
        ->and($entry?->description)->toBe('Given back');
});

it('shows the history on the record', function (): void {
    $this->actingAs(userWithRole(UserRole::Support));

    $entitlement = Entitlement::factory()->create();
    app(RevokeEntitlement::class)($entitlement, 'Could not attend; sent the recording');

    Livewire\Livewire::test(HistoryRelationManager::class, [
        'ownerRecord' => $entitlement->fresh(),
        'pageClass' => ViewEntitlement::class,
    ])
        ->assertSuccessful()
        ->assertSee('Given back')
        // The reason, not a JSON diff of the columns that changed.
        ->assertSee('Could not attend; sent the recording');
});

/** An audit trail somebody can edit is not an audit trail. */
it('does not let anyone edit the history', function (): void {
    $this->actingAs(userWithRole(UserRole::SuperAdmin));

    $entitlement = Entitlement::factory()->create();

    $manager = Livewire\Livewire::test(HistoryRelationManager::class, [
        'ownerRecord' => $entitlement,
        'pageClass' => ViewEntitlement::class,
    ]);

    expect($manager->instance()->isReadOnly())->toBeTrue();
});

// Giving access back from the register ----------------------------------------

it('gives access back from the record itself', function (): void {
    $this->actingAs(userWithRole(UserRole::Support));

    $entitlement = Entitlement::factory()->create();

    Livewire\Livewire::test(ViewEntitlement::class, ['record' => $entitlement->getKey()])
        ->callAction('giveBack', ['reason' => 'Sent the recording instead']);

    expect($entitlement->fresh()?->isRevoked())->toBeTrue()
        ->and($entitlement->fresh()?->note)->toBe('Sent the recording instead');
});

it('offers no give-back to a role that cannot revoke access', function (): void {
    $this->actingAs(userWithRole(UserRole::Finance));

    $entitlement = Entitlement::factory()->create();

    Livewire\Livewire::test(ViewEntitlement::class, ['record' => $entitlement->getKey()])
        ->assertActionHidden('giveBack');
});

it('offers no give-back on access already given back', function (): void {
    $this->actingAs(userWithRole(UserRole::Support));

    $entitlement = Entitlement::factory()->revoked()->create();

    Livewire\Livewire::test(ViewEntitlement::class, ['record' => $entitlement->getKey()])
        ->assertActionHidden('giveBack');
});
