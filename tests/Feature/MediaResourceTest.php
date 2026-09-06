<?php

declare(strict_types=1);

use App\Actions\Media\DeleteLibraryMedia;
use App\Enums\UserRole;
use App\Filament\Resources\Media\Pages\ListMedia;
use App\Models\Media;
use App\Models\MediaFolder;
use App\Models\MediaUsage;
use App\Models\Setting;
use App\Models\Speaker;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('public');
    $this->seed(RolePermissionSeeder::class);
});

// Authorization ---------------------------------------------------------------

it('opens the media library to roles that may read it', function (UserRole $role): void {
    $this->actingAs(userWithRole($role))
        ->get('/admin/media')
        ->assertSuccessful();
})->with([UserRole::SuperAdmin, UserRole::ContentEditor, UserRole::Finance, UserRole::Support]);

/**
 * Content Editor owns the library; the other three read it. Finance needs to see
 * what an invoice template looks like, Support needs to answer "which file did
 * they download" — neither should be able to replace the site logo.
 */
it('grants library CRUD to the Content Editor only', function (): void {
    expect(userWithRole(UserRole::ContentEditor)->can('create_media'))->toBeTrue()
        ->and(userWithRole(UserRole::ContentEditor)->can('delete_media'))->toBeTrue()
        ->and(userWithRole(UserRole::Finance)->can('create_media'))->toBeFalse()
        ->and(userWithRole(UserRole::Finance)->can('update_media'))->toBeFalse()
        ->and(userWithRole(UserRole::Support)->can('delete_media'))->toBeFalse();
});

// Listing ---------------------------------------------------------------------

/**
 * The grid draws a folder name, a page count and a usage badge per card. Strict
 * mode turns a lazy load into an exception, so this fails loudly if the
 * resource's with()/withCount() is ever dropped.
 */
it('renders the library without lazy loading relations', function (): void {
    Media::factory()->count(5)->create();
    Media::factory()->pdf()->count(2)->create();

    $this->actingAs(userWithRole(UserRole::SuperAdmin))
        ->get('/admin/media')
        ->assertSuccessful();
});

it('shows library files by default and record-attached files only on request', function (): void {
    $speaker = Speaker::factory()->create();

    $library = Media::factory()->create(['name' => 'A library image']);
    $attached = Media::factory()->attachedTo($speaker, 'portrait')->create(['name' => 'A speaker portrait']);

    Livewire\Livewire::actingAs(userWithRole(UserRole::SuperAdmin))
        ->test(ListMedia::class)
        ->assertCanSeeTableRecords([$library])
        ->assertCanNotSeeTableRecords([$attached])
        ->filterTable('scope', 'attached')
        ->assertCanSeeTableRecords([$attached])
        ->assertCanNotSeeTableRecords([$library]);
});

it('finds a PDF by text inside it, not just by its name', function (): void {
    $needle = Media::factory()->pdf()->create(['name' => 'Untitled Document 3']);
    $needle->document()->create([
        'extracted_text' => 'Family and Medical Leave Act entitlement thresholds',
        'extracted_at' => now(),
    ]);

    $other = Media::factory()->pdf()->create(['name' => 'Something Else']);

    Livewire\Livewire::actingAs(userWithRole(UserRole::SuperAdmin))
        ->test(ListMedia::class)
        ->searchTable('Medical Leave')
        ->assertCanSeeTableRecords([$needle])
        ->assertCanNotSeeTableRecords([$other]);
});

it('filters images that have no alt text', function (): void {
    $missing = Media::factory()->create(['name' => 'Undescribed']);
    $described = Media::factory()->withAlt('A lecture hall')->create();
    $pdf = Media::factory()->pdf()->create();

    Livewire\Livewire::actingAs(userWithRole(UserRole::SuperAdmin))
        ->test(ListMedia::class)
        ->filterTable('missing_alt', true)
        ->assertCanSeeTableRecords([$missing])
        ->assertCanNotSeeTableRecords([$described, $pdf]);
});

// The delete guard ------------------------------------------------------------

/**
 * The classic media-manager disaster. Refusing with the list of places beats a
 * cascade that leaves the homepage with a broken image.
 */
it('refuses to delete a file that is still in use', function (): void {
    $media = Media::factory()->create();
    $folder = MediaFolder::factory()->create(['name' => 'Brand']);

    MediaUsage::create([
        'media_id' => $media->id,
        'usable_type' => MediaFolder::class,
        'usable_id' => $folder->id,
        'context' => 'brand.logo',
    ]);

    $blockers = app(DeleteLibraryMedia::class)($media);

    expect($blockers)->toHaveCount(1)
        ->and($blockers[0])->toContain('Brand')
        ->and($blockers[0])->toContain('brand.logo')
        ->and(Media::query()->find($media->id))->not->toBeNull();
});

it('deletes a file nothing is using', function (): void {
    $media = Media::factory()->create();

    expect(app(DeleteLibraryMedia::class)($media))->toBe([])
        ->and(Media::query()->find($media->id))->toBeNull();
});

it('deletes an in-use file when forced', function (): void {
    $media = Media::factory()->create();

    MediaUsage::create([
        'media_id' => $media->id,
        'usable_type' => MediaFolder::class,
        'usable_id' => MediaFolder::factory()->create()->id,
        'context' => 'brand.logo',
    ]);

    expect(app(DeleteLibraryMedia::class)($media, force: true))->toBe([])
        ->and(Media::query()->find($media->id))->toBeNull();
});

// Usage tracking --------------------------------------------------------------

/**
 * A guard that trusts a stale index is worse than no guard: it says "safe to
 * delete" with authority. The index is therefore rebuilt on every save.
 */
it('records a usage when a brand media setting is saved', function (): void {
    $logo = Media::factory()->create();

    Setting::set('brand.logo_media_id', $logo->id);

    expect($logo->usages()->count())->toBe(1)
        ->and($logo->usages()->first()?->context)->toBe('brand.logo');
});

it('moves the usage when the setting points at a different file', function (): void {
    $old = Media::factory()->create();
    $new = Media::factory()->create();

    Setting::set('brand.logo_media_id', $old->id);
    Setting::set('brand.logo_media_id', $new->id);

    expect($old->usages()->count())->toBe(0)
        ->and($new->usages()->count())->toBe(1);
});

it('ignores settings that are not media references', function (): void {
    Setting::set('promo_bar.text', 'Save 20% this week');

    expect(MediaUsage::query()->count())->toBe(0);
});

// Folders ---------------------------------------------------------------------

/**
 * A client tidying their filing should not lose the files they were filing.
 * Deleting a folder relocates its contents; the delete guard still protects
 * anything actually in use.
 */
it('moves files to Uncategorised when their folder is deleted', function (): void {
    $folder = MediaFolder::factory()->create(['name' => 'Old campaign']);
    $media = Media::factory()->create(['model_id' => $folder->id]);

    // An empty list means every folder except Uncategorised is gone.
    $result = app(App\Actions\Media\SyncMediaFolders::class)([]);

    expect($result['deleted'])->toBe(1)
        ->and($result['relocated'])->toBe(1)
        ->and($media->fresh()?->model_id)->toBe(MediaFolder::uncategorised()->id)
        ->and(Media::query()->find($media->id))->not->toBeNull();
});

it('renames and creates folders from one list', function (): void {
    $existing = MediaFolder::factory()->create(['name' => 'Brnad']);

    $result = app(App\Actions\Media\SyncMediaFolders::class)([
        ['id' => $existing->id, 'name' => 'Brand', 'parent_id' => null],
        ['id' => null, 'name' => 'Speakers', 'parent_id' => null],
    ]);

    expect($result['renamed'])->toBe(1)
        ->and($result['created'])->toBe(1)
        ->and($existing->fresh()?->name)->toBe('Brand')
        ->and($existing->fresh()?->slug)->toBe('brand');
});

/**
 * One level of nesting is a filing system; three is where files get lost. A
 * parent that is itself nested is flattened rather than quietly accepted.
 */
it('refuses to nest a folder more than one level deep', function (): void {
    $top = MediaFolder::factory()->create(['name' => 'Marketing']);
    $child = MediaFolder::factory()->create(['name' => 'Campaigns', 'parent_id' => $top->id]);

    app(App\Actions\Media\SyncMediaFolders::class)([
        ['id' => $top->id, 'name' => 'Marketing', 'parent_id' => null],
        ['id' => $child->id, 'name' => 'Campaigns', 'parent_id' => $top->id],
        ['id' => null, 'name' => 'Spring', 'parent_id' => $child->id],
    ]);

    expect(MediaFolder::query()->where('name', 'Spring')->first()?->parent_id)->toBeNull();
});

it('does not let a folder become its own parent', function (): void {
    $folder = MediaFolder::factory()->create(['name' => 'Brand']);

    app(App\Actions\Media\SyncMediaFolders::class)([
        ['id' => $folder->id, 'name' => 'Brand', 'parent_id' => $folder->id],
    ]);

    expect($folder->fresh()?->parent_id)->toBeNull();
});

// Audit trail -----------------------------------------------------------------

/**
 * §9 — the predecessor had no audit trail. "Who deleted the handout" is the
 * question this exists to answer.
 */
it('logs who deleted a file', function (): void {
    $user = userWithRole(UserRole::SuperAdmin);
    $this->actingAs($user);

    $media = Media::factory()->create(['name' => 'Accreditation Statement']);
    app(DeleteLibraryMedia::class)($media);

    $entry = Spatie\Activitylog\Models\Activity::query()
        ->where('log_name', 'media')
        ->where('event', 'deleted')
        ->latest('id')
        ->first();

    expect($entry)->not->toBeNull()
        ->and($entry?->causer_id)->toBe($user->id);
});

/**
 * Conversions rewrite generated_conversions on every upload. Logging the whole
 * model would bury the events anyone actually looks for under machine noise.
 */
it('does not log conversion bookkeeping as a change', function (): void {
    $this->actingAs(userWithRole(UserRole::SuperAdmin));

    $media = Media::factory()->create();
    $before = Spatie\Activitylog\Models\Activity::query()->where('log_name', 'media')->count();

    $media->forceFill(['generated_conversions' => ['thumb' => true]])->save();

    expect(Spatie\Activitylog\Models\Activity::query()->where('log_name', 'media')->count())->toBe($before);
});

// Seed data -------------------------------------------------------------------

/**
 * §4 rule 1 — `migrate:fresh --seed` must always produce a working, demoable
 * app, and §5.9 makes the seeded database the QA and client-review environment.
 * A library that opens empty gives the client a decision to make instead of a
 * filing system to use.
 */
it('seeds a usable library with folders, tags and files', function (): void {
    $this->seed(Database\Seeders\MediaLibrarySeeder::class);

    expect(MediaFolder::query()->count())->toBe(7)
        ->and(App\Models\Tag::query()->where('type', App\Models\Tag::TYPE_MEDIA)->count())->toBe(5)
        ->and(Media::query()->count())->toBe(8)
        ->and(Media::query()->where('mime_type', 'application/pdf')->count())->toBe(5);

    // Every seeded file has real bytes behind it, so thumbnails, sizes and
    // downloads all work in the review environment.
    Media::query()->get()->each(function (Media $media): void {
        expect(Storage::disk($media->disk)->exists($media->getPathRelativeToRoot()))->toBeTrue();
    });
});

it('extracts text from the seeded PDFs so content search demonstrates itself', function (): void {
    $this->seed(Database\Seeders\MediaLibrarySeeder::class);

    $handout = Media::query()->where('file_name', 'fmla-compliance-handout.pdf')->firstOrFail();

    expect($handout->document)->not->toBeNull()
        ->and($handout->document?->page_count)->toBe(1)
        ->and($handout->document?->extracted_text)->toContain('FMLA Compliance Handout');
});

it('rebuilds the usage index from the records', function (): void {
    $logo = Media::factory()->create();
    Setting::set('brand.logo_media_id', $logo->id);

    // Simulating drift — a manual database edit, or a bulk import that wrote
    // rows without firing model events.
    MediaUsage::query()->delete();
    expect($logo->usages()->count())->toBe(0);

    $this->artisan('media:reindex-usages')->assertSuccessful();

    expect($logo->usages()->count())->toBe(1);
});
