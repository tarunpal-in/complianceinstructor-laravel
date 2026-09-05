<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Format;
use App\Models\Webinar;
use App\Models\WebinarAsset;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WebinarAsset> */
class WebinarAssetFactory extends Factory
{
    protected $model = WebinarAsset::class;

    public function definition(): array
    {
        return [
            'webinar_id' => Webinar::factory(),
            'format' => Format::Recorded,
            'label' => null,
            'url' => fake()->url(),
            'disk_path' => null,
            'available_from' => null,
            'is_active' => true,
        ];
    }

    public function format(Format $format): static
    {
        return $this->state(fn (): array => ['format' => $format]);
    }

    /** Held back until a given moment — how a join link is released on schedule. */
    public function releasedAt(DateTimeInterface $when): static
    {
        return $this->state(fn (): array => ['available_from' => $when]);
    }
}
