<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Seo\Seo;
use Illuminate\View\View;

/**
 * The public webinar catalogue — CLAUDE.md §6 / §7 P2.
 *
 * §4 rule 2 keeps this thin: it renders the page chrome (a server-rendered hero,
 * for SEO) and hands the filtered grid to the WebinarCatalogue Livewire
 * component embedded in the view. The industry page shares the same view through
 * IndustryController, which presets the topic.
 */
class CatalogueController extends Controller
{
    public function index(): View
    {
        return view('webinars.index', [
            'seo' => Seo::site(
                'Webinars & Training',
                'Browse continuing-education webinars across compliance, HR, payroll and leadership — filter by format, topic, accrediting body and more.',
            ),
            'heading' => 'Webinars & Training',
            'subheading' => 'CPE-accredited webinars across compliance, HR, payroll, and leadership.',
            'industrySlug' => null,
        ]);
    }
}
