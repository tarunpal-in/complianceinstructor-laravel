<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/**
 * Lets a soft-deleted record give back the public name it was holding.
 *
 * A slug or a coupon code is a UNIQUE column, and a UNIQUE index counts
 * soft-deleted rows like any other. So deleting the "Regular" plan left its
 * slug taken forever: the next plan that tried to use `regular` was told the
 * slug was already taken, by a record no screen would show it.
 *
 * MySQL and MariaDB have no partial indexes, so the index cannot be taught to
 * ignore deleted rows — which leaves renaming the deleted one. On delete the
 * name becomes `regular-deleted-7`; on restore it takes `regular` back if it is
 * still free, and keeps the suffixed form if something else has claimed it in
 * the meantime. That case is visible: the slug field on the restored record
 * shows what it actually holds.
 *
 * Deliberately not applied to `orders.number`. An order number is a permanent
 * reference on an invoice — GenerateOrderNumber already skips numbers in use,
 * and a number that could be handed to a second order would be a far worse bug
 * than a slug nobody can reuse.
 *
 * @phpstan-require-extends \Illuminate\Database\Eloquent\Model
 */
trait ReleasesUniqueNameOnDelete
{
    private const RELEASED_MARKER = '-deleted-';

    public static function bootReleasesUniqueNameOnDelete(): void
    {
        static::deleted(static function (self $model): void {
            // A force delete takes the row with it, so there is nothing left to
            // hold the name and nothing to rename.
            if ($model->isForceDeleting()) {
                return;
            }

            $model->releaseUniqueName();
        });

        static::restored(static function (self $model): void {
            $model->reclaimUniqueName();
        });
    }

    /** The column holding the public name. */
    protected function uniqueNameColumn(): string
    {
        return 'slug';
    }

    /**
     * How long that column is.
     *
     * The suffix has to fit inside it, or the rename that frees the name fails
     * and takes the delete down with it. A name long enough to need truncating
     * comes back shortened on restore; at 255 that needs a 246-character slug,
     * and the alternative is a delete that throws.
     */
    protected function uniqueNameLength(): int
    {
        return 255;
    }

    public function releaseUniqueName(): void
    {
        $column = $this->uniqueNameColumn();
        $current = (string) $this->getAttribute($column);

        if ($current === '') {
            return;
        }

        $suffix = self::RELEASED_MARKER.$this->getKey();

        // Already released — a second delete of the same row, or a row released
        // by the backfill migration.
        if (str_ends_with($current, $suffix)) {
            return;
        }

        $base = Str::limit($current, max(1, $this->uniqueNameLength() - strlen($suffix)), '');

        // Quietly: this is bookkeeping on the way out, not a change anyone made,
        // and it must not write an activity log entry or re-enter the delete.
        $this->forceFill([$column => $base.$suffix])->saveQuietly();
    }

    public function reclaimUniqueName(): void
    {
        $column = $this->uniqueNameColumn();
        $current = (string) $this->getAttribute($column);
        $suffix = self::RELEASED_MARKER.$this->getKey();

        if (! str_ends_with($current, $suffix)) {
            return;
        }

        $original = substr($current, 0, -strlen($suffix));

        // static::query() applies the soft-delete scope, so this asks whether a
        // *live* record has taken the name — another deleted one holding it
        // does not matter, because it is holding it under its own suffix.
        $taken = static::query()
            ->where($column, $original)
            ->whereKeyNot($this->getKey())
            ->exists();

        if ($taken) {
            return;
        }

        $this->forceFill([$column => $original])->saveQuietly();
    }
}
