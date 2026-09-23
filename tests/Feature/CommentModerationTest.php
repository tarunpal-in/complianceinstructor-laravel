<?php

declare(strict_types=1);

use App\Actions\Comments\ReplyAsStaff;
use App\Actions\Comments\SubmitComment;
use App\Enums\CommentStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Comments\Pages\ListComments;
use App\Http\Requests\StoreCommentRequest;
use App\Models\Comment;
use App\Models\Post;
use App\Support\CommentSpamCheck;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

/** Posting the form the article renders, with the honeypot left alone. */
function leaveComment(Post $post, array $overrides = []): Illuminate\Testing\TestResponse
{
    return test()->post('/blog/'.$post->slug.'/comments', [
        'author_name' => 'Dana Whitfield',
        'author_email' => 'dana@example.com',
        'body' => 'A genuine remark about the article, long enough to read like one.',
        ...$overrides,
    ]);
}

// What reaches the site -----------------------------------------------------------

it('holds a first-time commenter for review', function (): void {
    $post = Post::factory()->published()->create();

    leaveComment($post)->assertRedirect();

    $comment = Comment::query()->sole();

    expect($comment->status)->toBe(CommentStatus::Pending)
        ->and($post->fresh()->publishedComments)->toBeEmpty();
});

/*
 * The whole moderation policy, in one test: history buys a free pass, and
 * nothing else does. This is what makes a blog feel alive without anybody
 * being able to post unvetted on day one.
 */
it('publishes a returning commenter straight away', function (): void {
    $post = Post::factory()->published()->create();

    Comment::factory()->on($post)->approved()->from('dana@example.com')->create();

    leaveComment($post);

    expect(Comment::query()->latest('id')->firstOrFail()->status)->toBe(CommentStatus::Approved);
});

it('matches history on the email whatever case it was typed in', function (): void {
    $post = Post::factory()->published()->create();

    Comment::factory()->on($post)->approved()->from('dana@example.com')->create();

    leaveComment($post, ['author_email' => '  DANA@Example.COM ']);

    expect(Comment::query()->latest('id')->firstOrFail()->status)->toBe(CommentStatus::Approved);
});

/** History must not buy a way past the filter — a sold account is still spam. */
it('sends a returning commenter to spam when the comment is spam', function (): void {
    $post = Post::factory()->published()->create();

    Comment::factory()->on($post)->approved()->from('dana@example.com')->create();

    leaveComment($post, ['body' => 'Cheap replica watches http://a.test http://b.test http://c.test']);

    expect(Comment::query()->latest('id')->firstOrFail()->status)->toBe(CommentStatus::Spam);
});

it('tells a spammer the same thing it tells everybody else', function (): void {
    $post = Post::factory()->published()->create();

    leaveComment($post, ['body' => 'viagra http://a.test http://b.test http://c.test'])
        ->assertSessionHas('comment_status', 'Thanks — your comment will appear once it has been read.');
});

// The gate on the article ----------------------------------------------------------

it('refuses a comment on an article that is not published', function (): void {
    $post = Post::factory()->create();

    leaveComment($post)->assertNotFound();

    expect(Comment::query()->count())->toBe(0);
});

it('refuses a comment when the thread is closed', function (): void {
    $post = Post::factory()->published()->create(['comments_enabled' => false]);

    leaveComment($post)->assertNotFound();
});

it('shows the closed notice instead of the form', function (): void {
    $post = Post::factory()->published()->create(['comments_enabled' => false]);

    $this->get($post->path())
        ->assertSuccessful()
        ->assertSee('Comments are closed on this article.')
        ->assertDontSee('Leave a comment');
});

// The obvious ways in --------------------------------------------------------------

it('drops a comment that filled in the honeypot', function (): void {
    $post = Post::factory()->published()->create();

    leaveComment($post, [StoreCommentRequest::TRAP_FIELD => 'http://spam.test'])
        ->assertSessionHasErrors(StoreCommentRequest::TRAP_FIELD);

    expect(Comment::query()->count())->toBe(0);
});

it('throttles somebody posting faster than a person reads', function (): void {
    $post = Post::factory()->published()->create();

    foreach (range(1, 5) as $attempt) {
        leaveComment($post, ['body' => 'Remark number '.$attempt.', said at length.'])->assertRedirect();
    }

    leaveComment($post, ['body' => 'One more, which should not land.'])->assertStatus(429);
});

/** §14 #2 — where a comment lands is never decided by the request. */
it('ignores a status supplied with the form', function (): void {
    $post = Post::factory()->published()->create();

    leaveComment($post, ['status' => CommentStatus::Approved->value]);

    expect(Comment::query()->sole()->status)->toBe(CommentStatus::Pending);
});

// Replies -----------------------------------------------------------------------------

it('accepts a reply to a published comment', function (): void {
    $post = Post::factory()->published()->create();
    $parent = Comment::factory()->on($post)->approved()->create();

    leaveComment($post, ['parent_id' => $parent->getKey()])->assertRedirect();

    expect(Comment::query()->latest('id')->firstOrFail()->parent_id)->toBe($parent->getKey());
});

it('refuses a reply to something that was never published', function (): void {
    $post = Post::factory()->published()->create();
    $parent = Comment::factory()->on($post)->create();

    leaveComment($post, ['parent_id' => $parent->getKey()])
        ->assertSessionHasErrors('body');

    expect(Comment::query()->count())->toBe(1);
});

it('refuses a reply pointed at another article', function (): void {
    $post = Post::factory()->published()->create();
    $elsewhere = Comment::factory()->approved()->create();

    leaveComment($post, ['parent_id' => $elsewhere->getKey()])
        ->assertSessionHasErrors('body');
});

it('keeps replies one level deep', function (): void {
    $post = Post::factory()->published()->create();
    $parent = Comment::factory()->on($post)->approved()->create();
    $reply = Comment::factory()->replyTo($parent)->approved()->create();

    leaveComment($post, ['parent_id' => $reply->getKey()])
        ->assertSessionHasErrors('body');
});

// What a reader sees ---------------------------------------------------------------------

it('shows approved comments on the article and nothing else', function (): void {
    $post = Post::factory()->published()->create();

    Comment::factory()->on($post)->approved()->create(['body' => 'A published remark.']);
    Comment::factory()->on($post)->create(['body' => 'Still waiting to be read.']);
    Comment::factory()->on($post)->spam()->create(['body' => 'Buy something dreadful.']);

    $this->get($post->path())
        ->assertSuccessful()
        ->assertSee('A published remark.')
        ->assertDontSee('Still waiting to be read.')
        ->assertDontSee('Buy something dreadful.');
});

it('does not leak an unapproved reply under an approved comment', function (): void {
    $post = Post::factory()->published()->create();
    $parent = Comment::factory()->on($post)->approved()->create();

    Comment::factory()->replyTo($parent)->create(['body' => 'An unreviewed reply.']);

    $this->get($post->path())->assertDontSee('An unreviewed reply.');
});

it('escapes what a commenter wrote', function (): void {
    $post = Post::factory()->published()->create();

    Comment::factory()->on($post)->approved()->create([
        'body' => 'Look at this <script>alert(1)</script>',
    ]);

    $this->get($post->path())->assertDontSee('<script>alert(1)</script>', escape: false);
});

// Moderation -------------------------------------------------------------------------------

it('lets a content editor open the queue', function (): void {
    $this->actingAs(userWithRole(UserRole::ContentEditor))
        ->get('/admin/comments')
        ->assertSuccessful();
});

it('keeps roles without a comment permission out', function (UserRole $role): void {
    $this->actingAs(userWithRole($role))
        ->get('/admin/comments')
        ->assertForbidden();
})->with([UserRole::Finance, UserRole::Support]);

it('opens on the queue rather than on everything', function (): void {
    $post = Post::factory()->published()->create();

    $waiting = Comment::factory()->on($post)->create(['body' => 'Waiting to be read.']);
    $up = Comment::factory()->on($post)->approved()->create(['body' => 'Already up.']);

    Livewire\Livewire::actingAs(userWithRole(UserRole::ContentEditor))
        ->test(ListComments::class)
        ->assertCanSeeTableRecords([$waiting])
        ->assertCanNotSeeTableRecords([$up])
        ->set('activeTab', 'approved')
        ->assertCanSeeTableRecords([$up])
        ->assertCanNotSeeTableRecords([$waiting]);
});

it('publishes a comment and records who did it', function (): void {
    $editor = userWithRole(UserRole::ContentEditor);
    $comment = Comment::factory()->create();

    $this->actingAs($editor);

    Livewire\Livewire::test(ListComments::class)
        ->callTableAction('approve', $comment);

    $comment->refresh();

    expect($comment->status)->toBe(CommentStatus::Approved)
        ->and($comment->moderated_by)->toBe($editor->getKey())
        ->and($comment->moderated_at)->not->toBeNull();
});

/** Publishing something the filter flagged should not leave the flag on it. */
it('clears the spam reason when a moderator overrules the filter', function (): void {
    $editor = userWithRole(UserRole::ContentEditor);
    $comment = Comment::factory()->spam()->create();

    $this->actingAs($editor);

    Livewire\Livewire::test(ListComments::class)
        ->set('activeTab', 'spam')
        ->callTableAction('approve', $comment);

    expect($comment->refresh()->spam_reason)->toBeNull();
});

it('moderates a selection in one go', function (): void {
    $editor = userWithRole(UserRole::ContentEditor);
    $comments = Comment::factory()->count(3)->create();

    $this->actingAs($editor);

    Livewire\Livewire::test(ListComments::class)
        ->callTableBulkAction('approveAll', $comments);

    expect(Comment::query()->where('status', CommentStatus::Approved)->count())->toBe(3);
});

it('writes every decision to the audit trail', function (): void {
    $editor = userWithRole(UserRole::ContentEditor);
    $comment = Comment::factory()->create();

    $this->actingAs($editor);

    Livewire\Livewire::test(ListComments::class)->callTableAction('approve', $comment);

    expect($comment->activities()->where('description', 'updated')->exists())->toBeTrue();
});

it('offers no way to edit somebody else\'s words', function (): void {
    expect(App\Filament\Resources\Comments\CommentResource::getPages())
        ->not->toHaveKey('edit')
        ->not->toHaveKey('create');
});

// Staff replies -----------------------------------------------------------------------------

it('publishes a staff reply immediately', function (): void {
    $editor = userWithRole(UserRole::ContentEditor);
    $parent = Comment::factory()->approved()->create();

    $reply = app(ReplyAsStaff::class)($parent, $editor, 'Thanks — the recording goes out on Friday.');

    expect($reply->status)->toBe(CommentStatus::Approved)
        ->and($reply->parent_id)->toBe($parent->getKey())
        ->and($reply->post_id)->toBe($parent->post_id)
        ->and($reply->isFromStaff())->toBeTrue();
});

it('refuses a staff reply to a reply', function (): void {
    $editor = userWithRole(UserRole::ContentEditor);
    $parent = Comment::factory()->approved()->create();
    $reply = Comment::factory()->replyTo($parent)->approved()->create();

    expect(fn () => app(ReplyAsStaff::class)($reply, $editor, 'Nested too deep.'))
        ->toThrow(RuntimeException::class);
});

// The filter ------------------------------------------------------------------------------------

it('does not trip over a word that contains a spam word', function (): void {
    // The classic false positive, and on a compliance blog "specialist" is
    // in roughly every other comment.
    expect(CommentSpamCheck::reason('Our compliance specialist reviewed the policy.', 'Dana'))->toBeNull();
});

it('catches link stuffing', function (): void {
    expect(CommentSpamCheck::reason('http://a.test http://b.test http://c.test great', 'Dana'))
        ->toBe('Contains 3 links.');
});

it('catches a name that is a web address', function (): void {
    expect(CommentSpamCheck::reason('Nice article, thanks for writing it.', 'http://buy.test'))
        ->toBe('The name is a web address.');
});

// The action's own refusals ---------------------------------------------------------------------

it('refuses an empty comment at the action', function (): void {
    $post = Post::factory()->published()->create();

    expect(fn () => app(SubmitComment::class)($post, 'Dana', 'dana@example.com', '   '))
        ->toThrow(RuntimeException::class);
});

it('truncates a crafted user agent rather than failing the insert', function (): void {
    $post = Post::factory()->published()->create();

    $comment = app(SubmitComment::class)(
        post: $post,
        authorName: 'Dana',
        authorEmail: 'dana@example.com',
        body: 'A perfectly ordinary remark.',
        userAgent: str_repeat('x', 5000),
    );

    expect(strlen((string) $comment->user_agent))->toBeLessThanOrEqual(255);
});
