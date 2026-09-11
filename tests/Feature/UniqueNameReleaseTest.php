<?php

declare(strict_types=1);

use App\Models\Concerns\ReleasesUniqueNameOnDelete;
use App\Models\Industry;
use App\Models\Speaker;
use App\Models\Webinar;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Finder\SplFileInfo;

/**
 * A UNIQUE index counts soft-deleted rows, so a deleted record goes on holding
 * its slug or code against a record no screen will show. That is what made
 * "The slug has already been taken" unfixable for a deleted plan.
 *
 * `ReleasesUniqueNameOnDelete` fixes it one model at a time. This file is what
 * stops the next model being added without it: every unique name column on
 * every soft-deleting model must either be released on delete, or appear in the
 * exclusion list below with a reason someone wrote down.
 *
 * §9's approach — "structural prevention", not a note in a wiki nobody reads.
 */

/**
 * Columns that hold their name on purpose.
 *
 * Each of these was a decision, and each is here so it stays one. Reusing any
 * of them would make historic records ambiguous, which is worse than a name
 * that cannot be reused.
 *
 * @return array<string, string>
 */
function namesHeldOnPurpose(): array
{
    return [
        // An invoice reference. GenerateOrderNumber already skips numbers in
        // use, and a number that could be handed to a second order is far worse
        // than a number nobody can reuse.
        'orders.number' => 'Order numbers appear on invoices and must never be reused.',

        // order_items snapshots the sku onto the line, and offering_id is
        // nullOnDelete — so a reused sku would make historic invoice lines
        // ambiguous about which product was actually sold.
        'offerings.sku' => 'The sku is snapshotted onto order lines, so reuse makes historic lines ambiguous.',
    ];
}

/**
 * Every model in app/Models that soft-deletes.
 *
 * Read off the filesystem rather than listed by hand — a list would go stale
 * the first time someone adds a model, which is the moment this test exists
 * for.
 *
 * @return array<int, class-string<Model>>
 */
function softDeletingModels(): array
{
    return collect(File::files(app_path('Models')))
        ->map(fn (SplFileInfo $file): string => 'App\\Models\\'.$file->getBasename('.php'))
        ->filter(fn (string $class): bool => class_exists($class) && is_subclass_of($class, Model::class))
        ->filter(fn (string $class): bool => in_array(SoftDeletes::class, class_uses_recursive($class), true))
        ->values()
        ->all();
}

/**
 * The single-column unique indexes on a table, primary key aside.
 *
 * No filtering by column type, deliberately. Judging what counts as a "name"
 * would mean guessing, and a guess that comes out wrong hides a column instead
 * of flagging it. Anything unique gets raised, and whoever added it either
 * covers it or writes down why it keeps its name.
 *
 * @return array<int, string>
 */
function uniqueNameColumns(string $table): array
{
    return collect(Schema::getIndexes($table))
        ->filter(fn (array $index): bool => ($index['unique'] ?? false) && ! ($index['primary'] ?? false))
        ->filter(fn (array $index): bool => count($index['columns'] ?? []) === 1)
        ->map(fn (array $index): string => $index['columns'][0])
        ->values()
        ->all();
}

it('finds the models this is meant to cover', function (): void {
    // A sanity check on the discovery itself: a test that silently enumerates
    // nothing would pass forever while covering nothing.
    expect(softDeletingModels())->toContain(
        App\Models\Coupon::class,
        App\Models\MembershipPlan::class,
        App\Models\Order::class,
        Webinar::class,
    );
});

/**
 * The guard. Any unique name on a soft-deleting model is either released on
 * delete or written down as a deliberate exception.
 */
it('releases every unique name a deleted record would otherwise hold', function (): void {
    $unaccounted = [];

    foreach (softDeletingModels() as $class) {
        $model = new $class;
        $table = $model->getTable();

        $covered = in_array(ReleasesUniqueNameOnDelete::class, class_uses_recursive($class), true)
            // Renamed on the way out, or emptied — either way the deleted
            // record stops holding the name.
            ? [$model->releasedNameColumn(), ...$model->clearedNameColumns()]
            : [];

        foreach (uniqueNameColumns($table) as $column) {
            if (in_array($column, $covered, true)) {
                continue;
            }

            if (array_key_exists("{$table}.{$column}", namesHeldOnPurpose())) {
                continue;
            }

            $unaccounted[] = "{$table}.{$column} ({$class})";
        }
    }

    expect($unaccounted)->toBe([], implode("\n", [
        'These unique columns are on soft-deleting models, so a deleted record keeps holding them',
        'and nothing can reuse the name:',
        '',
        ...array_map(static fn (string $c): string => '  - '.$c, $unaccounted),
        '',
        'Cover it with ReleasesUniqueNameOnDelete — uniqueNameColumn() to rename it on the',
        'way out, or namesClearedOnDelete() to empty it where the value belongs to another',
        'system and cannot be renamed. Failing that, add the column to namesHeldOnPurpose()',
        'in this file with the reason it keeps its name.',
    ]));
});

/**
 * A Stripe price ID cannot be renamed — `price_1QRstu...-deleted-7` looks like
 * a real reference and resolves to nothing, and that surfaces as a declined
 * payment rather than as an error anyone can read. A deleted plan cannot be
 * sold, so it gives the price back instead.
 */
it('gives a deleted plan\'s Stripe price back rather than mangling it', function (): void {
    $plan = App\Models\MembershipPlan::factory()->create(['stripe_price_id' => 'price_1QRstuVWxyz0123']);

    $plan->delete();

    expect($plan->fresh()?->stripe_price_id)->toBeNull();

    // Which is the point: a new plan can be pointed at the same Stripe price.
    expect(App\Models\MembershipPlan::factory()->create(['stripe_price_id' => 'price_1QRstuVWxyz0123'])->stripe_price_id)
        ->toBe('price_1QRstuVWxyz0123');
});

/** The slug is still released in the same breath as the Stripe price. */
it('releases the slug and the Stripe price together', function (): void {
    $plan = App\Models\MembershipPlan::factory()->create([
        'slug' => 'regular',
        'stripe_price_id' => 'price_together',
    ]);

    $plan->delete();

    $fresh = $plan->fresh();

    expect($fresh?->slug)->toBe('regular-deleted-'.$plan->getKey())
        ->and($fresh?->stripe_price_id)->toBeNull();
});

// The three models added alongside the guard --------------------------------

it('hands a slug back when a record is deleted', function (string $class): void {
    /** @var Model $record */
    $record = $class::factory()->create(['slug' => 'compliance-2026']);

    $record->delete();

    expect($record->fresh()?->slug)->toBe('compliance-2026-deleted-'.$record->getKey());

    // Which is the point: something else can now take the name.
    expect($class::factory()->create(['slug' => 'compliance-2026'])->slug)->toBe('compliance-2026');
})->with([Webinar::class, Speaker::class, Industry::class]);

it('takes the slug back on restore when it is still free', function (string $class): void {
    /** @var Model $record */
    $record = $class::factory()->create(['slug' => 'compliance-2026']);

    $record->delete();
    $record->restore();

    expect($record->fresh()?->slug)->toBe('compliance-2026');
})->with([Webinar::class, Speaker::class, Industry::class]);

it('does not steal a slug back from a live record', function (string $class): void {
    /** @var Model $record */
    $record = $class::factory()->create(['slug' => 'compliance-2026']);
    $record->delete();

    $class::factory()->create(['slug' => 'compliance-2026']);

    $record->restore();

    expect($record->fresh()?->slug)->toBe('compliance-2026-deleted-'.$record->getKey());
})->with([Webinar::class, Speaker::class, Industry::class]);
