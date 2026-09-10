<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Format;
use App\Models\Coupon;
use App\Models\Industry;
use Illuminate\Database\Seeder;

/**
 * A promotion of each shape — §5.9 makes seed data a deliverable.
 *
 * The mix is the point. A screen that only ever shows one live percentage code
 * looks finished until the first expired one, the first capped one and the first
 * scoped one arrive together.
 */
class CouponSeeder extends Seeder
{
    public function run(): void
    {
        // The one in the promo bar, so the code on the public site works.
        Coupon::firstOrCreate(
            ['code' => 'TRAINING'],
            [
                'description' => 'Site-wide promo bar code',
                'amount_off_cents' => 1000,
                'currency' => 'USD',
                'is_active' => true,
            ],
        );

        Coupon::firstOrCreate(
            ['code' => 'SAVE20'],
            [
                'description' => 'Twenty per cent off anything',
                'percent_off' => 20,
                'max_per_user' => 1,
                'minimum_spend_cents' => 15000,
                'is_active' => true,
            ],
        );

        // Capped, so the "fully redeemed" state is reachable in review.
        Coupon::firstOrCreate(
            ['code' => 'FIRST50'],
            [
                'description' => 'First fifty customers only',
                'percent_off' => 25,
                'max_redemptions' => 50,
                'max_per_user' => 1,
                'is_active' => true,
            ],
        );

        Coupon::firstOrCreate(
            ['code' => 'TRANSCRIPTS15'],
            [
                'description' => 'Fifteen per cent off transcripts',
                'percent_off' => 15,
                'scope_format' => Format::Transcript,
                'is_active' => true,
            ],
        );

        $industry = Industry::query()->inRandomOrder()->first();

        if ($industry !== null) {
            Coupon::firstOrCreate(
                ['code' => 'INDUSTRY10'],
                [
                    'description' => 'Ten per cent off '.$industry->name,
                    'percent_off' => 10,
                    'scopeable_type' => Industry::class,
                    'scopeable_id' => $industry->getKey(),
                    'is_active' => true,
                ],
            );
        }

        // Finished and switched off, so both dead states have an example.
        Coupon::firstOrCreate(
            ['code' => 'SUMMER24'],
            [
                'description' => 'Last summer, kept for the record',
                'percent_off' => 30,
                'starts_at' => now()->subYear(),
                'ends_at' => now()->subMonths(9),
                'is_active' => true,
            ],
        );

        Coupon::firstOrCreate(
            ['code' => 'PAUSED'],
            [
                'description' => 'Switched off pending a decision',
                'amount_off_cents' => 5000,
                'is_active' => false,
            ],
        );
    }
}
