<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\WebinarSectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One content block on a webinar's detail page — CLAUDE.md §6.
 *
 * Replaces the four fixed content columns with ordered, renamable rows, the
 * same way `page_sections` replaced ~35 CMS models (§2). The heading is
 * optional: a section with a body and no heading renders its body but gets no
 * page tab, which is how the migrated delivery notice is stored.
 *
 * @property string|null $heading
 * @property string|null $body
 * @property int $sort_order
 * @property bool $is_visible
 */
class WebinarSection extends Model
{
    /** @use HasFactory<WebinarSectionFactory> */
    use HasFactory;

    protected $fillable = [
        'webinar_id', 'heading', 'body', 'sort_order', 'is_visible',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_visible' => 'boolean',
        ];
    }

    /** @return BelongsTo<Webinar, $this> */
    public function webinar(): BelongsTo
    {
        return $this->belongsTo(Webinar::class);
    }

    /** Whether this section carries a heading, and so earns a page tab. */
    public function hasHeading(): bool
    {
        return trim((string) $this->heading) !== '';
    }
}
