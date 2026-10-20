<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\WebinarType;
use App\Models\Accreditation;
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
                'time_label' => $w->scheduledTimeLabel() ?? '',
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
    /**
     * The next upcoming live-webinar dates, soonest first — for the hero's
     * rotating "Live Webinars Weekly · Next:" badge.
     *
     * Real dates from the catalogue, so the badge is never stale: a live session
     * that has passed drops off on the next page load, and a new one appears.
     *
     * @return list<string>
     */
    public static function liveWebinarDates(int $limit = 3): array
    {
        return Webinar::query()
            ->public()
            ->where('type', WebinarType::Live->value)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '>=', now())
            ->orderBy('scheduled_at')
            ->limit($limit)
            ->get()
            ->map(fn (Webinar $webinar): ?string => $webinar->scheduledDateLabel())
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Active accrediting bodies as homepage "Accreditation Partners" cards.
     *
     * Dynamic from the catalogue so the section shows the bodies the business
     * actually works with, with each one's uploaded logo where it has one.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function accreditations(int $limit = 6): array
    {
        return Accreditation::query()
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('code')
            ->limit($limit)
            ->get()
            ->map(fn (Accreditation $accreditation): array => [
                'code' => $accreditation->code,
                'badge' => $accreditation->description ?? '',
                'name' => $accreditation->name,
                'logo_url' => ($url = $accreditation->getFirstMediaUrl('logo')) !== '' ? $url : null,
            ])
            ->all();
    }

    /**
     * The active accrediting bodies' codes, for the rotating hero "Approved"
     * card — so it cycles through every body the business actually works with.
     *
     * @return list<string>
     */
    public static function accreditationCodes(int $limit = 6): array
    {
        return Accreditation::query()
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('code')
            ->limit($limit)
            ->pluck('code')
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    public static function industries(PageSection $block): array
    {
        // Count only the webinars a visitor can actually find in the catalogue,
        // so "N Courses" matches the industry landing page rather than counting
        // drafts and archived sessions.
        $query = Industry::query()->where('is_active', true)
            ->withCount(['webinars' => fn ($q) => $q->public()]);

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
                'slug' => $i->slug,
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
            ->map(fn (Speaker $s): array => self::speakerCard($s))
            ->values()
            ->all();
    }

    /**
     * Active speakers as faculty-card arrays, independent of a block.
     *
     * Lets the faculty section stay dynamic even on the fallback render path —
     * when the homepage has no `home_faculty` block to resolve — so the cards are
     * real speakers rather than the hardcoded demo wherever the catalogue has any.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function activeSpeakers(int $limit = 8): array
    {
        return Speaker::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->limit($limit)
            ->get()
            ->map(fn (Speaker $s): array => self::speakerCard($s))
            ->all();
    }

    /** @return array<string, mixed> */
    private static function speakerCard(Speaker $speaker): array
    {
        return [
            'name' => $speaker->name,
            'credentials' => $speaker->credentials ?: ($speaker->title ?? ''),
            'bodies' => $speaker->title ?? '',
            'image_url' => ($url = $speaker->getFirstMediaUrl('portrait')) !== '' ? $url : null,
            'image' => 'seminar',
            'url' => route('speakers.show', $speaker->slug),
        ];
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
