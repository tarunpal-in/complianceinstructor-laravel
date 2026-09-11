<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Filament\Resources\Industries\Pages\CreateIndustry;
use App\Filament\Resources\Speakers\Pages\CreateSpeaker;
use App\Filament\Resources\Speakers\Pages\EditSpeaker;
use App\Models\Industry;
use App\Models\MembershipPlan;
use App\Models\Speaker;
use App\Models\Webinar;
use App\Support\UniqueSlug;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

// The resolver ----------------------------------------------------------------

it('leaves a free name alone', function (): void {
    expect(UniqueSlug::for(Speaker::class, 'slug', 'diane-whitfield'))->toBe('diane-whitfield');
});

it('numbers from two, then upwards', function (): void {
    Speaker::factory()->create(['slug' => 'diane-whitfield']);

    expect(UniqueSlug::for(Speaker::class, 'slug', 'diane-whitfield'))->toBe('diane-whitfield-2');

    Speaker::factory()->create(['slug' => 'diane-whitfield-2']);

    expect(UniqueSlug::for(Speaker::class, 'slug', 'diane-whitfield'))->toBe('diane-whitfield-3');
});

/** Gaps get reused — deleting the second of three should not strand the number. */
it('takes the lowest free number, not the next one up', function (): void {
    Speaker::factory()->create(['slug' => 'diane']);
    Speaker::factory()->create(['slug' => 'diane-3']);

    expect(UniqueSlug::for(Speaker::class, 'slug', 'diane'))->toBe('diane-2');
});

/**
 * The whole reason this is one query rather than a loop: a WooCommerce import
 * of a few hundred same-titled records would otherwise be quadratic.
 */
it('resolves in one query however many names are taken', function (): void {
    foreach (range(1, 30) as $i) {
        Speaker::factory()->create(['slug' => $i === 1 ? 'diane' : 'diane-'.$i]);
    }

    DB::enableQueryLog();

    $resolved = UniqueSlug::for(Speaker::class, 'slug', 'diane');

    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($resolved)->toBe('diane-31')
        ->and($queries)->toBe(1);
});

/** Only strictly numeric suffixes are variants of a name. */
it('does not treat a longer name as a numbered variant', function (): void {
    Speaker::factory()->create(['slug' => 'diane']);
    Speaker::factory()->create(['slug' => 'diane-whitfield']);
    Speaker::factory()->create(['slug' => 'diane-deleted-7']);

    expect(UniqueSlug::for(Speaker::class, 'slug', 'diane'))->toBe('diane-2');
});

/**
 * A UNIQUE index does not know what deleted_at means, so a resolver that only
 * looked at live records could hand back a name the database then refuses.
 */
it('counts deleted records as holding their name', function (): void {
    $speaker = Speaker::factory()->create(['slug' => 'diane']);

    // Held directly rather than through delete(), which would release it —
    // this is the case of a row that got there some other way.
    DB::table('speakers')->where('id', $speaker->id)->update(['deleted_at' => now()]);

    expect(UniqueSlug::for(Speaker::class, 'slug', 'diane'))->toBe('diane-2');
});

it('lets a record keep its own name', function (): void {
    $speaker = Speaker::factory()->create(['slug' => 'diane']);

    expect(UniqueSlug::for(Speaker::class, 'slug', 'diane', $speaker->getKey()))->toBe('diane');
});

/** `%` and `_` are LIKE wildcards; unescaped they would match everything. */
it('does not let a wildcard in the name match other records', function (): void {
    Speaker::factory()->create(['slug' => 'diane-whitfield']);

    expect(UniqueSlug::for(Speaker::class, 'slug', '%'))->toBe('%');
});

it('keeps the result inside the column', function (): void {
    $long = str_repeat('a', 255);

    Speaker::factory()->create(['slug' => mb_substr($long, 0, 250)]);

    $resolved = UniqueSlug::for(Speaker::class, 'slug', $long);

    expect(strlen($resolved))->toBeLessThanOrEqual(255)
        ->and($resolved)->toBe(mb_substr($long, 0, 250).'-2');
});

it('has nothing to resolve for an empty name', function (): void {
    expect(UniqueSlug::for(Speaker::class, 'slug', '   '))->toBe('');
});

// Through the admin -----------------------------------------------------------

it('saves instead of refusing when the slug is taken', function (string $model, string $page): void {
    $this->actingAs(userWithRole(UserRole::SuperAdmin));

    $model::factory()->create(['slug' => 'compliance']);

    Livewire\Livewire::test($page)
        ->fillForm(['name' => 'Compliance', 'slug' => 'compliance', 'is_active' => true])
        ->call('create')
        ->assertHasNoFormErrors();

    expect($model::query()->where('slug', 'compliance-2')->exists())->toBeTrue();
})->with([
    [Speaker::class, CreateSpeaker::class],
    [Industry::class, CreateIndustry::class],
]);

/** Silence is the one thing this must not be — a slug is a public URL. */
it('says so when it had to change the slug', function (): void {
    $this->actingAs(userWithRole(UserRole::SuperAdmin));

    Speaker::factory()->create(['slug' => 'compliance']);

    Livewire\Livewire::test(CreateSpeaker::class)
        ->fillForm(['name' => 'Compliance', 'slug' => 'compliance', 'is_active' => true])
        ->call('create')
        ->assertNotified();
});

it('leaves a record alone when its own slug is unchanged', function (): void {
    $this->actingAs(userWithRole(UserRole::SuperAdmin));

    $speaker = Speaker::factory()->create(['slug' => 'diane', 'name' => 'Diane']);

    Livewire\Livewire::test(EditSpeaker::class, ['record' => $speaker->getKey()])
        ->fillForm(['name' => 'Diane Whitfield'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($speaker->fresh()?->slug)->toBe('diane');
});

/**
 * The two fixes meet here: deleting used to leave the name reserved, so this
 * would have saved as "regular-2" even though nothing live was called regular.
 */
it('reuses a name a deleted record gave back, without numbering it', function (): void {
    $plan = MembershipPlan::factory()->create(['slug' => 'regular']);
    $plan->delete();

    expect(UniqueSlug::for(MembershipPlan::class, 'slug', 'regular'))->toBe('regular');
});

it('resolves webinar slugs too', function (): void {
    Webinar::factory()->create(['slug' => 'fmla-compliance-2026']);

    expect(UniqueSlug::for(Webinar::class, 'slug', 'fmla-compliance-2026'))
        ->toBe('fmla-compliance-2026-2');
});
