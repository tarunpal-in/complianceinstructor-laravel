<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CorePageKey;
use App\Models\CorePage;
use App\Seo\Seo;
use Illuminate\View\View;

/**
 * The contact page — now a core page (CLAUDE.md §6), not a page-builder page.
 *
 * The hero, extra copy blocks and SEO are edited under Core Pages; the contact
 * details come from the business settings (one place for the address customers
 * are told to write to), and the form is the shared Livewire intake that records
 * an inquiry through the single RecordInquiry action.
 */
class ContactController extends Controller
{
    public function __invoke(): View
    {
        $corePage = CorePage::for(CorePageKey::Contact);

        return view('contact.index', [
            'seo' => $corePage ? Seo::for($corePage) : Seo::site('Contact'),
            'corePage' => $corePage,
        ]);
    }
}
