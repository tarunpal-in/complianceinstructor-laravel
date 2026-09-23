<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\ReleasesUniqueNameOnDelete;
use Database\Factories\PostCategoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A blog category — CLAUDE.md §5.7.
 */
class PostCategory extends Model
{
    /** @use HasFactory<PostCategoryFactory> */
    use HasFactory, ReleasesUniqueNameOnDelete, SoftDeletes;

    protected $fillable = ['name', 'slug', 'description', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** @return HasMany<Post, $this> */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    /** @param  Builder<PostCategory>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true)->orderBy('sort_order')->orderBy('name');
    }

    public function path(): string
    {
        return '/'.Post::PATH_PREFIX.'?category='.$this->slug;
    }

    /**
     * How many live articles sit in it.
     *
     * Counted rather than stored, for the reason every other count in this
     * codebase is: a column drifts the first time a post is deleted, and a
     * category promising six articles and showing four is a worse look than no
     * number at all.
     */
    public function livePostCount(): int
    {
        return $this->posts()->live()->count();
    }
}
