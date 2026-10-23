<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\Seoable;
use App\Enums\CorePageKey;
use App\Models\Concerns\HasSeo;
use Database\Factories\CorePageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * One editable "core" marketing page — CLAUDE.md §6.
 *
 * Speakers, Membership, Industries and Contact each keep their custom,
 * data-driven body (a speaker grid, plan cards, an industry grid, the contact
 * form). What a `CorePage` carries is the part that used to be hardcoded: the
 * hero (eyebrow / title / subtitle / background), its "extra copy blocks", the
 * membership CTA, and editable SEO. A fixed set keyed by {@see CorePageKey} —
 * rows are seeded and edited, never created or deleted from the UI.
 *
 * @property CorePageKey $key
 * @property string|null $hero_eyebrow
 * @property string $hero_title
 * @property string|null $hero_subtitle
 * @property int|null $hero_media_id
 * @property bool $hero_overlay
 * @property string|null $cta_label
 * @property string|null $cta_url
 * @property array<int, array{question?: string, answer?: string}>|null $faq
 */
class CorePage extends Model implements Seoable
{
    /** @use HasFactory<CorePageFactory> */
    use HasFactory, HasSeo, LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['hero_eyebrow', 'hero_title', 'hero_subtitle', 'hero_media_id', 'hero_overlay', 'cta_label', 'cta_url'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected $fillable = [
        'key', 'hero_eyebrow', 'hero_title', 'hero_subtitle',
        'hero_media_id', 'hero_overlay', 'cta_label', 'cta_url', 'faq',
    ];

    protected function casts(): array
    {
        return [
            'key' => CorePageKey::class,
            'hero_media_id' => 'integer',
            'hero_overlay' => 'boolean',
            'faq' => 'array',
        ];
    }

    /**
     * The FAQ rows with both a question and an answer, for the accordion.
     *
     * @return array<int, array{question: string, answer: string}>
     */
    public function faqItems(): array
    {
        return collect($this->faq ?? [])
            ->map(fn (array $item): array => [
                'question' => trim((string) ($item['question'] ?? '')),
                'answer' => trim((string) ($item['answer'] ?? '')),
            ])
            ->filter(fn (array $item): bool => $item['question'] !== '' && $item['answer'] !== '')
            ->values()
            ->all();
    }

    /**
     * The page for a key, with its hero image, copy blocks and SEO loaded.
     *
     * The single accessor every controller and view uses, so nothing reaches
     * for a core page by querying the table directly.
     */
    public static function for(CorePageKey|string $key): ?self
    {
        $value = $key instanceof CorePageKey ? $key->value : $key;

        return self::query()
            ->with(['heroMedia', 'sections', 'seo'])
            ->where('key', $value)
            ->first();
    }

    /** @return BelongsTo<Media, $this> */
    public function heroMedia(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'hero_media_id');
    }

    /** @return HasMany<CorePageSection, $this> */
    public function sections(): HasMany
    {
        return $this->hasMany(CorePageSection::class)->orderBy('sort_order')->orderBy('id');
    }

    /** The hero background URL, or null when none is picked. */
    public function heroImageUrl(): ?string
    {
        return $this->heroMedia?->detailImageUrl();
    }

    /** The CTA label, falling back to a sensible default when left blank. */
    public function ctaLabel(string $fallback = 'View memberships'): string
    {
        $label = trim((string) $this->cta_label);

        return $label !== '' ? $label : $fallback;
    }

    /** The CTA target, falling back to the key's own path when left blank. */
    public function ctaUrl(?string $fallback = null): string
    {
        $url = trim((string) $this->cta_url);

        return $url !== '' ? $url : ($fallback ?? $this->key->path());
    }

    // SEO fallbacks — editable overrides live in `seo_meta` via HasSeo ---------

    public function defaultSeoTitle(): string
    {
        return (string) $this->hero_title;
    }

    public function defaultSeoDescription(): ?string
    {
        $subtitle = trim((string) $this->hero_subtitle);

        return $subtitle === '' ? null : $subtitle;
    }

    /** The hero background is the page's own social image before the brand logo. */
    public function defaultSeoOgImageUrl(): ?string
    {
        return $this->heroImageUrl();
    }
}
