<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Memberships\SnapshotPlanEntitlements;
use App\Models\MembershipPlan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * A test customer with an active membership, for manually exercising the member
 * redemption flow (§5.5 / P4). Not part of the seed — a developer convenience,
 * run by hand against a seeded database.
 *
 * The chosen plan is a one-off tier (no Stripe), so the subscription is written
 * directly with an `active` status and its allowances snapshotted, exactly as
 * StartSubscription would after Cashier — just without a real gateway call.
 */
class MakeTestMember extends Command
{
    protected $signature = 'app:make-test-member
        {--email=test@example.com : The account email}
        {--password=password : The account password}';

    protected $description = 'Create or refresh a test customer with an active membership on the most expensive plan.';

    public function handle(SnapshotPlanEntitlements $snapshot): int
    {
        $plan = MembershipPlan::query()
            ->where('is_active', true)
            ->with('entitlements')
            ->orderByDesc('price_cents')
            ->first();

        if ($plan === null || $plan->entitlements->isEmpty()) {
            $this->error('No sellable membership plan found. Seed plans first: php artisan db:seed --class=MembershipPlanSeeder');

            return self::FAILURE;
        }

        $email = (string) $this->option('email');
        $password = (string) $this->option('password');

        // updateOrCreate so re-running just refreshes the same account. The
        // model's `hashed` cast hashes the password on save.
        $user = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => 'Test Member',
                'password' => $password,
                'email_verified_at' => now(),
            ],
        );

        // One active membership at a time — clear any prior default subscription
        // so this command is idempotent.
        Subscription::query()
            ->where('user_id', $user->getKey())
            ->where('type', 'default')
            ->delete();

        $subscription = Subscription::create([
            'user_id' => $user->getKey(),
            'membership_plan_id' => $plan->getKey(),
            'type' => 'default',
            'stripe_id' => 'sub_test_'.Str::random(20),
            'stripe_status' => 'active',
            'stripe_price' => null,
            'quantity' => 1,
            'price_cents' => $plan->price_cents,
            'trial_ends_at' => null,
            'ends_at' => null,
        ]);

        // Freezes the plan's allowances onto the subscription and sets its term
        // dates — the step that makes the membership actually spendable.
        $snapshot($subscription, $plan);

        $this->info('Test member ready.');
        $this->newLine();
        $this->line("  Email:    {$email}");
        $this->line("  Password: {$password}");
        $this->line("  Plan:     {$plan->name} (\$".number_format($plan->price_cents / 100, 2).')');
        $this->newLine();
        $this->line('  Allowance this term:');

        foreach ($subscription->entitlements as $entitlement) {
            $amount = $entitlement->quantity === null ? 'unlimited' : (string) $entitlement->quantity;
            $this->line("    - {$entitlement->format->label()}: {$amount}");
        }

        $this->newLine();
        $this->line('  Sign in at /login, open any webinar sold in a covered format,');
        $this->line('  and use "Watch with your membership".');

        return self::SUCCESS;
    }
}
