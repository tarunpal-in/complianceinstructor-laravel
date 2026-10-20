<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\NavigationMenu;
use App\Models\NavigationItem;
use App\Support\Navigation;
use Illuminate\Database\Seeder;

/**
 * Seeds the header and footer menus — CLAUDE.md §4 rule 1 / §5.6.
 *
 * Builds the rows from the same defaults the front end falls back to, so
 * migrate:fresh --seed reproduces exactly the menus that were hardcoded before
 * the navigation manager existed, and the client edits from there. Idempotent:
 * a menu that already has rows is left alone.
 */
class NavigationSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedMenu(NavigationMenu::Header, Navigation::defaultHeader());
        $this->seedMenu(NavigationMenu::Footer, Navigation::defaultFooter());
    }

    /** @param  list<array<string, mixed>>  $tree */
    private function seedMenu(NavigationMenu $menu, array $tree): void
    {
        if (NavigationItem::query()->forMenu($menu)->exists()) {
            return;
        }

        foreach ($tree as $topSort => $top) {
            $parent = NavigationItem::create([
                'menu' => $menu,
                'parent_id' => null,
                'label' => $top['label'],
                'url' => $top['url'],
                'sort_order' => $topSort,
            ]);

            foreach ($top['children'] as $childSort => $child) {
                NavigationItem::create([
                    'menu' => $menu,
                    'parent_id' => $parent->getKey(),
                    'label' => $child['label'],
                    'url' => $child['url'],
                    'sort_order' => $childSort,
                ]);
            }
        }
    }
}
