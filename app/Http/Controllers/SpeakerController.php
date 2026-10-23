<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CorePageKey;
use App\Models\Accreditation;
use App\Models\CorePage;
use App\Models\Industry;
use App\Models\Speaker;
use App\Seo\Seo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The faculty — CLAUDE.md §6, "Speakers | Index & profile" (design-ui/All
 * Speakers Page.png).
 *
 * §4 rule 2 keeps this thin. The `active` scope carries the rule about who the
 * public may see, so there is no is_active check to forget (§14 #9). Filters are
 * plain query strings — there is no Livewire in this app — which also keeps the
 * list shareable and canonicalisable.
 */
class SpeakerController extends Controller
{
    /** The sort options the list offers, and their labels. */
    private const SORTS = [
        'sessions' => 'Most sessions',
        'name' => 'Name (A–Z)',
    ];

    public function index(Request $request): View
    {
        $term = trim((string) $request->query('q'));

        // Topic = industry, Credit = accrediting body: both filter by what the
        // speaker's webinars actually carry, so the list cannot claim a speaker
        // teaches something they never have.
        $topic = Industry::query()->where('slug', (string) $request->query('topic'))->first();
        $creditCode = strtoupper(trim((string) $request->query('credit')));
        $sort = array_key_exists((string) $request->query('sort'), self::SORTS)
            ? (string) $request->query('sort')
            : 'sessions';

        $speakers = Speaker::query()
            ->active()
            ->when($term !== '', fn (Builder $query): Builder => $query->where(fn (Builder $inner): Builder => $inner
                ->where('name', 'like', "%{$term}%")
                ->orWhere('title', 'like', "%{$term}%")
                ->orWhere('credentials', 'like', "%{$term}%")))
            ->when($topic !== null, fn (Builder $query): Builder => $query
                ->whereHas('webinars', fn (Builder $w): Builder => $w->where('industry_id', $topic?->getKey())))
            ->when($creditCode !== '', fn (Builder $query): Builder => $query
                ->whereHas('webinars.accreditations', fn (Builder $a): Builder => $a->where('code', $creditCode)))
            ->with(['media', 'webinars.accreditations'])
            ->withCount('webinars')
            ->when($sort === 'name', fn (Builder $query): Builder => $query->orderBy('name'))
            ->when($sort === 'sessions', fn (Builder $query): Builder => $query
                ->orderByDesc('webinars_count')->orderBy('name'))
            ->get();

        $corePage = CorePage::for(CorePageKey::Speakers);

        return view('speakers.index', [
            // SEO from the editable core page (hero fallbacks when the SEO fields
            // are blank); a site default only if the row is somehow missing.
            'seo' => $corePage ? Seo::for($corePage) : Seo::site('Speakers'),
            'corePage' => $corePage,
            'speakers' => $speakers,
            'topics' => Industry::query()->where('is_active', true)->orderBy('name')->get(),
            'credits' => Accreditation::query()->where('is_active', true)->orderBy('code')->get(),
            'sorts' => self::SORTS,
            'filters' => [
                'q' => $term,
                'topic' => $topic?->slug,
                'credit' => $creditCode,
                'sort' => $sort,
            ],
        ]);
    }

    public function show(Speaker $speaker): View
    {
        // An inactive speaker keeps a slug and would otherwise resolve. 404
        // rather than 403: whether a draft profile exists is not public.
        if (! $speaker->is_active) {
            throw new NotFoundHttpException;
        }

        // Everything the profile reads from their sessions in one go — the
        // Live/Recorded tabs, topic domains, credits and counts all walk this.
        $speaker->load(['media', 'webinars' => fn ($query) => $query
            ->with(['accreditations', 'industry', 'cardMedia'])]);

        $crumbs = [
            ['name' => 'Home', 'url' => url('/')],
            ['name' => 'Our Speakers', 'url' => route('speakers.index')],
            ['name' => $speaker->name, 'url' => route('speakers.show', $speaker)],
        ];

        return view('speakers.show', [
            'seo' => Seo::for($speaker),
            'breadcrumbs' => $crumbs,
            'speaker' => $speaker,
        ]);
    }
}
