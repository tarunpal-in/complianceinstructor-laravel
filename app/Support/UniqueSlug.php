<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * The next free form of a name — `regular`, else `regular-2`, `regular-3`.
 *
 * A UNIQUE slug used to mean a save could be refused outright, and the person
 * saving had to go and work out who was holding the name. This resolves it
 * instead, the way WordPress does.
 *
 * **One query, not a loop.** The obvious implementation probes each candidate
 * in turn, which is fine for a form and quadratic for an import: bring 1,200
 * webinars across from WooCommerce with a few hundred shared titles and the
 * three-hundredth duplicate costs three hundred queries by itself. Every
 * candidate is fetched at once and the gap found in PHP, so the cost is the
 * same on the first collision and the thousandth.
 *
 * **Deleted rows count.** A UNIQUE index does not know what `deleted_at` means,
 * so a resolver that only looked at live records could hand back a name the
 * database then refuses. Global scopes are dropped for exactly that reason.
 */
final class UniqueSlug
{
    /**
     * @param  class-string<Model>  $model
     * @param  int|string|null  $ignoreId  the record being edited, which may keep its own name
     */
    public static function for(
        string $model,
        string $column,
        string $desired,
        int|string|null $ignoreId = null,
        int $maxLength = 255,
    ): string {
        $desired = trim($desired);

        if ($desired === '') {
            // Nothing to resolve. An empty slug is a validation problem and
            // saying so is the form's job, not this one's.
            return $desired;
        }

        // Room for the longest suffix this could need, so the result cannot
        // overflow the column and turn a save into a database error.
        $desired = mb_substr($desired, 0, max(1, $maxLength - 5));

        $taken = self::taken($model, $column, $desired, $ignoreId);

        if (! in_array($desired, $taken, true)) {
            return $desired;
        }

        return $desired.'-'.self::firstFreeSuffix($desired, $taken);
    }

    /**
     * Everything already using this name or a numbered form of it.
     *
     * `LIKE 'regular-%'` is left-anchored, so it reads the unique index as a
     * range rather than scanning the table.
     *
     * @param  class-string<Model>  $model
     * @return array<int, string>
     */
    private static function taken(string $model, string $column, string $desired, int|string|null $ignoreId): array
    {
        $query = $model::query()
            ->withoutGlobalScopes()
            ->where(function ($builder) use ($column, $desired): void {
                $builder->where($column, $desired)
                    ->orWhere($column, 'like', self::escapeLike($desired).'-%');
            });

        if ($ignoreId !== null) {
            $query->whereKeyNot($ignoreId);
        }

        return $query->pluck($column)
            ->filter(static fn (mixed $value): bool => is_string($value))
            ->values()
            ->all();
    }

    /**
     * The lowest number not already in use, starting at 2.
     *
     * Only strictly numeric suffixes count. `regular-deleted-7` and
     * `regular-webinar` both start with the prefix and neither is a numbered
     * variant, so neither may block `regular-2`.
     *
     * @param  array<int, string>  $taken
     */
    private static function firstFreeSuffix(string $desired, array $taken): int
    {
        $prefix = $desired.'-';
        $used = [];

        foreach ($taken as $value) {
            if (! str_starts_with($value, $prefix)) {
                continue;
            }

            $suffix = substr($value, strlen($prefix));

            if ($suffix !== '' && ctype_digit($suffix)) {
                $used[(int) $suffix] = true;
            }
        }

        $candidate = 2;

        while (isset($used[$candidate])) {
            $candidate++;
        }

        return $candidate;
    }

    /**
     * Neutralise LIKE's own wildcards.
     *
     * Slugs are sanitised before they reach here, but this also runs against
     * columns that are not — a name containing `%` would otherwise match
     * everything and the resolver would start inventing suffixes it did not
     * need.
     */
    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
