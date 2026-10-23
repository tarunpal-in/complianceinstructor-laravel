<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CorePageKey;
use App\Models\CorePage;
use App\Models\Industry;
use App\Seo\Seo;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * An industry landing page — CLAUDE.md §6 ("Industry landing pages").
 *
 * The same catalogue view as CatalogueController, with the industry's topic
 * preset so the page opens on that industry's webinars while the visitor can
 * still broaden the filters. An inactive industry is a 404: it is not something
 * the public is meant to reach.
 */
class IndustryController extends Controller
{
    /**
     * The industries listing — a hero (editable via Core Pages) over a grid of
     * active industries, each linking to its own landing page.
     */
    public function index(): View
    {
        $corePage = CorePage::for(CorePageKey::Industries);

        $industries = Industry::query()
            ->where('is_active', true)
            ->withCount(['webinars' => fn ($query) => $query->public()])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (Industry $industry): array => [
                'name' => $industry->name,
                'slug' => $industry->slug,
                'count' => (int) ($industry->webinars_count ?? 0),
                // A real card image if the industry has one, else the component
                // falls back to the comp's default asset.
                'image_url' => ($url = $industry->getFirstMediaUrl('card')) !== '' ? $url : null,
                'image' => 'seminar',
            ])
            ->all();

        return view('industries.index', [
            'seo' => $corePage ? Seo::for($corePage) : Seo::site('Industries'),
            'corePage' => $corePage,
            'industries' => $industries,
        ]);
    }

    public function show(Industry $industry): View
    {
        if (! $industry->is_active) {
            throw new NotFoundHttpException;
        }

        return view('webinars.index', [
            'seo' => Seo::site(
                $industry->name.' Webinars & Training',
                $industry->description ?: 'Continuing-education webinars for '.$industry->name.' professionals.',
            ),
            'heading' => $industry->name,
            'subheading' => $industry->description,
            'industrySlug' => $industry->slug,
        ]);
    }
}
