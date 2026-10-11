<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Post;
use App\Models\PostCategory;
use App\Seo\Seo;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The blog index — CLAUDE.md §6, "Index, filters, article, related".
 *
 * §4 rule 2 keeps this thin. The `live` scope carries the rule about what the
 * public may read, so there is no status check here to forget (§14 #9).
 */
class BlogController extends Controller
{
    public function __invoke(Request $request): View
    {
        $category = PostCategory::query()
            ->where('slug', (string) $request->query('category'))
            ->first();

        // A plain-text filter, so the custom 404's search box has somewhere to
        // go. A LIKE over title and excerpt is enough at this catalogue size;
        // Scout can take over if the archive ever outgrows it.
        $term = trim((string) $request->query('q'));

        $posts = Post::query()
            ->live()
            ->when($category !== null, fn ($query) => $query->where('post_category_id', $category?->getKey()))
            ->when($term !== '', fn ($query) => $query->where(function ($q) use ($term): void {
                $q->where('title', 'like', "%{$term}%")
                    ->orWhere('excerpt', 'like', "%{$term}%");
            }))
            ->with(['category', 'author', 'media'])
            ->latest('published_at')
            ->paginate(9)
            ->withQueryString();

        return view('blog.index', [
            // A category here is a ?category= filter, not its own path, so it
            // canonicalises to /blog/ (the query is stripped) — which is exactly
            // the duplicate-URL prevention brief §7 asks for on a filtered list.
            // The title still reflects the filter for a human; the editable
            // per-category SEO fields attach when categories get their own URLs.
            'seo' => $category !== null
                ? Seo::site($category->name.' articles', $category->description)
                : Seo::site('Blog'),
            'posts' => $posts,
            'categories' => PostCategory::query()->active()->get(),
            'activeCategory' => $category,
            'searchTerm' => $term,
        ]);
    }
}
