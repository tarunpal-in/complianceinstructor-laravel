<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Post;
use App\Models\PostCategory;
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

        $posts = Post::query()
            ->live()
            ->when($category !== null, fn ($query) => $query->where('post_category_id', $category?->getKey()))
            ->with(['category', 'author', 'media'])
            ->latest('published_at')
            ->paginate(9)
            ->withQueryString();

        return view('blog.index', [
            'posts' => $posts,
            'categories' => PostCategory::query()->active()->get(),
            'activeCategory' => $category,
        ]);
    }
}
