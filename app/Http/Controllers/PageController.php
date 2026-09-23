<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Page;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves a page the client built — CLAUDE.md §5.6.
 *
 * §4 rule 2 keeps this thin: it finds a live page and hands it to a view. The
 * scope decides what "live" means, so a draft cannot be reached by guessing its
 * address, and a scheduled one stays hidden until its date.
 *
 * Route-model binding is deliberately *not* used. Binding would resolve any
 * page by slug and leave the status check to be remembered here — which is
 * exactly the shape of §14 #9, a rule enforced at one call site and forgotten
 * at the next. The query carries the rule instead.
 */
class PageController extends Controller
{
    public function __invoke(string $slug): View
    {
        $page = Page::query()
            ->live()
            ->where('slug', $slug)
            ->with('visibleSections')
            ->first();

        if ($page === null) {
            // A draft and a page that never existed are the same thing to a
            // visitor. Saying "this exists but you may not see it" would leak
            // what the client is working on.
            throw new NotFoundHttpException;
        }

        return view('pages.show', ['page' => $page]);
    }
}
