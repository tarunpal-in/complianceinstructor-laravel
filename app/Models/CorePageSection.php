<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CorePageSectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One "extra copy block" on a core page — CLAUDE.md §6.
 *
 * Ordered, optionally-titled rich-text content rendered around the page's
 * data-driven body, the same pattern as {@see WebinarSection}. The heading is
 * optional: a block with a body and no heading renders its body alone.
 *
 * @property string|null $heading
 * @property string|null $body
 * @property int $sort_order
 * @property bool $is_visible
 */
class CorePageSection extends Model
{
    /** @use HasFactory<CorePageSectionFactory> */
    use HasFactory;

    protected $fillable = [
        'core_page_id', 'heading', 'body', 'sort_order', 'is_visible',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_visible' => 'boolean',
        ];
    }

    /** @return BelongsTo<CorePage, $this> */
    public function corePage(): BelongsTo
    {
        return $this->belongsTo(CorePage::class);
    }

    /** Whether this block carries a heading, and so earns a title on the page. */
    public function hasHeading(): bool
    {
        return trim((string) $this->heading) !== '';
    }
}
