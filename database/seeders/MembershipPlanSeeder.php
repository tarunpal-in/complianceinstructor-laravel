<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AllowanceWindow;
use App\Enums\BillingPeriod;
use App\Enums\Format;
use App\Models\MembershipPlan;
use Illuminate\Database\Seeder;

/**
 * The four tiers from the comp — Regular, Plus, Standard, Business — §5.9.
 *
 * The client's own plans rather than invented ones, because the seeded database
 * is the QA and client-review environment: a review of the pricing section is
 * worth nothing if the cards on it are not the cards they sell.
 *
 * All four are one-off purchases of a fixed span, which is what "$299 / 3
 * months" means. That reading also settles the open question in HomeController:
 * the 5- and 7-month terms never had to fit `billing_period`, because
 * `duration_months` is where a one-off's span belongs.
 *
 * Between them they still exercise every shape the admin has to render: counted
 * allowances and unlimited ones, a featured tier, and both included and excluded
 * lines on the card.
 */
class MembershipPlanSeeder extends Seeder
{
    public function run(): void
    {
        $this->plan(
            name: 'Regular',
            slug: 'regular',
            description: 'Three live sessions and their recordings, over three months.',
            priceCents: 29900,
            durationMonths: 3,
            sortOrder: 10,
            allowances: [
                ['format' => Format::Live, 'quantity' => 3],
                ['format' => Format::Recorded, 'quantity' => 3],
                ['format' => Format::Download, 'quantity' => 3],
            ],
            highlights: [
                ['label' => 'CEU Credits (SHRM & HRCI)', 'included' => true],
                ['label' => 'Certificate of Completion', 'included' => true],
                ['label' => 'PDF Study Materials', 'included' => true],
                ['label' => 'E-Transcript Access', 'included' => false],
                ['label' => 'Recording Download', 'included' => false],
                ['label' => 'Dedicated Support Specialist', 'included' => false],
            ],
        );

        $this->plan(
            name: 'Plus',
            slug: 'plus',
            description: 'Five live sessions, with transcripts and downloads included.',
            priceCents: 49900,
            durationMonths: 5,
            sortOrder: 20,
            allowances: [
                ['format' => Format::Live, 'quantity' => 5],
                ['format' => Format::Recorded, 'quantity' => 5],
                ['format' => Format::Transcript, 'quantity' => 5],
                ['format' => Format::Download, 'quantity' => 5],
            ],
            highlights: [
                ['label' => 'CEU Credits (SHRM & HRCI)', 'included' => true],
                ['label' => 'Certificate of Completion', 'included' => true],
                ['label' => 'PDF Study Materials', 'included' => true],
                ['label' => 'E-Transcript Access', 'included' => true],
                ['label' => 'Recording Download', 'included' => true],
                ['label' => 'Dedicated Support Specialist', 'included' => false],
            ],
        );

        $this->plan(
            name: 'Standard',
            slug: 'standard',
            description: 'Seven live sessions across seven months, everything included.',
            priceCents: 79900,
            durationMonths: 7,
            sortOrder: 30,
            allowances: [
                ['format' => Format::Live, 'quantity' => 7],
                ['format' => Format::Recorded, 'quantity' => 7],
                ['format' => Format::Transcript, 'quantity' => 7],
                ['format' => Format::Download, 'quantity' => 7],
            ],
            highlights: [
                ['label' => 'CEU Credits (All Providers)', 'included' => true],
                ['label' => 'Certificate of Completion', 'included' => true],
                ['label' => 'PDF Study Materials', 'included' => true],
                ['label' => 'E-Transcript Access', 'included' => true],
                ['label' => 'Recording Download Access', 'included' => true],
                ['label' => 'Priority Support Line', 'included' => true],
            ],
        );

        // The highlighted tier, and the one with unlimited allowances — so both
        // states are reachable in review rather than appearing for the first
        // time in production.
        $this->plan(
            name: 'Business',
            slug: 'business',
            description: 'Ten live sessions a year, with unlimited recordings, transcripts and downloads.',
            priceCents: 99900,
            durationMonths: 12,
            sortOrder: 40,
            featured: true,
            allowances: [
                ['format' => Format::Live, 'quantity' => 10],
                ['format' => Format::Recorded, 'quantity' => null],
                ['format' => Format::Transcript, 'quantity' => null],
                ['format' => Format::Download, 'quantity' => null],
            ],
            highlights: [
                ['label' => 'CEU Credits (All Providers)', 'included' => true],
                ['label' => 'Certificate of Completion', 'included' => true],
                ['label' => 'PDF Study Materials', 'included' => true],
                ['label' => 'E-Transcript Access', 'included' => true],
                ['label' => 'Recording Download Access', 'included' => true],
                ['label' => 'Dedicated Account Manager', 'included' => true],
            ],
        );
    }

    /**
     * @param  array<int, array{format: Format, quantity: int|null}>  $allowances
     * @param  array<int, array{label: string, included: bool}>  $highlights
     */
    private function plan(
        string $name,
        string $slug,
        string $description,
        int $priceCents,
        int $durationMonths,
        int $sortOrder,
        array $allowances,
        array $highlights,
        bool $featured = false,
    ): void {
        $plan = MembershipPlan::firstOrCreate(
            ['slug' => $slug],
            [
                'name' => $name,
                'description' => $description,
                'highlights' => $highlights,
                'price_cents' => $priceCents,
                'currency' => 'USD',
                // A fixed span paid once — no renewal, so no Stripe price. §3
                // only routes *recurring* billing through Cashier.
                'billing_period' => BillingPeriod::OneTime,
                'duration_months' => $durationMonths,
                'stripe_price_id' => null,
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
                [
                    'quantity' => $allowance['quantity'],
                    // The allowance covers the whole span the customer bought.
                    'window' => AllowanceWindow::Term,
                ],
            );
        }
    }
}
