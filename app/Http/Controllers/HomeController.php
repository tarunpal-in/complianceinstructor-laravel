<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\MembershipPlan;
use App\Models\Page;
use App\Models\PageSection;
use App\Seo\Seo;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * The public homepage.
 *
 * Content and data shapes mirror design-ui/homepage-by-bolt/src/App.tsx, which
 * is the authoritative implementation of the Figma comp.
 *
 * CLAUDE.md §4 rule 2 — "Controllers route; Actions do" — so this holds no
 * logic.
 *
 * The homepage is a builder-driven `Page` (slug `home`): every section is a
 * `page_sections` block edited through the "Homepage Sections" screen (§2).
 * This reads the page's visible blocks and hands them to the view, which
 * dispatches each to its `home/blocks/*` partial. The dynamic blocks (webinars,
 * categories, faculty) resolve their own live records via HomepageContent; only
 * the pricing list is passed in, because it is always the live plans (§5.5).
 *
 * When the home page is absent (an unseeded install) the view falls back to the
 * `components/home/*` defaults, so the front door is never blank.
 */
class HomeController extends Controller
{
    public function __invoke(): View
    {
        $home = Page::query()
            ->where('slug', Page::HOME_SLUG)
            ->with('sections')
            ->first();

        return view('home.index', [
            'seo' => Seo::site('Professional Compliance Training'),
            // The homepage's blocks, keyed by type. A type is present only when
            // its block exists and is visible, so hiding a section in the editor
            // hides it here. `homeManaged` distinguishes "hidden" from "unseeded
            // install", the only case the view falls back to the comp defaults.
            'sections' => $this->sectionsOf($home),
            'homeManaged' => $home !== null,
            // The pricing cards are always the live plans (§5.5); the block only
            // edits the wrapper copy, so the list is resolved here and passed to
            // whichever renders — the block partial or the fallback component.
            'plans' => $this->plans(),
        ]);
    }

    /**
     * A page's visible sections, keyed by block type.
     *
     * @return Collection<string, PageSection>
     */
    private function sectionsOf(?Page $page): Collection
    {
        if ($page === null) {
            return collect();
        }

        return $page->sections
            ->where('is_visible', true)
            ->keyBy(fn (PageSection $section): string => $section->type);
    }

    /**
     * The membership tiers, from the database — §5.5.
     *
     * The card's quota badge is derived from `plan_entitlements`, so the
     * headline number on the pricing page and the allowance the subscriber
     * actually receives cannot drift apart. The tick-and-cross list underneath
     * is `highlights`, which is copy and enforces nothing.
     *
     * One thing the comp implies that nothing here expresses: the lower tiers
     * are limited to SHRM & HRCI credits while the upper two carry all
     * providers. On the card that is a bullet. If it is meant to be a rule, it
     * needs a real column, and the §17 offering matrix has to say so first.
     *
     * @return Collection<int, MembershipPlan>
     */
    private function plans(): Collection
    {
        return MembershipPlan::query()
            ->active()
            ->with('entitlements')
            ->get();
    }
}
