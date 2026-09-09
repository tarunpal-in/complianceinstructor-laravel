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
            // Matches what Order::nextNumber() produced before the format was
            // configurable, so seeding an existing install changes nothing.
            ['orders', 'number_format', 'CI-{YYYY}-{SEQ}', 'string'],
            ['orders', 'number_padding', 4, 'integer'],
            ['orders', 'number_reset', 'yearly', 'string'],
            // January, so an existing install keeps calendar-year numbering.
            // The client sets 4 for a 1 April – 31 March accounting year.
            ['orders', 'year_start_month', 1, 'integer'],

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
