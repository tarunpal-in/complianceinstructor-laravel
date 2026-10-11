<?php

declare(strict_types=1);

namespace App\Seo;

use App\Contracts\Seoable;
use App\Models\Media;
use App\Models\Page;
use App\Models\Post;
use App\Models\Webinar;
use App\Support\Business;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Finds the SEO problems a team would otherwise only learn about from Google —
 * the client's requested health dashboard (brief §1/§3/§13).
 *
 * Read-only reporting over the data Phase 1 already created: no new storage, just
 * the checks the brief lists, each pointed at the record to fix. The checks are
 * the ones worth a person's attention — a missing description, a title too long
 * to show, two pages fighting for the same title, an image with no alt text, a
 * page quietly set to noindex — not a lint of every field.
 */
final class SeoAudit
{
    /** Google truncates a title past roughly this, and a description past the next. */
    private const TITLE_MAX = 60;

    private const DESCRIPTION_MAX = 160;

    private const DESCRIPTION_MIN = 50;

    /**
     * Every finding, grouped by the check that produced it.
     *
     * @return array<string, array<int, SeoFinding>>
     */
    public static function run(): array
    {
        $records = self::records();

        return array_filter([
            'Missing meta description' => self::where($records, fn (SeoRecord $r): bool => $r->description === '', 'No description — search engines will quote a guess from the page.'),
            'Title too long' => self::where($records, fn (SeoRecord $r): bool => mb_strlen($r->title) > self::TITLE_MAX, fn (SeoRecord $r): string => mb_strlen($r->title).' characters — Google shows about '.self::TITLE_MAX.'.'),
            'Description too long' => self::where($records, fn (SeoRecord $r): bool => mb_strlen($r->description) > self::DESCRIPTION_MAX, fn (SeoRecord $r): string => mb_strlen($r->description).' characters — it will be cut off around '.self::DESCRIPTION_MAX.'.'),
            'Thin meta description' => self::where($records, fn (SeoRecord $r): bool => $r->description !== '' && mb_strlen($r->description) < self::DESCRIPTION_MIN, fn (SeoRecord $r): string => 'Only '.mb_strlen($r->description).' characters — too thin to be compelling.'),
            'Duplicate titles' => self::duplicateTitles($records),
            'Hidden from search (noindex)' => self::where($records, fn (SeoRecord $r): bool => str_contains(strtolower((string) $r->model->seoRobots()), 'noindex'), 'Deliberately hidden from search — confirm that is intended.'),
            'No focus keyword set' => self::where($records, fn (SeoRecord $r): bool => $r->model->seoFocusKeyword() === null, 'No focus keyword recorded for your own tracking.', 50),
            'Images missing ALT text' => self::imagesMissingAlt(),
        ], fn (array $findings): bool => $findings !== []);
    }

    public static function totalIssues(): int
    {
        return array_sum(array_map('count', self::run()));
    }

    /**
     * The public, SEO-editable records, each resolved once.
     *
     * @return array<int, SeoRecord>
     */
    private static function records(): array
    {
        $site = Business::name();
        $records = [];

        foreach (Webinar::query()->public()->with('seo')->get() as $webinar) {
            $records[] = self::describe($webinar, $webinar->title, $site);
        }

        foreach (Post::query()->live()->with('seo')->get() as $post) {
            $records[] = self::describe($post, $post->title, $site);
        }

        foreach (Page::query()->live()->with('seo')->get() as $page) {
            $records[] = self::describe($page, $page->title, $site);
        }

        return $records;
    }

    private static function describe(Model&Seoable $model, string $ownTitle, string $site): SeoRecord
    {
        return new SeoRecord(
            model: $model,
            title: $model->seoTitle() ?? trim($ownTitle.' — '.$site),
            description: trim((string) ($model->seoDescription() ?? $model->defaultSeoDescription() ?? '')),
            url: self::editUrl($model),
        );
    }

    /**
     * Filter the records by a predicate and turn each match into a finding.
     *
     * @param  array<int, SeoRecord>  $records
     * @param  callable(SeoRecord): bool  $matches
     * @param  string|callable(SeoRecord): string  $detail
     * @return array<int, SeoFinding>
     */
    private static function where(array $records, callable $matches, string|callable $detail, ?int $limit = null): array
    {
        $findings = [];

        foreach ($records as $record) {
            if (! $matches($record)) {
                continue;
            }

            $findings[] = self::finding($record, is_callable($detail) ? $detail($record) : $detail);

            if ($limit !== null && count($findings) >= $limit) {
                break;
            }
        }

        return $findings;
    }

    /**
     * @param  array<int, SeoRecord>  $records
     * @return array<int, SeoFinding>
     */
    private static function duplicateTitles(array $records): array
    {
        $counts = [];
        foreach ($records as $record) {
            $key = Str::lower($record->title);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return self::where(
            $records,
            fn (SeoRecord $r): bool => ($counts[Str::lower($r->title)] ?? 0) > 1,
            'This title is used on more than one page (brief §3 — no duplicate titles).',
        );
    }

    /** @return array<int, SeoFinding> */
    private static function imagesMissingAlt(): array
    {
        return Media::query()
            ->where('collection_name', '!=', '')
            ->latest('id')
            ->limit(200)
            ->get()
            ->filter(fn (Media $m): bool => $m->needsAltText())
            ->take(50)
            ->map(fn (Media $m): SeoFinding => new SeoFinding(
                subject: $m->name !== '' ? $m->name : (string) $m->file_name,
                detail: 'No ALT text — invisible to search and to screen readers.',
                url: null,
            ))
            ->values()
            ->all();
    }

    private static function finding(SeoRecord $record, string $detail): SeoFinding
    {
        return new SeoFinding(
            subject: class_basename($record->model).': '.Str::limit($record->title, 60),
            detail: $detail,
            url: $record->url,
        );
    }

    private static function editUrl(Model&Seoable $model): ?string
    {
        $resource = match ($model::class) {
            Webinar::class => \App\Filament\Resources\Webinars\WebinarResource::class,
            Post::class => \App\Filament\Resources\Posts\PostResource::class,
            Page::class => \App\Filament\Resources\Pages\PageResource::class,
            default => null,
        };

        if ($resource === null || ! $resource::canView($model)) {
            return null;
        }

        return $resource::getUrl('edit', ['record' => $model], panel: 'admin');
    }
}
