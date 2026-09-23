<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Post;
use Illuminate\View\View;

/**
 * An article before it is published.
 *
 * Reached only through a signed, expiring link — Laravel rejects an unsigned or
 * tampered request before this runs, so there is no check to forget. The same
 * mechanism as a page preview rather than a second one.
 */
class PostPreviewController extends Controller
{
    public function __invoke(Post $post): View
    {
        $post->load(['category', 'author', 'tags', 'media']);

        return view('blog.show', [
            'post' => $post,
            'related' => $post->related(),
            // Without the banner, somebody sent this link believes it is live.
            'preview' => true,
        ]);
    }
}
