<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Format;
use App\Models\Offering;
use App\Models\Webinar;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Offering>
 *
 * States mirror the seven options in the purchase panel
 * (docs/webinars-module.md §2.1), including the four bundles that are the whole
 * reason formats live on a pivot.
 */
class OfferingFactory extends Factory
{
    protected $model = Offering::class;

    public function definition(): array
    {
        return [
            'offerable_type' => Webinar::class,
            'offerable_id' => Webinar::factory(),
            'sku' => Str::upper(Str::random(10)),
            'price_cents' => 19900,
            'compare_at_cents' => null,
            'addon_price_cents' => null,
            'currency' => 'USD',
            'seats_per_unit' => 1,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    /**
     * Attach the formats this offering grants.
     *
     * Uses afterCreating rather than a relationship state because the rows are
     * enum values on a pivot, not records in a `formats` table.
     */
    public function withFormats(Format ...$formats): static
    {
        return $this->afterCreating(function (Offering $offering) use ($formats): void {
            foreach ($formats as $format) {
                $offering->formats()->create(['format' => $format]);
            }
        });
    }

    public function priced(int $cents, ?int $addonCents = null): static
    {
        return $this->state(fn (): array => [
            'price_cents' => $cents,
            'addon_price_cents' => $addonCents,
        ]);
    }

    // The seven options in the panel -----------------------------------------

    public function live(): static
    {
        return $this->priced(19900)->withFormats(Format::Live)
            ->state(fn (): array => ['sort_order' => 10]);
    }

    public function recorded(): static
    {
        return $this->priced(19900, 5500)->withFormats(Format::Recorded)
            ->state(fn (): array => ['sort_order' => 20]);
    }

    public function transcript(): static
    {
        return $this->priced(17900, 5500)->withFormats(Format::Transcript)
            ->state(fn (): array => ['sort_order' => 30]);
    }

    public function recordedAndTranscript(): static
    {
        return $this->priced(27900)->withFormats(Format::Recorded, Format::Transcript)
            ->state(fn (): array => ['sort_order' => 40]);
    }

    public function liveAndTranscript(): static
    {
        return $this->priced(27900)->withFormats(Format::Live, Format::Transcript)
            ->state(fn (): array => ['sort_order' => 50]);
    }

    /** The three-format bundle — the case that must fan out into three grants. */
    public function everything(): static
    {
        return $this->priced(27900)
            ->withFormats(Format::Live, Format::Recorded, Format::Transcript)
            ->state(fn (): array => ['sort_order' => 60]);
    }

    public function download(): static
    {
        return $this->priced(27900, 5500)->withFormats(Format::Download)
            ->state(fn (): array => ['sort_order' => 70]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
