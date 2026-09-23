<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Page;
use Illuminate\View\View;

/**
 * Shows a page before it is published.
 *
 * Reached only through a signed, expiring link (see Page::previewUrl and the
 * `signed` middleware on the route). Laravel rejects an unsigned or tampered
 * request before this runs, so there is no check to forget here — which is the
 * point of using the framework's signing rather than a `?preview=1` flag that
 * somebody would eventually trust.
 *
 * Deliberately renders exactly what publishing would render: hidden blocks stay
 * hidden. A preview that showed more than the real page would be no use for
 * deciding whether to publish it.
 */
class PagePreviewController extends Controller
{
    public function __invoke(Page $page): View
    {
        $page->load('visibleSections');

        return view('pages.show', [
            'page' => $page,
            // The banner is not decoration. Without it somebody sent this link
            // will believe the page is live, and say so to the client.
            'preview' => true,
        ]);
    }
}
