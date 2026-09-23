<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Post;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * One article.
 *
 * Route-model binding is deliberately not used: binding resolves any post by
 * slug and leaves the published check to be remembered here, which is exactly
 * §14 #9's shape. The query carries the rule instead, so a draft cannot be
 * reached by guessing its address.
 */
class PostController extends Controller
{
    public function __invoke(string $slug): View
    {
        $post = Post::query()
            ->live()
            ->where('slug', $slug)
            // The thread, two levels, in one go. Model::shouldBeStrict turns a
            // missed relation into an exception rather than a slow page, which
            // is the point of loading it here rather than in the view.
            ->with([
                'category', 'author', 'tags', 'media',
                'publishedComments.replies.author',
                'publishedComments.author',
            ])
            ->first();

        if ($post === null) {
            // A draft and an article that never existed look the same to a
            // reader. Saying "this exists but is not published" would leak what
            // the client is working on.
            throw new NotFoundHttpException;
        }

        return view('blog.show', ['post' => $post, 'related' => $post->related()]);
    }
}
