<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\NavigationMenu;
use App\Models\NavigationItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NavigationItem>
 */
class NavigationItemFactory extends Factory
{
    protected $model = NavigationItem::class;

    public function definition(): array
    {
        return [
            'menu' => NavigationMenu::Header,
            'parent_id' => null,
            'label' => fake()->words(2, true),
            'url' => '/'.fake()->slug(),
            'sort_order' => 0,
            'is_active' => true,
            'open_in_new_tab' => false,
        ];
    }

    public function footer(): static
    {
        return $this->state(fn (): array => ['menu' => NavigationMenu::Footer]);
    }

    public function heading(): static
    {
        return $this->state(fn (): array => ['url' => null]);
    }

    public function childOf(NavigationItem $parent): static
    {
        return $this->state(fn (): array => [
            'menu' => $parent->menu,
            'parent_id' => $parent->getKey(),
        ]);
    }
}
