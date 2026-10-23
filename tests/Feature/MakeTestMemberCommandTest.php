<?php

declare(strict_types=1);

use App\Enums\Format;
use App\Models\MembershipPlan;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\MembershipPlanSeeder;
use Illuminate\Support\Facades\Hash;

/*
 * The developer convenience command behind "give me a test member". It must
 * produce an account that can actually redeem — verified, on the dearest plan,
 * with an active subscription whose allowances are snapshotted and spendable.
 */

it('creates a verified test member on the most expensive plan with spendable allowances', function (): void {
    $this->seed(MembershipPlanSeeder::class);

    $this->artisan('app:make-test-member')->assertSuccessful();

    $user = User::query()->where('email', 'test@example.com')->sole();
    $dearest = MembershipPlan::query()->orderByDesc('price_cents')->firstOrFail();

    expect($user->email_verified_at)->not->toBeNull()
        ->and(Hash::check('password', $user->password))->toBeTrue()
        ->and($user->isCustomer())->toBeTrue();

    $membership = $user->membership();

    expect($membership)->not->toBeNull()
        ->and($membership->membership_plan_id)->toBe($dearest->getKey())
        ->and($membership->state()->grantsAccess())->toBeTrue()
        ->and($membership->entitlements)->not->toBeEmpty()
        // Business covers unlimited recorded — a covered format is spendable.
        ->and($membership->canSpend(Format::Recorded))->toBeTrue();
});

it('is idempotent — re-running refreshes one account and one subscription', function (): void {
    $this->seed(MembershipPlanSeeder::class);

    $this->artisan('app:make-test-member')->assertSuccessful();
    $this->artisan('app:make-test-member')->assertSuccessful();

    expect(User::query()->where('email', 'test@example.com')->count())->toBe(1)
        ->and(Subscription::query()->where('type', 'default')->count())->toBe(1);
});

it('honours a custom email and password', function (): void {
    $this->seed(MembershipPlanSeeder::class);

    $this->artisan('app:make-test-member', ['--email' => 'qa@example.com', '--password' => 's3cret!'])
        ->assertSuccessful();

    $user = User::query()->where('email', 'qa@example.com')->sole();

    expect(Hash::check('s3cret!', $user->password))->toBeTrue()
        ->and($user->membership())->not->toBeNull();
});
