<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Industry;
use App\Models\PageSection;
use App\Models\Speaker;
use App\Models\Webinar;
use Illuminate\Support\Str;

/**
 * Turns a homepage data-block into the live records it describes.
 *
 * The dynamic homepage sections (webinars, categories, faculty) store only how
 * to choose — "auto" with a limit, or a hand-picked list — never the records
 * themselves. This resolves that choice against the catalogue and maps each
 * record into the array shape its `components/home/*` partial already expects,
 * so a renamed webinar or a new speaker shows without anyone editing the
 * homepage (CLAUDE.md §2 / the P2 data swaps the controller flagged).
 *
 * Query logic lives here rather than in a Blade partial — §4 rule 2 keeps views
 * free of it — and here rather than the controller because each block resolves
 * itself independently.
 */
class HomepageContent
{
    /**
     * Upcoming webinars as home webinar-card arrays.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function webinars(PageSection $block): array
    {
        $query = Webinar::query()->public()->with(['speaker', 'accreditations']);

        if (self::isManual($block)) {
            $ids = self::chosenIds($block);
            $webinars = $query->whereIn('id', $ids)->get()
                ->sortBy(fn (Webinar $w): int => self::rank($ids, $w->getKey()))
                ->values();
        } else {
            // Auto: the next scheduled sessions, soonest first (the domain's own
            // "upcoming" rule — future date, ordered).
            $webinars = $query->upcoming()->limit(self::limit($block))->get();
        }

        return $webinars
            ->filter(fn (Webinar $w): bool => $w->scheduled_at !== null)
            ->map(fn (Webinar $w): array => [
                'title' => $w->title,
                'speaker' => $w->speaker->name ?: 'To be announced',
                'speaker_title' => $w->speaker->title ?? '',
                'speaker_initials' => self::initials($w->speaker->name),
                'accreditations' => $w->accreditations->pluck('code')->all(),
                'starts_at' => $w->scheduled_at,
                'time_label' => $w->scheduled_at?->timezone($w->timezone)->format('g:i A T') ?? '',
                'duration' => $w->duration_minutes ? $w->duration_minutes.' Min' : '',
                'url' => route('webinars.show', $w),
            ])
            ->values()
            ->all();
    }

    /**
     * Industries as home category-card arrays.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function industries(PageSection $block): array
    {
        $query = Industry::query()->where('is_active', true)->withCount('webinars');

        if (self::isManual($block)) {
            $ids = self::chosenIds($block);
            $industries = $query->whereIn('id', $ids)->get()
                ->sortBy(fn (Industry $i): int => self::rank($ids, $i->getKey()))
                ->values();
        } else {
            $industries = $query->orderBy('sort_order')->limit(self::limit($block))->get();
        }

        return $industries
            ->map(fn (Industry $i): array => [
                'name' => $i->name,
                'count' => (int) ($i->webinars_count ?? 0),
                // A real card image if the industry has one, else the comp's
                // default asset (the component falls back on a null url).
                'image_url' => ($url = $i->getFirstMediaUrl('card')) !== '' ? $url : null,
                'image' => 'seminar',
            ])
            ->values()
            ->all();
    }

    /**
     * Speakers as home faculty-card arrays.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function speakers(PageSection $block): array
    {
        $query = Speaker::query()->where('is_active', true);

        if (self::isManual($block)) {
            $ids = self::chosenIds($block);
            $speakers = $query->whereIn('id', $ids)->get()
                ->sortBy(fn (Speaker $s): int => self::rank($ids, $s->getKey()))
                ->values();
        } else {
            $speakers = $query->orderBy('name')->limit(self::limit($block))->get();
        }

        return $speakers
            ->map(fn (Speaker $s): array => [
                'name' => $s->name,
                'credentials' => $s->credentials ?: ($s->title ?? ''),
                'bodies' => $s->title ?? '',
                'image_url' => ($url = $s->getFirstMediaUrl('portrait')) !== '' ? $url : null,
                'image' => 'seminar',
            ])
            ->values()
            ->all();
    }

    private static function isManual(PageSection $block): bool
    {
        return $block->value('source') === 'manual';
    }

    private static function limit(PageSection $block): int
    {
        return max(1, min(12, (int) $block->value('limit', 3)));
    }

    /**
     * The record ids the client hand-picked, in order.
     *
     * whereIn with an empty list resolves to no rows, so an unset or empty pick
     * simply yields an empty section — no special-casing needed.
     *
     * @return array<int, int>
     */
    private static function chosenIds(PageSection $block): array
    {
        return array_values(array_filter(array_map(
            'intval',
            is_array($block->value('items')) ? $block->value('items') : [],
        )));
    }

    /**
     * A record's position in the hand-picked list, so the on-page order matches
     * the order the client chose.
     *
     * @param  array<int, int>  $ids
     */
    private static function rank(array $ids, int|string $key): int
    {
        $position = array_search((int) $key, $ids, true);

        return $position === false ? PHP_INT_MAX : $position;
    }

    /** Initials from a name — "Jane Q. Doe" → "JD". */
    private static function initials(?string $name): string
    {
        $name = trim((string) $name);

        if ($name === '') {
            return '';
        }

        $parts = preg_split('/\s+/', $name) ?: [];
        $first = Str::substr($parts[0] ?? '', 0, 1);
        $last = count($parts) > 1 ? Str::substr((string) end($parts), 0, 1) : '';

        return Str::upper($first.$last);
    }
}
