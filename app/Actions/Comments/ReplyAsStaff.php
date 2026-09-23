<?php

declare(strict_types=1);

namespace App\Actions\Comments;

use App\Enums\CommentStatus;
use App\Models\Comment;
use App\Models\User;
use RuntimeException;

/**
 * The client answering a commenter in public.
 *
 * Separate from SubmitComment on purpose. That action's whole job is to decide
 * whether a stranger's words are safe to publish; this one already knows — a
 * signed-in member of staff wrote them, and holding the client's own reply in a
 * queue for the client to approve is a loop with nobody outside it.
 *
 * So: published immediately, stamped as moderated by its author, and attached
 * to an account so the thread can mark it as coming from the site rather than
 * from another reader.
 */
class ReplyAsStaff
{
    public function __invoke(Comment $replyingTo, User $author, string $body): Comment
    {
        $body = trim($body);

        if ($body === '') {
            throw new RuntimeException('Write something first.');
        }

        /*
         * One level deep, matching the table. Replying to a reply would either
         * nest deeper than the thread renders or silently re-parent somebody
         * else's words, and both are worse than refusing.
         */
        if ($replyingTo->parent_id !== null) {
            throw new RuntimeException('Reply to the comment at the top of the thread instead.');
        }

        return Comment::create([
            'post_id' => $replyingTo->post_id,
            'parent_id' => $replyingTo->getKey(),
            'user_id' => $author->getKey(),
            'author_name' => $author->name,
            'author_email' => $author->email,
            'body' => $body,
            'status' => CommentStatus::Approved,
            'moderated_at' => now(),
            'moderated_by' => $author->getKey(),
        ]);
    }
}
