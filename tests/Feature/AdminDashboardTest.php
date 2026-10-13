<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
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

it('shows the dashboard to an admin', function (): void {
    $this->actingAs(userWithRole(UserRole::SuperAdmin))
        ->get('/admin')
        ->assertSuccessful()
        ->assertSee('Compliance Instructor', escape: false);
});

/*
 * The Recent Orders widget derives each order's status from its payments and
 * refunds, so its query must eager-load them. With Model::shouldBeStrict() on
 * outside production, a missing eager load throws LazyLoadingViolationException
 * the moment the widget has an order with a payment to show — which it now does
 * after `migrate:fresh --seed`. The dashboard renders the widget deferred, so
 * this drives the widget directly, where its query and status column actually
 * run.
 */
it('renders the Recent Orders widget when there are orders to list', function (): void {
    $order = Order::factory()->create(['placed_at' => now(), 'subtotal_cents' => 20000, 'total_cents' => 20000]);
    $payment = Payment::factory()->forOrder($order)->create(['amount_cents' => 20000]);
    Refund::factory()->partialOf($payment, 5000)->create();

    $component = Livewire\Livewire::actingAs(userWithRole(UserRole::SuperAdmin))
        ->test(App\Filament\Widgets\RecentOrders::class)
        ->assertCanSeeTableRecords([$order])
        ->assertSee($order->number);

    // The status column derives from payments and refunds, so the table query
    // must eager-load them — reverting that eager load fails here.
    $eager = array_keys($component->instance()->getTable()->getQuery()->getEagerLoads());

    expect($eager)->toContain('payments.refunds');
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
        ->toContain('ci-theme-toggle')    // single theme control, replaces Filament's three
        ->toContain('fi-global-search')   // search, centre
        ->toContain('fi-user-menu')       // avatar, right
        ->toContain('fi-sidebar');        // left navigation
});

it('exposes users to global search', function (): void {
    expect(App\Filament\Resources\Users\UserResource::getGloballySearchableAttributes())
        ->toBe(['name', 'email']);
});

/**
 * The sidebar shape was agreed with the client before the resources behind it
 * exist. These pin it so a stub cannot be dropped silently when its resource
 * lands, and so Forum and Quizzes — both cut (§13) — cannot reappear.
 */
it('renders the agreed navigation groups', function (string $group): void {
    $this->actingAs(userWithRole(UserRole::SuperAdmin))
        ->get('/admin')
        ->assertSee($group, escape: false);
})->with(['Catalogue', 'Sales', 'Memberships', 'Learners', 'Content', 'Inbox', 'Reports', 'System']);

it('renders the agreed navigation items', function (string $item): void {
    $this->actingAs(userWithRole(UserRole::SuperAdmin))
        ->get('/admin')
        ->assertSee($item, escape: false);
})->with([
    'Webinars', 'Packages', 'Speakers', 'Industries',
    'Orders', 'Payments', 'Coupons',
    'Plans', 'Subscriptions',
    'Customers', 'Entitlements', 'Certificates',
    'Pages', 'Blog', 'Redirects', 'SEO health',
    'Inquiries', 'Subscribers',
    // 'Emails' is now a cluster; its overview and log are tabs inside it, not
    // separate sidebar items.
    'Staff', 'Roles', 'Settings', 'Emails', 'Activity Log', 'Backups',
]);

/*
 * The stub for a resource that has since been built shows as two identical
 * items in the same group, one of which links to '#'. The presence test above
 * cannot see it — "does the sidebar contain Inquiries" is true either way — so
 * this compares the two lists directly. It caught exactly that when Leads was
 * built over its own stub, and again when Leads became Inquiries.
 */
it('drops a placeholder once its resource lands', function (): void {
    $panel = Filament\Facades\Filament::getPanel('admin');

    $stubs = collect($panel->getNavigationItems())
        ->map(fn (Filament\Navigation\NavigationItem $item): string => (string) $item->getLabel());

    $resources = collect($panel->getResources())
        ->map(fn (string $resource): string => (string) $resource::getNavigationLabel());

    expect($stubs->intersect($resources)->values()->all())->toBe([]);
});

it('does not offer Forum or Quizzes, which were cut', function (string $gone): void {
    $this->actingAs(userWithRole(UserRole::SuperAdmin))
        ->get('/admin')
        ->assertDontSee($gone, escape: false);
})->with(['Forum', 'Quizzes']);
