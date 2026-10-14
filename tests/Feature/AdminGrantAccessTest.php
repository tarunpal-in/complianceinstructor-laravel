<?php

declare(strict_types=1);

use App\Enums\EntitlementSource;
use App\Enums\Format;
use App\Enums\UserRole;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\RelationManagers\AccessRelationManager;
use App\Models\Entitlement;
use App\Models\User;
use App\Models\Webinar;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

/*
 * Admin manual grant — §8 #3 / P5 slice 4. Revoke already lives on the grant
 * (ViewEntitlement); this is the giving half, on the member's own record.
 */

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

it('lets a permitted admin grant a member access by hand', function (): void {
    $customer = User::factory()->create();
    $webinar = Webinar::factory()->create();

    Livewire::actingAs(userWithRole(UserRole::SuperAdmin))
        ->test(AccessRelationManager::class, ['ownerRecord' => $customer, 'pageClass' => EditUser::class])
        ->callAction('grantAccess', [
            'webinar_id' => $webinar->getKey(),
            'format' => 'recorded',
            'note' => 'Comped after a billing mix-up',
        ]);

    $grant = Entitlement::query()
        ->where('user_id', $customer->getKey())
        ->where('webinar_id', $webinar->getKey())
        ->sole();

    expect($grant->format)->toBe(Format::Recorded)
        ->and($grant->source_type)->toBe(EntitlementSource::Manual)
        ->and($grant->isRevoked())->toBeFalse()
        ->and($grant->note)->toContain('billing mix-up');
});

it('grants idempotently without duplicating the ledger row', function (): void {
    $customer = User::factory()->create();
    $webinar = Webinar::factory()->create();

    $grant = fn () => Livewire::actingAs(userWithRole(UserRole::SuperAdmin))
        ->test(AccessRelationManager::class, ['ownerRecord' => $customer, 'pageClass' => EditUser::class])
        ->callAction('grantAccess', ['webinar_id' => $webinar->getKey(), 'format' => 'recorded']);

    $grant();
    $grant();

    expect(Entitlement::query()->where('user_id', $customer->getKey())->count())->toBe(1);
});

it('hides the grant action from staff without the permission', function (): void {
    $customer = User::factory()->create();

    Livewire::actingAs(userWithRole(UserRole::ContentEditor))
        ->test(AccessRelationManager::class, ['ownerRecord' => $customer, 'pageClass' => EditUser::class])
        ->assertActionHidden('grantAccess');
});
