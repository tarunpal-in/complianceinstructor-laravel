<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\WebinarStatus;
use App\Enums\WebinarType;
use App\Models\Accreditation;
use App\Models\Industry;
use App\Models\Webinar;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The public webinar catalogue — CLAUDE.md §6 / §7 P2 ("catalogue with Livewire
 * filtering: format, industry, date, accreditation").
 *
 * Embedded in the catalogue and industry pages rather than a full page of its
 * own, so the hero and SEO stay server-rendered Blade and only the filtered grid
 * is reactive. Every filter is URL-bound, so a filtered view is shareable and
 * the back button works — the catalogue is the most-linked surface on an SEO-led
 * site, and a filter that lived only in component state would break both.
 *
 * No price or access is decided here; this reads the public catalogue only.
 */
class WebinarCatalogue extends Component
{
    /** Preset by the industry page; its topic is checked on first load. */
    public ?string $industrySlug = null;

    #[Url(as: 'type')]
    public string $type = 'all';

    /** @var list<string> industry slugs */
    #[Url(as: 'topics')]
    public array $topics = [];

    /** @var list<string> accreditation codes */
    #[Url(as: 'credits')]
    public array $credits = [];

    #[Url(as: 'duration')]
    public string $duration = 'any';

    #[Url(as: 'max')]
    public ?int $maxPrice = null;

    #[Url(as: 'sort')]
    public string $sort = 'popular';

    /** Free-text search, set from the header search box (?q=…). */
    #[Url(as: 'q')]
    public string $q = '';

    public function mount(): void
    {
        if ($this->industrySlug !== null && $this->topics === []) {
            $this->topics = [$this->industrySlug];
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['type', 'topics', 'credits', 'duration', 'maxPrice', 'sort', 'q']);
    }

    public function render(): View
    {
        $industries = Industry::query()
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('name')
            ->withCount(['webinars' => fn (Builder $query) => $query->whereIn('status', WebinarStatus::publicValues())])
            ->get();

        $accreditations = Accreditation::query()
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('code')
            ->get();

        $webinars = $this->results();

        return view('livewire.webinar-catalogue', [
            'industries' => $industries,
            'accreditations' => $accreditations,
            'webinars' => $webinars,
            'total' => $webinars->count(),
        ]);
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Webinar> */
    private function results()
    {
        $query = Webinar::query()
            ->public()
            ->with(['speaker.media', 'accreditations', 'industry', 'cardMedia']);

        // Free-text search — title, summary, the speaker and the industry.
        if (($term = trim($this->q)) !== '') {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

            $query->where(function (Builder $outer) use ($like): void {
                $outer->where('title', 'like', $like)
                    ->orWhere('summary', 'like', $like)
                    ->orWhereHas('speaker', fn (Builder $speaker) => $speaker->where('name', 'like', $like))
                    ->orWhereHas('industry', fn (Builder $industry) => $industry->where('name', 'like', $like));
            });
        }

        // Format.
        if ($this->type === 'live') {
            $query->where('type', WebinarType::Live->value);
        } elseif ($this->type === 'recorded') {
            $query->where('type', WebinarType::Recorded->value);
        }

        // Topic (industry).
        if ($this->topics !== []) {
            $query->whereHas('industry', fn (Builder $industry) => $industry->whereIn('slug', $this->topics));
        }

        // Credit type (accreditation).
        if ($this->credits !== []) {
            $query->whereHas('accreditations', fn (Builder $accreditation) => $accreditation->whereIn('code', $this->credits));
        }

        // Duration.
        match ($this->duration) {
            'under60' => $query->where('duration_minutes', '<', 60),
            '60to90' => $query->whereBetween('duration_minutes', [60, 90]),
            'over90' => $query->where('duration_minutes', '>', 90),
            default => null,
        };

        // Max price — cheapest offering within budget (dollars in the UI, cents here).
        if ($this->maxPrice !== null && $this->maxPrice > 0) {
            $cents = $this->maxPrice * 100;
            $query->whereHas('offerings', fn (Builder $offering) => $offering
                ->where('is_active', true)
                ->where('price_cents', '<=', $cents));
        }

        $this->applySort($query);

        return $query->limit(60)->get();
    }

    /** @param  Builder<Webinar>  $query */
    private function applySort(Builder $query): void
    {
        match ($this->sort) {
            'soonest' => $query->orderByRaw('scheduled_at is null, scheduled_at asc'),
            'newest' => $query->orderByDesc('created_at'),
            // "Most popular" has no metric yet, so it is the sensible default:
            // upcoming sessions first, then the rest by recency.
            default => $query->orderByRaw('scheduled_at is null, scheduled_at asc')->orderByDesc('id'),
        };
    }
}
