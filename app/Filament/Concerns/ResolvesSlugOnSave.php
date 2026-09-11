<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Support\UniqueSlug;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

/**
 * Saves as `regular-2` instead of refusing to save at all.
 *
 * A duplicate slug used to fail validation, which left whoever was saving to
 * work out for themselves who was holding the name. The save now always goes
 * through, on the next free form of what they typed.
 *
 * **It is never silent**, and that is the one place this departs from how
 * WordPress does the same thing. §3 puts the whole business on SEO and a slug
 * is a public URL, so typing `regular` and quietly getting `regular-2` is
 * something you would discover weeks later through a link that went somewhere
 * unexpected. A notification says what happened, every time it happens.
 *
 * Worth knowing what a collision now means. Before deleted records started
 * giving their names back, "already taken" was usually a record in the bin —
 * a phantom. It cannot be that any more, so a suffix here means there is
 * genuinely another live record of that name, and the notification is the only
 * thing that will tell you so.
 *
 * Deliberately not used for coupon codes. A code is advertised copy that
 * someone chose; turning SAVE20 into SAVE20-2 because it already exists is not
 * a helpful save, it is the wrong coupon. That one still refuses.
 */
trait ResolvesSlugOnSave
{
    private ?string $slugRequested = null;

    private ?string $slugSaved = null;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function resolveSlug(array $data, string $column = 'slug'): array
    {
        $desired = $data[$column] ?? null;

        if (! is_string($desired) || trim($desired) === '') {
            return $data;
        }

        /** @var class-string<\Illuminate\Database\Eloquent\Model> $model */
        $model = static::getResource()::getModel();

        /*
         * On edit, the record is excused from the check so it can keep its own
         * slug. On create there is no record yet to excuse.
         *
         * Asked by page type rather than by calling getRecord() and testing the
         * result: an edit page's getRecord() is not nullable, so a `?->` there
         * would read as a possibility that cannot happen.
         */
        $ignore = $this instanceof EditRecord ? $this->getRecord()->getKey() : null;

        $resolved = UniqueSlug::for($model, $column, trim($desired), $ignore);

        if ($resolved !== trim($desired)) {
            $this->slugRequested = trim($desired);
            $this->slugSaved = $resolved;
        }

        $data[$column] = $resolved;

        return $data;
    }

    /**
     * Say so, once the save has actually happened.
     *
     * Sent from afterCreate()/afterSave() rather than folded into the page's
     * own notification, because three of these resources already override
     * theirs to say something else — a webinar that cannot be published, a plan
     * that cannot be sold. Both messages matter and neither should displace
     * the other.
     */
    protected function notifyIfSlugChanged(): void
    {
        if ($this->slugSaved === null) {
            return;
        }

        Notification::make()
            ->warning()
            ->title('The web address was already in use')
            ->body(sprintf(
                '"%s" belongs to another record, so this one was saved as "%s". Edit the web address if you would rather it read differently.',
                $this->slugRequested,
                $this->slugSaved,
            ))
            ->persistent()
            ->send();

        $this->slugRequested = null;
        $this->slugSaved = null;
    }
}
