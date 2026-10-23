<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\OrderStatus;
use App\Enums\WebinarStatus;
use App\Enums\WebinarType;
use App\Models\Accreditation;
use App\Models\Industry;
use App\Models\Webinar;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

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
    use WithPagination;

    /** Cards per page — three full rows of the four-column grid. */
    private const PER_PAGE = 12;

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
    public string $sort = 'newest';

    /** Free-text search, set from the header search box (?q=…). */
    #[Url(as: 'q')]
    public string $q = '';

    public function mount(): void
    {
        if ($this->industrySlug !== null && $this->topics === []) {
            $this->topics = [$this->industrySlug];
        }
    }

    /**
     * Our own high-contrast pager instead of livewire::tailwind, whose `dark:`
     * variants render dark pills on this fixed-light site under OS dark-mode.
     */
    public function paginationView(): string
    {
        return 'pagination.catalogue';
    }

    public function clearFilters(): void
    {
        $this->reset(['type', 'topics', 'credits', 'duration', 'maxPrice', 'sort', 'q']);
        $this->resetPage();
    }

    /**
     * Any filter or sort change returns to page one — otherwise a narrower
     * result set could leave the viewer stranded on a page that no longer
     * exists, staring at an empty grid.
     */
    public function updated(string $property): void
    {
        if ($property !== 'page') {
            $this->resetPage();
        }
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
            'total' => $webinars->total(),
        ]);
    }

    /** @return LengthAwarePaginator<int, Webinar> */
    private function results(): LengthAwarePaginator
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

        return $query->paginate(self::PER_PAGE);
    }

    /** @param  Builder<Webinar>  $query */
    private function applySort(Builder $query): void
    {
        match ($this->sort) {
            'soonest' => $query->orderByRaw('scheduled_at is null, scheduled_at asc'),
            // Most popular by real sales: how many times the webinar has been
            // bought on a settled order — the same Paid/PartiallyRefunded set as
            // Order::scopeSettled, inlined here because the scope cannot be typed
            // through a nested whereHas closure. Most first; ties and never-sold
            // webinars fall back to newest session first.
            'popular' => $query
                ->withCount(['orderItems as purchases_count' => fn (Builder $items) => $items->whereHas('order', fn (Builder $order) => $order->whereIn('status', [OrderStatus::Paid->value, OrderStatus::PartiallyRefunded->value]))])
                ->orderByDesc('purchases_count')
                ->orderByRaw('scheduled_at is null, scheduled_at desc')
                ->orderByDesc('id'),
            // "Newest" and the default both order by the session date, not
            // created_at: every imported webinar shares one import timestamp, so
            // created_at cannot order them — the session date is the real measure
            // of how recent one is. The catalogue must not open on 2021.
            default => $query->orderByRaw('scheduled_at is null, scheduled_at desc')->orderByDesc('id'),
        };
    }
}
