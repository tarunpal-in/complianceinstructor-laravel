<?php

declare(strict_types=1);

use App\Enums\EntitlementSource;
use App\Enums\Format;
use App\Enums\UserRole;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\RelationManagers\AccessRelationManager;
use App\Filament\Resources\Users\RelationManagers\MembershipsRelationManager;
use App\Filament\Resources\Users\RelationManagers\OrdersRelationManager;
use App\Models\Entitlement;
use App\Models\Order;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

// Who belongs on a screen called Customers ------------------------------------

/**
 * The reported problem: the Super Admin was listed among the people who buy
 * things, because the screen was labelled Customers and queried every account.
 *
 * §5.8 gives users no role column, so "customer" is the absence of the four §8
 * admin roles rather than a value anywhere.
 */
it('keeps staff out of the customers tab', function (): void {
    $customer = User::factory()->create(['name' => 'Priya Raman']);
    $admin = userWithRole(UserRole::SuperAdmin);

    Livewire\Livewire::actingAs(userWithRole(UserRole::SuperAdmin))
        ->test(ListUsers::class)
        ->assertCanSeeTableRecords([$customer])
        ->assertCanNotSeeTableRecords([$admin]);
});

/** Staff stay reachable — Staff & Roles is a placeholder until P5. */
it('lists staff on their own tab', function (): void {
    $customer = User::factory()->create();
    $finance = userWithRole(UserRole::Finance);

    Livewire\Livewire::actingAs(userWithRole(UserRole::SuperAdmin))
        ->test(ListUsers::class)
        ->set('activeTab', 'staff')
        ->assertCanSeeTableRecords([$finance])
        ->assertCanNotSeeTableRecords([$customer])
        ->set('activeTab', 'all')
        ->assertCanSeeTableRecords([$customer, $finance]);
});

it('separates accounts that never verified an email', function (): void {
    $verified = User::factory()->create();
    $unverified = User::factory()->unverified()->create();

    Livewire\Livewire::actingAs(userWithRole(UserRole::SuperAdmin))
        ->test(ListUsers::class)
        ->set('activeTab', 'unverified')
        ->assertCanSeeTableRecords([$unverified])
        ->assertCanNotSeeTableRecords([$verified]);
});

it('knows a customer from a member of staff', function (): void {
    expect(User::factory()->create()->isCustomer())->toBeTrue()
        ->and(userWithRole(UserRole::Support)->isCustomer())->toBeFalse()
        ->and(User::factory()->create()->describeRoles())->toBe('Customer')
        ->and(userWithRole(UserRole::Finance)->describeRoles())->toBe('Finance');
});

// Order history ---------------------------------------------------------------

/** The question a customer record is opened to answer. */
it('shows what a customer has bought', function (): void {
    $this->actingAs(userWithRole(UserRole::SuperAdmin));

    $customer = User::factory()->create();
    $theirs = Order::factory()->paid()->totalling(19900)->create(['user_id' => $customer->id]);
    $somebody = Order::factory()->paid()->create();

    Livewire\Livewire::test(OrdersRelationManager::class, [
        'ownerRecord' => $customer,
        'pageClass' => EditUser::class,
    ])
        ->assertCanSeeTableRecords([$theirs])
        ->assertCanNotSeeTableRecords([$somebody]);
});

it('counts what each customer has bought without a second query per row', function (): void {
    $this->actingAs(userWithRole(UserRole::SuperAdmin));

    $customer = User::factory()->create();
    Order::factory()->paid()->count(3)->create(['user_id' => $customer->id]);

    Livewire\Livewire::test(ListUsers::class)
        ->assertTableColumnStateSet('orders_count', 3, $customer);
});

/** Each relation is counted once — a duplicate alias is what MySQL rejects. */
it('counts each relation once in the customers query', function (): void {
    $sql = App\Filament\Resources\Users\UserResource::getEloquentQuery()->toSql();

    expect(substr_count($sql, 'orders_count'))->toBeLessThanOrEqual(1)
        ->and(substr_count($sql, 'subscriptions_count'))->toBeLessThanOrEqual(1)
        ->and(substr_count($sql, 'entitlements_count'))->toBeLessThanOrEqual(1);
});

// Memberships and access ------------------------------------------------------

it('shows the memberships a customer has held', function (): void {
    $this->actingAs(userWithRole(UserRole::SuperAdmin));

    $customer = User::factory()->create();
    $membership = Subscription::factory()->create(['user_id' => $customer->id]);

    Livewire\Livewire::test(MembershipsRelationManager::class, [
        'ownerRecord' => $customer,
        'pageClass' => EditUser::class,
    ])->assertCanSeeTableRecords([$membership]);
});

/**
 * All three of §5.2's routes together, which is the point of the ledger: what
 * they bought, what they spent an allowance on, and what staff gave them.
 */
it('shows everything a customer can watch, however they got it', function (): void {
    $this->actingAs(userWithRole(UserRole::SuperAdmin));

    $customer = User::factory()->create();

    $bought = Entitlement::factory()->fromOrder(7)->create(['user_id' => $customer->id]);
    $comped = Entitlement::factory()->create([
        'user_id' => $customer->id,
        'source_type' => EntitlementSource::Manual,
        'format' => Format::Recorded,
    ]);
    $somebodyElse = Entitlement::factory()->create();

    Livewire\Livewire::test(AccessRelationManager::class, [
        'ownerRecord' => $customer,
        'pageClass' => EditUser::class,
    ])
        ->assertCanSeeTableRecords([$bought, $comped])
        ->assertCanNotSeeTableRecords([$somebodyElse]);
});

/** None of the three lists may write: each record is edited where it lives. */
it('keeps the customer record read-only about other people\'s records', function (string $manager): void {
    $this->actingAs(userWithRole(UserRole::SuperAdmin));

    $customer = User::factory()->create();

    $mounted = Livewire\Livewire::test($manager, [
        'ownerRecord' => $customer,
        'pageClass' => EditUser::class,
    ]);

    expect($mounted->instance()->isReadOnly())->toBeTrue();
})->with([
    OrdersRelationManager::class,
    MembershipsRelationManager::class,
    AccessRelationManager::class,
]);
