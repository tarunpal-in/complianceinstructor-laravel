<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Str;

/**
 * @property string $name
 * @property string $slug
 * @property string|null $type
 *
 * CLAUDE.md §5.7 — shared by media now and by the blog at P7, so there is only
 * ever one tag vocabulary.
 *
 * `type` namespaces it: media tags do not clutter the blog's tag picker.
 */
class Tag extends Model
{
    /** @use HasFactory<\Database\Factories\TagFactory> */
    use HasFactory;

    public const TYPE_MEDIA = 'media';

    protected $fillable = ['name', 'slug', 'type'];

    /** @return MorphToMany<Media, $this> */
    public function media(): MorphToMany
    {
        return $this->morphedByMany(Media::class, 'taggable');
    }

    /**
     * Find or create by name within a type, so the client typing "Accreditation"
     * twice does not produce two tags.
     */
    public static function findOrCreateNamed(string $name, string $type = self::TYPE_MEDIA): self
    {
        $name = trim($name);

        return static::firstOrCreate(
            ['type' => $type, 'slug' => Str::slug($name)],
            ['name' => $name],
        );
    }
}
