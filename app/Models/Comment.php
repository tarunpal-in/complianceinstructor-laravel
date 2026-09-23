<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CommentStatus;
use Carbon\CarbonInterface;
use Database\Factories\CommentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * One comment on an article.
 *
 * @property CommentStatus $status
 * @property CarbonInterface|null $moderated_at
 */
class Comment extends Model
{
    /** @use HasFactory<CommentFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'post_id', 'parent_id', 'user_id', 'author_name', 'author_email', 'body',
        'status', 'spam_reason', 'ip_address', 'user_agent', 'moderated_at', 'moderated_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => CommentStatus::class,
            'moderated_at' => 'datetime',
        ];
    }

    /**
     * §9 — publishing somebody else's words under your own masthead is worth a
     * trail. "Who approved this" is the question that follows a complaint.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'spam_reason', 'moderated_by', 'body'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('comment');
    }

    // Relationships -------------------------------------------------------------

    /** @return BelongsTo<Post, $this> */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /** @return BelongsTo<Comment, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Comment::class, 'parent_id');
    }

    /**
     * Replies to this comment — approved only, because an unapproved reply
     * appearing under a published one is the leak the queue exists to prevent.
     *
     * @return HasMany<Comment, $this>
     */
    public function replies(): HasMany
    {
        return $this->hasMany(Comment::class, 'parent_id')
            ->where('status', CommentStatus::Approved)
            ->oldest();
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function moderatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderated_by');
    }

    // Scopes ----------------------------------------------------------------------

    /**
     * Comments a reader sees.
     *
     * @param  Builder<Comment>  $query
     */
    public function scopeApproved(Builder $query): void
    {
        $query->where('status', CommentStatus::Approved);
    }

    /** @param  Builder<Comment>  $query */
    public function scopeAwaitingReview(Builder $query): void
    {
        $query->where('status', CommentStatus::Pending);
    }

    /**
     * Top-level comments only, for rendering a thread.
     *
     * @param  Builder<Comment>  $query
     */
    public function scopeRoots(Builder $query): void
    {
        $query->whereNull('parent_id');
    }

    // State -------------------------------------------------------------------------

    public function isApproved(): bool
    {
        return $this->status === CommentStatus::Approved;
    }

    public function isFromStaff(): bool
    {
        return $this->author?->isCustomer() === false;
    }

    /** The first line, for a moderation list that has to stay scannable. */
    public function preview(int $length = 120): string
    {
        return Str::limit(trim(preg_replace('/\s+/', ' ', $this->body) ?? ''), $length);
    }

    /**
     * Whether this email has had a comment published before.
     *
     * What decides if their next one waits in the queue. Deliberately by email
     * rather than by IP: an office shares an address, and a returning commenter
     * on a different connection is still the same person as far as anybody
     * reading the blog is concerned.
     */
    public static function hasApprovedHistory(string $email): bool
    {
        return self::query()
            ->approved()
            ->where('author_email', Str::lower(trim($email)))
            ->exists();
    }
}
