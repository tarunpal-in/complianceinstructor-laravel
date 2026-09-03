<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

/**
 * Defaults for the site settings.
 *
 * Values match what was hardcoded in the promo bar's markup and CSS, so seeding
 * changes nothing visually — it only moves the source of truth into the database
 * where the client can reach it.
 *
 * Uses firstOrCreate, so re-seeding never overwrites what the client has since
 * edited (CLAUDE.md §4 rule 1 — migrate:fresh --seed must always work, and it
 * must not be destructive on an existing install).
 */
class SettingSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            ['promo_bar', 'enabled', true, 'boolean'],
            ['promo_bar', 'text', 'Purchase any webinar and use code :code at checkout to save $10.', 'string'],
            ['promo_bar', 'code', 'TRAINING', 'string'],
            ['promo_bar', 'background_color', '#0c4d80', 'color'],
            ['promo_bar', 'text_color', '#ffffff', 'color'],
            ['promo_bar', 'font_size', 14, 'integer'],
        ];

        foreach ($defaults as [$group, $key, $value, $type]) {
            Setting::firstOrCreate(
                ['group' => $group, 'key' => $key],
                ['value' => $value, 'type' => $type],
            );
        }

        Setting::flush();
    }
}
