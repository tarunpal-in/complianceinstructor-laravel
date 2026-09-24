<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * CLAUDE.md §4 rule 1 — `migrate:fresh --seed` must ALWAYS produce a working,
 * demoable app. No exceptions. CI asserts this on every push.
 *
 * §5.9 makes seed data a deliverable: working alone, the seeded database IS the
 * QA environment and the client-review environment. The realistic catalogue
 * seeders (30 webinars, 8 speakers, 5 industries, 4 plans, 60 orders) land in P1
 * alongside the schema.
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            SettingSeeder::class,
            MediaLibrarySeeder::class,
            PageSeeder::class,
            BlogSeeder::class,
            MembershipPlanSeeder::class,
            // After the plans, because every subscription snapshots one.
            SubscriptionSeeder::class,
            // After the catalogue, because one of them scopes to an industry.
            CouponSeeder::class,
            // Needs a catalogue to sell, so it runs last and skips itself when
            // there is nothing published yet.
            OrderSeeder::class,
            // After the orders, because one seeded lead is deliberately the
            // same email as a customer who bought — which is how the derived
            // Outcome column gets something to find.
            LeadSeeder::class,
        ]);

        $admin = User::firstOrCreate(
            ['email' => 'onlyclientwork@gmail.com'],
            ['name' => 'Administrator', 'password' => 'password'],
        );

        $admin->forceFill(['email_verified_at' => now()])->save();
        $admin->syncRoles(UserRole::SuperAdmin->value);
    }
}
