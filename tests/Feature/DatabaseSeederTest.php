<?php

declare(strict_types=1);

use App\Models\Order;
use App\Models\Webinar;

/*
 * CLAUDE.md §4 rule 1 — `migrate:fresh --seed` must ALWAYS produce a working,
 * demoable app, and CI asserts it. This runs the whole seeder in-suite so the
 * invariant is guarded here too — in particular now that a demo catalogue exists
 * for OrderSeeder to actually sell, a path that had nothing to run against
 * before.
 */
it('runs the full database seeder into a working app', function (): void {
    $this->seed();

    // The catalogue populated the homepage's live sections…
    expect(Webinar::query()->exists())->toBeTrue()
        // …and OrderSeeder had something to sell, so it ran its real path.
        ->and(Order::query()->exists())->toBeTrue();

    $this->get('/')->assertSuccessful()->assertSee('Membership Plans', escape: false);
});

it('re-running the order seeder is a no-op, not a number collision', function (): void {
    // migrate --seed on an already-seeded database re-runs the seeders; the
    // order numbers are positional, so a second run used to collide on the
    // unique number (e.g. CI-2026-0003). It must now be idempotent.
    $this->seed();
    $count = Order::query()->count();

    expect($count)->toBeGreaterThan(0);

    $this->seed(Database\Seeders\OrderSeeder::class);

    expect(Order::query()->count())->toBe($count);
});
