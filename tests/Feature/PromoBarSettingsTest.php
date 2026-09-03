<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Filament\Pages\Settings;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->seed(SettingSeeder::class);
});

it('renders the promo bar from settings', function (): void {
    Setting::set('promo_bar.background_color', '#123456', 'color');
    Setting::set('promo_bar.text_color', '#abcdef', 'color');
    Setting::set('promo_bar.font_size', 18, 'integer');
    Setting::set('promo_bar.text', 'Save big with :code today.', 'string');
    Setting::set('promo_bar.code', 'SPRING', 'string');

    $this->get('/')
        ->assertSee('background: #123456; color: #abcdef; font-size: 18px', escape: false)
        ->assertSee('Save big with', escape: false)
        ->assertSee('<strong>SPRING</strong>', escape: false);
});

it('removes the bar entirely when disabled', function (): void {
    Setting::set('promo_bar.enabled', false, 'boolean');

    $this->get('/')
        ->assertSuccessful()
        ->assertDontSee('promo-bar', escape: false);
});

/**
 * Settings are admin-authored, but an admin is still an untrusted source for a
 * value interpolated into a style attribute — a stray `;` would break out of the
 * declaration. Anything that is not a plain hex colour must fall back.
 */
it('refuses a colour that is not plain hex', function (string $malicious): void {
    Setting::set('promo_bar.background_color', $malicious, 'color');

    $this->get('/')
        ->assertDontSee($malicious, escape: false)
        ->assertSee('background: #0c4d80', escape: false);
})->with([
    'red; position: fixed; inset: 0',
    'url(javascript:alert(1))',
    '#0c4d80; content: "x"',
]);

it('clamps the font size to a sane range', function (): void {
    Setting::set('promo_bar.font_size', 900, 'integer');

    $this->get('/')->assertSee('font-size: 32px', escape: false);
});

it('lets an admin save promo bar settings', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole(UserRole::SuperAdmin->value);

    Livewire\Livewire::actingAs($admin)
        ->test(Settings::class)
        ->fillForm([
            'promo_bar_enabled' => true,
            'promo_bar_text' => 'New offer with :code inside.',
            'promo_bar_code' => 'AUTUMN',
            'promo_bar_background_color' => '#101820',
            'promo_bar_text_color' => '#ffffff',
            'promo_bar_font_size' => 15,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    Setting::flush();

    expect(Setting::get('promo_bar.code'))->toBe('AUTUMN')
        ->and(Setting::get('promo_bar.font_size'))->toBe(15)
        ->and(Setting::get('promo_bar.background_color'))->toBe('#101820');
});

it('serves settings from one cached read', function (): void {
    Setting::flush();
    Setting::cached();

    DB::enableQueryLog();
    Setting::get('promo_bar.enabled');
    Setting::get('promo_bar.text');
    Setting::get('promo_bar.font_size');

    expect(DB::getQueryLog())->toBeEmpty();
});

/**
 * Filament drops hidden fields from form state, so saving with the bar toggled
 * off used to throw "Undefined array key promo_bar_text". The fields now
 * dehydrate while hidden, and save() falls back to stored values regardless —
 * so turning the bar off must never wipe the message or the colours.
 */
it('saves with the bar disabled without losing the other values', function (): void {
    Setting::set('promo_bar.text', 'Keep me with :code.', 'string');
    Setting::set('promo_bar.background_color', '#101820', 'color');
    Setting::set('promo_bar.font_size', 16, 'integer');

    $admin = User::factory()->create();
    $admin->assignRole(UserRole::SuperAdmin->value);

    Livewire\Livewire::actingAs($admin)
        ->test(Settings::class)
        ->set('data.promo_bar_enabled', false)
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertOk();

    Setting::flush();

    expect(Setting::get('promo_bar.enabled'))->toBeFalse()
        ->and(Setting::get('promo_bar.text'))->toBe('Keep me with :code.')
        ->and(Setting::get('promo_bar.background_color'))->toBe('#101820')
        ->and(Setting::get('promo_bar.font_size'))->toBe(16);
});

it('re-enables the bar with its previous styling intact', function (): void {
    Setting::set('promo_bar.enabled', false, 'boolean');
    Setting::set('promo_bar.text_color', '#abcdef', 'color');

    $admin = User::factory()->create();
    $admin->assignRole(UserRole::SuperAdmin->value);

    Livewire\Livewire::actingAs($admin)
        ->test(Settings::class)
        ->set('data.promo_bar_enabled', true)
        ->call('save')
        ->assertHasNoFormErrors();

    Setting::flush();

    expect(Setting::get('promo_bar.enabled'))->toBeTrue()
        ->and(Setting::get('promo_bar.text_color'))->toBe('#abcdef');
});
