<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AllowanceWindow;
use App\Enums\BillingPeriod;
use App\Enums\Format;
use App\Models\MembershipPlan;
use Illuminate\Database\Seeder;

/**
 * The four plans §5.9 asks for, chosen to exercise every shape the screen has
 * to render rather than to look tidy.
 *
 * Between them: a quarterly and a yearly and a one-off; a counted allowance and
 * an unlimited one; a per-month window and a per-term one; a featured tier; and
 * one plan deliberately left unsellable, so the "cannot be sold yet" state is
 * reachable in client review instead of appearing for the first time in
 * production.
 */
class MembershipPlanSeeder extends Seeder
{
    public function run(): void
    {
        $this->plan(
            name: 'Essentials',
            slug: 'essentials',
            description: 'Two live sessions a quarter, with the transcripts to keep.',
            priceCents: 29900,
            period: BillingPeriod::Quarter,
            sortOrder: 10,
            allowances: [
                ['format' => Format::Live, 'quantity' => 2, 'window' => AllowanceWindow::Term],
                ['format' => Format::Transcript, 'quantity' => 2, 'window' => AllowanceWindow::Term],
            ],
        );

        // The featured tier: a per-month allowance on a yearly plan, which is
        // the case the window column exists for — 4 a month is 48 a year, and
        // the predecessor's all-time counter could not express it at all.
        $this->plan(
            name: 'Professional',
            slug: 'professional',
            description: 'Four live sessions every month, plus unlimited recordings and transcripts.',
            priceCents: 99900,
            period: BillingPeriod::Year,
            sortOrder: 20,
            featured: true,
            allowances: [
                ['format' => Format::Live, 'quantity' => 4, 'window' => AllowanceWindow::Month],
                ['format' => Format::Recorded, 'quantity' => null, 'window' => AllowanceWindow::Term],
                ['format' => Format::Transcript, 'quantity' => null, 'window' => AllowanceWindow::Term],
            ],
        );

        $this->plan(
            name: 'Enterprise',
            slug: 'enterprise',
            description: 'Everything we publish, in every format, for the whole team.',
            priceCents: 249900,
            period: BillingPeriod::Year,
            sortOrder: 30,
            allowances: [
                ['format' => Format::Live, 'quantity' => null, 'window' => AllowanceWindow::Term],
                ['format' => Format::Recorded, 'quantity' => null, 'window' => AllowanceWindow::Term],
                ['format' => Format::Transcript, 'quantity' => null, 'window' => AllowanceWindow::Term],
                ['format' => Format::Download, 'quantity' => null, 'window' => AllowanceWindow::Term],
            ],
        );

        // A one-off: buys twelve months outright, so it needs a duration and no
        // Stripe price. Left inactive because it has none of the latter, which
        // is what an unfinished plan actually looks like.
        $this->plan(
            name: 'Annual Pass',
            slug: 'annual-pass',
            description: 'A year of recordings and transcripts, paid once.',
            priceCents: 79900,
            period: BillingPeriod::OneTime,
            sortOrder: 40,
            durationMonths: 12,
            allowances: [
                ['format' => Format::Recorded, 'quantity' => 12, 'window' => AllowanceWindow::Term],
                ['format' => Format::Transcript, 'quantity' => 12, 'window' => AllowanceWindow::Term],
            ],
        );
    }

    /**
     * @param  array<int, array{format: Format, quantity: int|null, window: AllowanceWindow}>  $allowances
     */
    private function plan(
        string $name,
        string $slug,
        string $description,
        int $priceCents,
        BillingPeriod $period,
        int $sortOrder,
        array $allowances,
        bool $featured = false,
        ?int $durationMonths = null,
    ): void {
        $plan = MembershipPlan::firstOrCreate(
            ['slug' => $slug],
            [
                'name' => $name,
                'description' => $description,
                'price_cents' => $priceCents,
                'currency' => 'USD',
                'billing_period' => $period,
                'duration_months' => $durationMonths,
                // A recurring plan needs a real Stripe price before anyone can
                // subscribe (§3). These are placeholders so the seeded app is
                // demoable; they are not valid in any Stripe account.
                'stripe_price_id' => $period->isRecurring() ? 'price_seed_'.$slug : null,
                'is_active' => true,
                'is_featured' => $featured,
                'sort_order' => $sortOrder,
            ],
        );

        foreach ($allowances as $allowance) {
            // Keyed on the UNIQUE, so re-running the seeder never doubles an
            // allowance — the exact failure that constraint exists to prevent.
            $plan->entitlements()->firstOrCreate(
                ['format' => $allowance['format']],
                ['quantity' => $allowance['quantity'], 'window' => $allowance['window']],
            );
        }
    }
}
