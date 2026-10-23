<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Format;
use Database\Factories\OfferingFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

/**
 * The sellable SKU — CLAUDE.md §5.3.
 *
 * "the ONLY place a price is ever read from." Nothing here is ever taken from a
 * request: §14 #1 has the predecessor passing a hidden `final_amount` field
 * straight to PayPal, and every total in this build is recomputed from these
 * rows inside the order transaction.
 *
 * An offering grants one or more formats (docs/webinars-module.md §2.1). The
 * plural is the whole point — the purchase panel's four bundles cannot be
 * expressed otherwise, and §5.2 keys entitlements per format, so a three-format
 * bundle must fan out into three grants.
 */
class Offering extends Model
{
    /** @use HasFactory<OfferingFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'offerable_type', 'offerable_id', 'sku',
        'price_cents', 'compare_at_cents', 'addon_price_cents',
        'currency', 'seats_per_unit', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'compare_at_cents' => 'integer',
            'addon_price_cents' => 'integer',
            'seats_per_unit' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function offerable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return HasMany<OfferingFormat, $this> */
    public function formats(): HasMany
    {
        return $this->hasMany(OfferingFormat::class);
    }

    /**
     * The formats this offering grants, as enums.
     *
     * @return Collection<int, Format>
     */
    public function formatEnums(): Collection
    {
        return $this->formats->pluck('format');
    }

    /**
     * The label shown in the purchase panel — "Live + Recorded + Transcript".
     *
     * Composed rather than stored, so a bundle's name can never drift from what
     * it actually grants.
     */
    public function label(): string
    {
        $labels = $this->formatEnums()
            ->sortBy(fn (Format $format): int => array_search($format, Format::cases(), true))
            ->map(fn (Format $format): string => $format->label());

        return $labels->isEmpty() ? 'Untitled option' : $labels->join(' + ');
    }

    /**
     * The x-ui.icon shown beside this option in the purchase panel.
     *
     * A single-format offering wears that format's own icon; a multi-format
     * bundle wears a single generic `monitor`, so the combined options read as
     * one option rather than a cluster of per-format marks.
     */
    public function iconName(): string
    {
        $formats = $this->formatEnums();

        return $formats->count() === 1 ? $formats->first()->iconName() : 'monitor';
    }

    /**
     * The tooltip text, joined from each format's site-wide description.
     *
     * Site-wide by decision: "Recorded" means the same thing on every webinar,
     * so the client writes four descriptions once rather than retyping them
     * across the catalogue.
     */
    public function description(): string
    {
        return $this->formatEnums()
            ->map(fn (Format $format): string => (string) Setting::get(
                $format->descriptionSettingKey(),
                $format->defaultDescription(),
            ))
            ->filter()
            ->join(' ');
    }

    /**
     * What this costs, in cents.
     *
     * `$asAddon` is true when the buyer already has this webinar in the cart
     * under another offering — the cart's "Add Recorded for $55" against $199
     * standalone. Falls back to the full price when no add-on price is set, so
     * a missing value can never make something free.
     */
    public function priceCents(bool $asAddon = false): int
    {
        return $asAddon && $this->addon_price_cents !== null
            ? $this->addon_price_cents
            : $this->price_cents;
    }

    public function isAvailableAsAddon(): bool
    {
        return $this->addon_price_cents !== null;
    }

    /** @param  Builder<Offering>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Whether this offering grants the given format.
     */
    public function grants(Format $format): bool
    {
        return $this->formatEnums()->contains($format);
    }
}
