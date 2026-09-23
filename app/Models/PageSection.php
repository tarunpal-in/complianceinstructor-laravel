<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PageSectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One block on a page — CLAUDE.md §5.6.
 *
 * @property array<string, mixed>|null $data
 */
class PageSection extends Model
{
    /** @use HasFactory<PageSectionFactory> */
    use HasFactory;

    protected $fillable = ['page_id', 'type', 'data', 'sort_order', 'is_visible'];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'sort_order' => 'integer',
            'is_visible' => 'boolean',
        ];
    }

    /** @return BelongsTo<Page, $this> */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    /**
     * One field out of the block's data.
     *
     * Everything reading a block goes through here rather than touching the
     * array, because `data` is whatever that block's form produced — a field
     * added last week is absent from every row saved before it, and a partial
     * that assumed otherwise would take a live page down.
     */
    public function value(string $key, mixed $default = null): mixed
    {
        return data_get($this->data, $key, $default);
    }

    /**
     * A repeated group out of the block's data, as a list.
     *
     * Filament's repeaters key their rows by uuid, which is meaningless to a
     * template and changes nothing about the order. Stripped here so a partial
     * can simply loop.
     *
     * @return array<int, array<string, mixed>>
     */
    public function rows(string $key): array
    {
        $rows = $this->value($key, []);

        if (! is_array($rows)) {
            return [];
        }

        return array_values(array_filter($rows, 'is_array'));
    }

    /** Whether this block has anything worth drawing. */
    public function isEmpty(): bool
    {
        return blank(array_filter(
            (array) $this->data,
            static fn (mixed $value): bool => filled($value),
        ));
    }
}
