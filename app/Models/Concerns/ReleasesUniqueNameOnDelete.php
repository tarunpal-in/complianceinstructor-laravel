<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Support\UniqueSlug;
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
     * The same, readable from outside.
     *
     * The override point stays protected; this exists so the guard in
     * UniqueNameReleaseTest can ask a model which of its unique columns is
     * actually covered, rather than assuming it is `slug`.
     */
    public function releasedNameColumn(): string
    {
        return $this->uniqueNameColumn();
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

    /**
     * Unique columns emptied on delete rather than renamed.
     *
     * Some unique values belong to somebody else's system and mean nothing
     * once altered. `membership_plans.stripe_price_id` is the case in hand: a
     * Stripe price ID has to match Stripe byte for byte, so renaming it to
     * `price_1QRstu...-deleted-7` would leave a reference to nothing that looks
     * exactly like a real one — and the failure would surface as a declined
     * payment rather than as an error anyone could read.
     *
     * A deleted plan cannot be sold, so the reference has no live purpose and
     * only holds the price hostage against a new plan that wants it. Clearing
     * it releases the price with no corrupted value written anywhere.
     *
     * The cost is that a restored record comes back without it. That state is
     * already handled and already explained — MembershipPlan::problems() says
     * "A recurring plan needs a Stripe price ID before anyone can subscribe",
     * and the form refuses to put such a plan on the pricing page. Visible and
     * self-describing beats silently wrong.
     *
     * Every column listed here must be nullable.
     *
     * @return array<int, string>
     */
    protected function namesClearedOnDelete(): array
    {
        return [];
    }

    /**
     * The same, readable from outside — see releasedNameColumn().
     *
     * @return array<int, string>
     */
    public function clearedNameColumns(): array
    {
        return $this->namesClearedOnDelete();
    }

    public function releaseUniqueName(): void
    {
        $changes = $this->releasedNameChange();

        foreach ($this->namesClearedOnDelete() as $column) {
            if ($this->getAttribute($column) !== null) {
                $changes[$column] = null;
            }
        }

        if ($changes === []) {
            return;
        }

        // Quietly: this is bookkeeping on the way out, not a change anyone made,
        // and it must not write an activity log entry or re-enter the delete.
        $this->forceFill($changes)->saveQuietly();
    }

    /**
     * The rename that frees the name, or nothing if there is none to do.
     *
     * @return array<string, string>
     */
    private function releasedNameChange(): array
    {
        $column = $this->uniqueNameColumn();
        $current = (string) $this->getAttribute($column);

        if ($current === '') {
            return [];
        }

        $suffix = self::RELEASED_MARKER.$this->getKey();

        // Already released — a second delete of the same row, or a row released
        // by the backfill migration.
        if (str_ends_with($current, $suffix)) {
            return [];
        }

        $length = $this->uniqueNameLength();
        $base = Str::limit($current, max(1, $length - strlen($suffix)), '');

        /*
         * Through the resolver rather than used as-is. The suffix is built from
         * the primary key so it is unique among *released* names, but nothing
         * stops a live record from literally being called
         * `regular-deleted-7` — and then the rename would collide and take the
         * delete down with it. Absurdly unlikely, and free to rule out.
         */
        return [$column => UniqueSlug::for(static::class, $column, $base.$suffix, $this->getKey(), $length)];
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
