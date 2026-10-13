<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Page;
use App\Support\HomepageDefaults;
use Illuminate\Database\Seeder;

/**
 * The homepage as a builder-driven `Page` — CLAUDE.md §2 / §5.6 / §5.9.
 *
 * §4 rule 1 requires `migrate:fresh --seed` to produce a working, demoable app;
 * the homepage is the first thing anyone reviewing it sees. This creates the
 * `home` singleton and fills it with the comp's own copy (via HomepageDefaults),
 * so the seeded homepage renders exactly as the hand-built one did — now from
 * the database, and editable in "Homepage Sections".
 *
 * Idempotent: the page is keyed on its slug and the sections on their position,
 * so re-running edits rather than stacks.
 */
class HomePageSeeder extends Seeder
{
    public function run(): void
    {
        HomepageDefaults::apply(Page::home());
    }
}
