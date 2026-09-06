<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\MediaFolder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MediaFolder>
 */
class MediaFolderFactory extends Factory
{
    protected $model = MediaFolder::class;

    public function definition(): array
    {
        $name = $this->faker->unique()->words(2, true);

        return [
            'name' => Str::title($name),
            'slug' => Str::slug($name),
            'parent_id' => null,
            'sort_order' => 0,
        ];
    }

    public function childOf(MediaFolder $parent): self
    {
        return $this->state(fn (): array => ['parent_id' => $parent->id]);
    }
}
