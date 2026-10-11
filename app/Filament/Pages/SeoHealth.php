<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Seo\SeoAudit;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use UnitEnum;

/**
 * The SEO health dashboard — the client's requested overview (brief §1/§3/§13).
 *
 * One screen answering "what needs fixing", scanned live from the content rather
 * than stored: missing descriptions, titles too long, duplicates, images with no
 * alt text, pages hidden from search. Each finding links to the record, so the
 * screen is a to-do list rather than a report.
 *
 * Gated on `view_any_seo_meta`, which the Content Editor holds — this is their
 * job, the same team that edits the per-page SEO fields it reports on.
 */
class SeoHealth extends Page
{
    protected static string|BackedEnum|null $navigationIcon = \Filament\Support\Icons\Heroicon::OutlinedMagnifyingGlassCircle;

    protected static string|null|UnitEnum $navigationGroup = 'Content';

    protected static ?int $navigationSort = 46;

    protected static ?string $navigationLabel = 'SEO health';

    protected static ?string $title = 'SEO health';

    protected static ?string $slug = 'seo-health';

    protected string $view = 'filament.pages.seo-health';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view_any_seo_meta') ?? false;
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Checked live across your webinars, articles and pages. Fix a finding and it clears on the next visit.';
    }

    /**
     * @return array<string, array<int, \App\Seo\SeoFinding>>
     */
    public function findings(): array
    {
        return SeoAudit::run();
    }

    public function totalIssues(): int
    {
        return SeoAudit::totalIssues();
    }
}
