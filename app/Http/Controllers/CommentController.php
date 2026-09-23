<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Comments\SubmitComment;
use App\Enums\CommentStatus;
use App\Http\Requests\StoreCommentRequest;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A reader leaving a comment.
 *
 * §4 rule 2 — this routes, and SubmitComment does. Everything decided here is
 * about the HTTP round trip: which article, what the reader is told afterwards,
 * and where they land.
 */
class CommentController extends Controller
{
    public function __invoke(StoreCommentRequest $request, string $slug, SubmitComment $submit): RedirectResponse
    {
        $post = Post::query()->live()->where('slug', $slug)->first();

        if ($post === null || ! $post->acceptsComments()) {
            // Same answer for a draft, a missing article and a closed thread.
            // Distinguishing them would say what the client is working on.
            throw new NotFoundHttpException;
        }

        $user = $request->user();

        try {
            $comment = $submit(
                post: $post,
                authorName: (string) $request->validated('author_name'),
                authorEmail: (string) $request->validated('author_email'),
                body: (string) $request->validated('body'),
                replyingTo: $this->replyTarget($request),
                user: $user instanceof User ? $user : null,
                ipAddress: $request->ip(),
                userAgent: $request->userAgent(),
            );
        } catch (RuntimeException $exception) {
            // §14 #16 — the message is one this class wrote for a reader, never
            // a raw exception surfaced as the response.
            return back()->withInput()->withErrors(['body' => $exception->getMessage()]);
        }

        return redirect()
            ->to($post->path().'#comments')
            ->with('comment_status', $this->tell($comment));
    }

    /**
     * What the reader is told.
     *
     * Spam is deliberately indistinguishable from waiting. Telling somebody the
     * filter caught them is a free test harness for tuning past it, and the
     * occasional false positive is better served by "waiting" than by an
     * accusation.
     */
    private function tell(Comment $comment): string
    {
        return $comment->status === CommentStatus::Approved
            ? 'Thanks — your comment is up.'
            : 'Thanks — your comment will appear once it has been read.';
    }

    /**
     * The comment being replied to, if it is a real one.
     *
     * Resolved rather than bound, because the action re-checks that it belongs
     * to this article and is published. A stale id from an old page is a
     * refusal there, not a 404 here.
     */
    private function replyTarget(StoreCommentRequest $request): ?Comment
    {
        $parentId = $request->validated('parent_id');

        return $parentId === null ? null : Comment::query()->find($parentId);
    }
}
