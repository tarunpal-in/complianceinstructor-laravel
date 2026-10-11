<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\Seoable;
use App\Enums\PublishStatus;
use App\Models\Concerns\HasSeo;
use App\Models\Concerns\ReleasesUniqueNameOnDelete;
use Carbon\CarbonInterface;
use Database\Factories\PostFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * A blog article — CLAUDE.md §5.7.
 *
 * @property PublishStatus $status
 * @property CarbonInterface|null $published_at
 */
class Post extends Model implements HasMedia, Seoable
{
    /** @use HasFactory<PostFactory> */
    use HasFactory, HasSeo, InteractsWithMedia, LogsActivity, ReleasesUniqueNameOnDelete, SoftDeletes;

    /** The blog's own prefix, so `/blog/{slug}` cannot collide with a page. */
    public const PATH_PREFIX = 'blog';

    /** Matches a page's, because a preview link is a preview link. */
    public const PREVIEW_LIFETIME_DAYS = 7;

    /**
     * Words a minute, for the reading estimate.
     *
     * 200 is the usual figure for adult non-fiction. It is an estimate shown to
     * a reader, not a number anything depends on — worth saying, because a
     * precise-looking "7 min read" invites somebody to treat it as one.
     */
    private const WORDS_PER_MINUTE = 200;

    protected $fillable = [
        'title', 'slug', 'excerpt', 'body', 'post_category_id', 'user_id',
        'status', 'published_at', 'comments_enabled',
    ];

    protected function casts(): array
    {
        return [
            'status' => PublishStatus::class,
            'published_at' => 'datetime',
            'comments_enabled' => 'boolean',
        ];
    }

    /** §9 — the client publishes these, and "who changed this" gets asked. */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['title', 'slug', 'post_category_id', 'user_id', 'status', 'published_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('post');
    }

    // Media -----------------------------------------------------------------------

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('cover')->singleFile();
    }

    /**
     * Two crops: a card on the index, and the banner on the article.
     *
     * Queued, so an upload does not block the request — §4 rule 7.
     */
    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('card')->fit(Fit::Crop, 640, 400);
        $this->addMediaConversion('banner')->fit(Fit::Crop, 1600, 700);
    }

    public function coverUrl(string $conversion = 'card'): ?string
    {
        $media = $this->getFirstMedia('cover');

        return $media?->hasGeneratedConversion($conversion) === true
            ? $media->getUrl($conversion)
            : $media?->getUrl();
    }

    // Relationships -----------------------------------------------------------------

    /** @return BelongsTo<PostCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(PostCategory::class, 'post_category_id');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Every comment, whatever state it is in — the moderation queue's end.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<Comment, $this>
     */
    public function comments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Comment::class);
    }

    /**
     * The thread a reader sees: published, top-level, oldest first.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<Comment, $this>
     */
    public function publishedComments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->comments()->approved()->roots()->oldest();
    }

    /**
     * Comments on this article still to be read.
     *
     * Its own relation rather than a constrained count on the list, so the blog
     * screen can say which article somebody is waiting on without that query
     * living in a column closure.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<Comment, $this>
     */
    public function commentsAwaitingReview(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->comments()->awaitingReview();
    }

    /**
     * Whether somebody may leave a comment.
     *
     * Both halves matter: an unpublished article has nothing to comment on, and
     * the per-article switch is how the client closes a thread that has gone
     * somewhere they do not want to moderate.
     */
    public function acceptsComments(): bool
    {
        return $this->comments_enabled && $this->isLive();
    }

    /**
     * What the article is about.
     *
     * The same `tags` table the media library uses, namespaced by type — one
     * tag vocabulary for the whole site, as the table's own comment intends.
     *
     * @return MorphToMany<Tag, $this>
     */
    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }

    // Scopes -------------------------------------------------------------------------

    /**
     * Posts the public may read.
     *
     * Published *and* past their date, in one place. §14 #9 — a rule enforced
     * at one call site and forgotten at the next is how a draft ends up on the
     * index page.
     *
     * @param  Builder<Post>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->where('status', PublishStatus::Published)
            ->where(fn (Builder $dated): Builder => $dated
                ->whereNull('published_at')
                ->orWhere('published_at', '<=', now()));
    }

    // State ---------------------------------------------------------------------------

    public function isLive(): bool
    {
        return $this->status->isPublic()
            && ($this->published_at === null || $this->published_at->isPast());
    }

    /** Published with a future date is scheduled, whatever the column says. */
    public function derivedStatus(): PublishStatus
    {
        if ($this->status !== PublishStatus::Published) {
            return $this->status;
        }

        return $this->published_at !== null && $this->published_at->isFuture()
            ? PublishStatus::Scheduled
            : PublishStatus::Published;
    }

    public function path(): string
    {
        return '/'.self::PATH_PREFIX.'/'.$this->slug;
    }

    /** A link that opens the article before it is published — see Page::previewUrl. */
    public function previewUrl(): string
    {
        return URL::temporarySignedRoute(
            'blog.preview',
            now()->addDays(self::PREVIEW_LIFETIME_DAYS),
            ['post' => $this->getKey()],
        );
    }

    public function viewableUrl(): string
    {
        return $this->isLive() ? $this->path() : $this->previewUrl();
    }

    /**
     * Why this post cannot go out — empty means it can.
     *
     * The same gate as a page and a webinar. An article with no body is a
     * headline and a byline over nothing, and it is found by a reader.
     *
     * @return array<int, string>
     */
    public function problems(): array
    {
        $problems = [];

        if (blank(trim(strip_tags($this->body ?? '')))) {
            $problems[] = 'It has no body text.';
        }

        if ($this->post_category_id === null) {
            // Not fatal to a reader, but §3 has the business living on SEO and
            // an uncategorised post is absent from the one page that would link
            // to it.
            $problems[] = 'It has no category, so nothing on the site will link to it.';
        }

        return $problems;
    }

    public function isPublishable(): bool
    {
        return $this->problems() === [];
    }

    // Reading ---------------------------------------------------------------------------

    /** Roughly how long the article takes to read. */
    public function readingMinutes(): int
    {
        $words = str_word_count(strip_tags((string) $this->body));

        return max(1, (int) ceil($words / self::WORDS_PER_MINUTE));
    }

    public function describeReadingTime(): string
    {
        return $this->readingMinutes().' min read';
    }

    /**
     * The excerpt, or the opening of the body where none was written.
     *
     * A fallback rather than a requirement: the client should not be blocked
     * from publishing because they did not write a summary, and an index card
     * with nothing on it is worse than a machine-cut sentence.
     */
    public function summary(int $length = 160): string
    {
        if (filled($this->excerpt)) {
            return Str::limit($this->excerpt, $length);
        }

        return Str::limit(trim(strip_tags((string) $this->body)), $length);
    }

    // SEO — CLAUDE.md §5.6 / client SEO brief -------------------------------

    /** The summary feeds the meta description when none is set. */
    public function defaultSeoDescription(): ?string
    {
        $summary = $this->summary(160);

        return $summary === '' ? null : $summary;
    }

    /** The cover image is the article's social image, not the logo (brief §13). */
    public function defaultSeoOgImageUrl(): ?string
    {
        return $this->coverUrl('banner');
    }

    /** The public path, so a slug change leaves a 301 behind (brief §4). */
    public function seoPathForSlug(string $slug): ?string
    {
        return '/'.self::PATH_PREFIX.'/'.$slug;
    }

    /**
     * Other articles a reader might want next.
     *
     * Same category, newest first, excluding this one. Deliberately simple —
     * §13 has the blog as a descope candidate, and "related by tag overlap
     * weighted by recency" is effort spent on the part of the site nothing
     * depends on.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Post>
     */
    public function related(int $limit = 3): \Illuminate\Database\Eloquent\Collection
    {
        return Post::query()
            ->live()
            ->whereKeyNot($this->getKey())
            ->when(
                $this->post_category_id !== null,
                fn (Builder $query): Builder => $query->where('post_category_id', $this->post_category_id),
            )
            ->with('category')
            ->latest('published_at')
            ->limit($limit)
            ->get();
    }
}
