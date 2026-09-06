<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Filament\Pages\Settings;
use App\Models\Media;
use App\Models\Setting;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('public');
    $this->seed(RolePermissionSeeder::class);
    $this->seed(SettingSeeder::class);
});

it('saves the brand images as media references', function (): void {
    $logo = Media::factory()->create(['name' => 'Logo']);
    $favicon = Media::factory()->create(['name' => 'Favicon']);

    Livewire\Livewire::actingAs(userWithRole(UserRole::SuperAdmin))
        ->test(Settings::class)
        ->fillForm([
            'brand_logo_media_id' => $logo->id,
            'brand_favicon_media_id' => $favicon->id,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Setting::mediaId('brand.logo_media_id'))->toBe($logo->id)
        ->and(Setting::mediaId('brand.favicon_media_id'))->toBe($favicon->id);
});

/**
 * The point of picking from the library rather than uploading here: the logo is
 * a reference, so the delete guard knows it is in use and replacing the file
 * updates the header without anyone touching settings again.
 */
it('records the logo as in use, which the delete guard then honours', function (): void {
    $logo = Media::factory()->create();

    Livewire\Livewire::actingAs(userWithRole(UserRole::SuperAdmin))
        ->test(Settings::class)
        ->fillForm(['brand_logo_media_id' => $logo->id])
        ->call('save');

    expect($logo->usages()->count())->toBe(1);

    $blockers = app(App\Actions\Media\DeleteLibraryMedia::class)($logo);

    expect($blockers)->toHaveCount(1)
        ->and($blockers[0])->toContain('brand.logo')
        ->and(Media::query()->find($logo->id))->not->toBeNull();
});

it('releases the file when the setting is cleared', function (): void {
    $logo = Media::factory()->create();

    $page = Livewire\Livewire::actingAs(userWithRole(UserRole::SuperAdmin))
        ->test(Settings::class)
        ->fillForm(['brand_logo_media_id' => $logo->id])
        ->call('save');

    expect($logo->usages()->count())->toBe(1);

    $page->fillForm(['brand_logo_media_id' => null])->call('save');

    expect($logo->usages()->count())->toBe(0)
        ->and(app(App\Actions\Media\DeleteLibraryMedia::class)($logo))->toBe([]);
});

/**
 * A value that is not a media id must not reach a query. `value` is a JSON
 * column, so a hand-edited row could hold anything.
 */
it('ignores a brand setting that does not hold a media id', function (): void {
    Setting::set('brand.logo_media_id', 'not-an-id', 'media_id');

    expect(Setting::mediaId('brand.logo_media_id'))->toBeNull();
});
