<?php

declare(strict_types=1);

use App\Enums\NavigationMenu;
use App\Enums\UserRole;
use App\Filament\Pages\Navigation as NavigationPage;
use App\Models\NavigationItem;
use App\Models\User;
use App\Support\Navigation;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

/*
 * The navigation manager — §5.6 / §6. The header and footer menus are editable
 * rows, read by the front end through App\Support\Navigation with a graceful
 * fallback to the designed defaults.
 */

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

// The helper -------------------------------------------------------------------

it('falls back to the default menus when none are stored', function (): void {
    expect(Navigation::header())->not->toBeEmpty()
        ->and(collect(Navigation::footer())->pluck('label'))->toContain('Company');
});

it('reads a stored menu tree', function (): void {
    $parent = NavigationItem::factory()->create([
        'menu' => NavigationMenu::Header, 'label' => 'Training', 'url' => '/webinars', 'sort_order' => 0,
    ]);
    NavigationItem::factory()->childOf($parent)->create([
        'label' => 'Live Webinars', 'url' => '/webinars?type=live', 'sort_order' => 0,
    ]);

    $header = Navigation::header();

    expect($header[0]['label'])->toBe('Training')
        ->and($header[0]['children'][0]['label'])->toBe('Live Webinars');
});

it('normalises a relative menu URL to an absolute path', function (): void {
    // "webinars?type=live" (no leading slash) is relative, so on /contact it
    // would resolve to /contact/webinars and 404; the tree makes it absolute.
    $parent = NavigationItem::factory()->create([
        'menu' => NavigationMenu::Footer, 'label' => 'Training', 'url' => null, 'sort_order' => 0,
    ]);
    NavigationItem::factory()->childOf($parent)->create([
        'label' => 'Live Webinars', 'url' => 'webinars?type=live', 'sort_order' => 0,
    ]);

    $footer = Navigation::footer();

    expect($footer[0]['children'][0]['url'])->toBe('/webinars?type=live');
});

it('leaves absolute, anchor and scheme menu URLs untouched', function (): void {
    foreach (['/webinars', 'https://example.com', 'mailto:hi@x.com', '#top'] as $url) {
        NavigationItem::query()->delete();
        NavigationItem::factory()->create([
            'menu' => NavigationMenu::Header, 'label' => 'Link', 'url' => $url, 'sort_order' => 0,
        ]);

        expect(Navigation::header()[0]['url'])->toBe($url);
    }
});

it('hides an inactive item from the front end', function (): void {
    NavigationItem::factory()->create(['menu' => NavigationMenu::Header, 'label' => 'Visible Link']);
    NavigationItem::factory()->create(['menu' => NavigationMenu::Header, 'label' => 'Hidden Link', 'is_active' => false]);

    expect(collect(Navigation::header())->pluck('label'))
        ->toContain('Visible Link')
        ->not->toContain('Hidden Link');
});

// The seeder -------------------------------------------------------------------

it('seeds the header and footer menus idempotently', function (): void {
    $this->seed(NavigationSeeder::class);
    $count = NavigationItem::query()->count();

    $this->seed(NavigationSeeder::class);

    expect($count)->toBeGreaterThan(0)
        ->and(NavigationItem::query()->count())->toBe($count)
        ->and(NavigationItem::query()->forMenu(NavigationMenu::Footer)->topLevel()->count())->toBe(4);
});

// The front end ----------------------------------------------------------------

it('renders the stored header menu in the navbar', function (): void {
    NavigationItem::factory()->create(['menu' => NavigationMenu::Header, 'label' => 'Bespoke Nav Item', 'url' => '/webinars']);

    $this->get('/')->assertSee('Bespoke Nav Item');
});

// The builder page -------------------------------------------------------------

it('lets a permitted admin save both menus', function (): void {
    Livewire::actingAs(userWithRole(UserRole::SuperAdmin))
        ->test(NavigationPage::class)
        ->set('data.header', [
            ['label' => 'Home', 'url' => '/', 'open_in_new_tab' => false, 'children' => []],
            ['label' => 'Webinars', 'url' => '/webinars', 'open_in_new_tab' => false, 'children' => [
                ['label' => 'Live', 'url' => '/webinars?type=live', 'open_in_new_tab' => false],
            ]],
        ])
        ->set('data.footer', [
            ['label' => 'Legal', 'url' => null, 'open_in_new_tab' => false, 'children' => [
                ['label' => 'Privacy Policy', 'url' => '/privacy-policy', 'open_in_new_tab' => false],
            ]],
        ])
        ->call('save')
        ->assertNotified();

    expect(NavigationItem::query()->forMenu(NavigationMenu::Header)->topLevel()->count())->toBe(2);

    $webinars = NavigationItem::query()->where('label', 'Webinars')->sole();
    expect($webinars->children)->toHaveCount(1);

    $footerHeading = NavigationItem::query()->forMenu(NavigationMenu::Footer)->topLevel()->sole();
    expect($footerHeading->label)->toBe('Legal')
        ->and($footerHeading->url)->toBeNull()
        ->and($footerHeading->children)->toHaveCount(1);
});

it('replaces the menus wholesale, leaving no orphans', function (): void {
    NavigationItem::factory()->create(['menu' => NavigationMenu::Header, 'label' => 'Old Link']);

    Livewire::actingAs(userWithRole(UserRole::SuperAdmin))
        ->test(NavigationPage::class)
        ->set('data.header', [['label' => 'New Link', 'url' => '/', 'open_in_new_tab' => false, 'children' => []]])
        ->set('data.footer', [])
        ->call('save');

    expect(NavigationItem::query()->where('label', 'Old Link')->exists())->toBeFalse()
        ->and(NavigationItem::query()->where('label', 'New Link')->exists())->toBeTrue();
});

it('is reachable only with the navigation permission', function (): void {
    $this->actingAs(userWithRole(UserRole::SuperAdmin));
    expect(NavigationPage::canAccess())->toBeTrue();

    $this->actingAs(User::factory()->create()); // a customer, no role
    expect(NavigationPage::canAccess())->toBeFalse();
});
