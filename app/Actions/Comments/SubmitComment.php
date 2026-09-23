<?php

declare(strict_types=1);

namespace App\Actions\Comments;

use App\Enums\CommentStatus;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use App\Support\CommentSpamCheck;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Takes a comment from a reader — §4 rule 2, and §4 rule 3 in spirit: nothing
 * from the request reaches the row without passing through here.
 *
 * **Nothing is published by this action.** A first-time commenter waits in the
 * queue; somebody the client has already published goes straight up, which is
 * what makes a blog feel alive without anybody being able to post unvetted on
 * day one. That trade is the whole moderation policy, in one method.
 */
class SubmitComment
{
    public function __invoke(
        Post $post,
        string $authorName,
        string $authorEmail,
        string $body,
        ?Comment $replyingTo = null,
        ?User $user = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Comment {
        if (! $post->acceptsComments()) {
            throw new RuntimeException('Comments are closed on this article.');
        }

        if ($replyingTo !== null && ! $this->isReplyable($post, $replyingTo)) {
            // A reply to something unpublished, or on another article, is
            // either a stale page or somebody posting at the endpoint directly.
            throw new RuntimeException('That comment is no longer open for replies.');
        }

        $authorEmail = Str::lower(trim($authorEmail));
        $authorName = trim($authorName);
        $body = trim($body);

        if ($body === '') {
            throw new RuntimeException('Write something first.');
        }

        $spamReason = CommentSpamCheck::reason($body, $authorName);

        return Comment::create([
            'post_id' => $post->getKey(),
            'parent_id' => $replyingTo?->getKey(),
            'user_id' => $user?->getKey(),
            'author_name' => $authorName,
            'author_email' => $authorEmail,
            'body' => $body,
            'status' => $this->decide($spamReason, $authorEmail),
            'spam_reason' => $spamReason,
            'ip_address' => $ipAddress,
            // Truncated to the column: a crafted user agent must not be able to
            // fail the insert and take the comment form down with it.
            'user_agent' => $userAgent === null ? null : Str::limit($userAgent, 250, ''),
        ]);
    }

    /**
     * Where it lands.
     *
     * Spam first: a returning commenter whose latest remark is a link farm is
     * an account that has been sold or a name somebody borrowed, and history
     * should not buy a free pass past the filter.
     */
    private function decide(?string $spamReason, string $authorEmail): CommentStatus
    {
        if ($spamReason !== null) {
            return CommentStatus::Spam;
        }

        return Comment::hasApprovedHistory($authorEmail)
            ? CommentStatus::Approved
            : CommentStatus::Pending;
    }

    /** Replies attach to a published comment on the same article, one level deep. */
    private function isReplyable(Post $post, Comment $replyingTo): bool
    {
        return $replyingTo->post_id === $post->getKey()
            && $replyingTo->isApproved()
            && $replyingTo->parent_id === null;
    }
}
