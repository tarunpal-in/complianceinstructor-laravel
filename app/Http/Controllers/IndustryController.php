<?php

declare(strict_types=1);

namespace App\Http\Controllers;

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
