<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Models\Industry;
use App\Models\Lead;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * A worked register — §5.9.
 *
 * Every tab has to have something in it or the screen cannot be reviewed:
 * untouched leads, one somebody is working, a repeat enquiry from the same
 * address, and — the one that matters — a lead whose email belongs to a
 * customer who has actually bought, so the derived Outcome column has something
 * to find. That last one is the whole argument for deriving it rather than
 * storing a `converted` flag, and it is invisible until a seeded row proves it.
 */
class LeadSeeder extends Seeder
{
    public function run(): void
    {
        if (Lead::query()->exists()) {
            return;
        }

        $industries = Industry::query()->orderBy('id')->get();
        $staff = User::query()->whereHas('roles')->orderBy('id')->first();

        // Untouched: the default tab, and the badge in the sidebar.
        Lead::factory()
            ->count(4)
            ->untouched()
            ->from(LeadSource::Quiz, 'Which HR compliance gaps are costing you?')
            ->fromCampaign('google', 'cpc', 'hr-compliance-2026')
            ->create([
                'industry_id' => $industries->first()?->getKey(),
            ]);

        Lead::factory()
            ->count(2)
            ->untouched(12)
            ->from(LeadSource::Download, 'Wage and hour audit checklist')
            ->create();

        // Being worked, with an owner.
        if ($staff !== null) {
            Lead::factory()
                ->count(2)
                ->assignedTo($staff)
                ->from(LeadSource::Webinar, 'FMLA in 2026')
                ->create([
                    'note' => 'Placeholder note — seeded so the follow-up panel has something in it.',
                ]);
        }

        Lead::factory()
            ->status(LeadStatus::Qualified)
            ->from(LeadSource::Contact)
            ->create([
                'company' => 'Northwind Logistics',
                'message' => 'Placeholder enquiry. Asked about seats for a team of twelve.',
            ]);

        Lead::factory()
            ->status(LeadStatus::Unqualified)
            ->from(LeadSource::Import, 'SHRM conference badge scans')
            ->create(['marketing_opt_in' => false]);

        $this->seedRepeatEnquiry();
        $this->seedConvertedLead();
    }

    /**
     * The same address twice, months apart.
     *
     * Why the email column carries no UNIQUE index: the second enquiry is
     * evidence that the first campaign did not close them, and a table that
     * refused it would lose that.
     */
    private function seedRepeatEnquiry(): void
    {
        $email = 'returning.enquirer@example.com';

        Lead::factory()->from(LeadSource::Download, 'Year-end payroll close checklist')->create([
            'name' => 'Priya Raman',
            'email' => $email,
            'company' => 'Cedar & Vale',
            'status' => LeadStatus::Unqualified,
            'created_at' => now()->subMonths(4),
            'updated_at' => now()->subMonths(4),
        ]);

        Lead::factory()->untouched(2)->from(LeadSource::Quiz, 'Is your payroll year-end ready?')->create([
            'name' => 'Priya Raman',
            'email' => $email,
            'company' => 'Cedar & Vale',
        ]);
    }

    /**
     * A lead whose email belongs to somebody who went on to buy.
     *
     * Nothing on the lead row says so. The register works it out by joining the
     * email to an account and asking whether that account has a settled order,
     * which is the behaviour worth being able to see in review.
     */
    private function seedConvertedLead(): void
    {
        $customer = Order::query()->settled()->with('user')->first()?->user;

        if ($customer === null) {
            return;
        }

        Lead::factory()
            ->matching($customer)
            ->from(LeadSource::Quiz, 'Which HR compliance gaps are costing you?')
            ->status(LeadStatus::Qualified)
            ->create([
                'note' => 'Placeholder — seeded to show a lead that went on to buy.',
                'contacted_at' => now()->subMonth(),
            ]);
    }
}
