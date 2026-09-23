<?php

declare(strict_types=1);

namespace App\Actions\Comments;

use App\Enums\CommentStatus;
use App\Models\Comment;

/**
 * A moderator's decision about one comment.
 *
 * One way in, so every decision is stamped with who made it and when. §9 has
 * every mutation written to the activity log with the actor, and "who approved
 * this" is the first question after a complaint about something published under
 * the client's masthead.
 */
class ModerateComment
{
    public function __invoke(Comment $comment, CommentStatus $status, ?int $moderatorId = null): Comment
    {
        $comment->forceFill([
            'status' => $status,
            'moderated_at' => now(),
            'moderated_by' => $moderatorId ?? auth()->id(),
            /*
             * Approving clears the filter's guess. Leaving "Contains 3 links"
             * on a comment a person has since published would have the list
             * arguing with itself.
             */
            'spam_reason' => $status === CommentStatus::Approved ? null : $comment->spam_reason,
        ])->save();

        return $comment;
    }
}
