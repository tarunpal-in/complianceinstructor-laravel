<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\InquirySource;
use App\Enums\InquiryStatus;
use App\Models\Industry;
use App\Models\Inquiry;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * A worked register — §5.9.
 *
 * Every tab has to have something in it or the screen cannot be reviewed:
 * untouched inquiries, one somebody is working, a repeat inquiry from the same
 * address, and — the one that matters — an inquiry whose email belongs to a
 * customer who has actually bought, so the derived Outcome column has something
 * to find. That last one is the whole argument for deriving it rather than
 * storing a `converted` flag, and it is invisible until a seeded row proves it.
 */
class InquirySeeder extends Seeder
{
    public function run(): void
    {
        if (Inquiry::query()->exists()) {
            return;
        }

        $industries = Industry::query()->orderBy('id')->get();
        $staff = User::query()->whereHas('roles')->orderBy('id')->first();

        // Untouched: the default tab, and the badge in the sidebar.
        Inquiry::factory()
            ->count(4)
            ->untouched()
            ->from(InquirySource::Quiz, 'Which HR compliance gaps are costing you?')
            ->fromCampaign('google', 'cpc', 'hr-compliance-2026')
            ->create([
                'industry_id' => $industries->first()?->getKey(),
            ]);

        Inquiry::factory()
            ->count(2)
            ->untouched(12)
            ->from(InquirySource::Download, 'Wage and hour audit checklist')
            ->create();

        // Being worked, with an owner.
        if ($staff !== null) {
            Inquiry::factory()
                ->count(2)
                ->assignedTo($staff)
                ->from(InquirySource::Webinar, 'FMLA in 2026')
                ->create([
                    'note' => 'Placeholder note — seeded so the follow-up panel has something in it.',
                ]);
        }

        Inquiry::factory()
            ->status(InquiryStatus::Answered)
            ->from(InquirySource::Contact)
            ->create([
                'company' => 'Northwind Logistics',
                'message' => 'Placeholder inquiry. Asked about seats for a team of twelve.',
            ]);

        Inquiry::factory()
            ->status(InquiryStatus::Closed)
            ->from(InquirySource::Import, 'SHRM conference badge scans')
            ->create(['marketing_opt_in' => false]);

        $this->seedRepeatEnquiry();
        $this->seedConvertedInquiry();
    }

    /**
     * The same address twice, months apart.
     *
     * Why the email column carries no UNIQUE index: the second inquiry is
     * evidence that the first campaign did not close them, and a table that
     * refused it would lose that.
     */
    private function seedRepeatEnquiry(): void
    {
        $email = 'returning.enquirer@example.com';

        Inquiry::factory()->from(InquirySource::Download, 'Year-end payroll close checklist')->create([
            'name' => 'Priya Raman',
            'email' => $email,
            'company' => 'Cedar & Vale',
            'status' => InquiryStatus::Closed,
            'created_at' => now()->subMonths(4),
            'updated_at' => now()->subMonths(4),
        ]);

        Inquiry::factory()->untouched(2)->from(InquirySource::Quiz, 'Is your payroll year-end ready?')->create([
            'name' => 'Priya Raman',
            'email' => $email,
            'company' => 'Cedar & Vale',
        ]);
    }

    /**
     * An inquiry whose email belongs to somebody who went on to buy.
     *
     * Nothing on the inquiry row says so. The register works it out by joining the
     * email to an account and asking whether that account has a settled order,
     * which is the behaviour worth being able to see in review.
     */
    private function seedConvertedInquiry(): void
    {
        $customer = Order::query()->settled()->with('user')->first()?->user;

        if ($customer === null) {
            return;
        }

        Inquiry::factory()
            ->matching($customer)
            ->from(InquirySource::Quiz, 'Which HR compliance gaps are costing you?')
            ->status(InquiryStatus::Answered)
            ->create([
                'note' => 'Placeholder — seeded to show an inquiry that went on to buy.',
                'contacted_at' => now()->subMonth(),
            ]);
    }
}
