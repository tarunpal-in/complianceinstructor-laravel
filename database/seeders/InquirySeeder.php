<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\InquirySource;
use App\Enums\InquiryStatus;
use App\Models\Inquiry;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * A working inbox — §5.9.
 *
 * Every tab needs something in it or the screen cannot be reviewed: messages
 * nobody has picked up, one somebody is handling, a second message from an
 * address that already wrote once, and — the one that matters — an inquiry
 * whose email belongs to a customer who has actually bought. That last one is
 * the whole argument for deriving the Customer column rather than storing a
 * flag, and it is invisible until a seeded row proves it.
 *
 * The copy says it is placeholder. §12 has content readiness as the most common
 * cause of slipped launches, and seeded text that reads like real customer
 * messages is how a screenshot ends up in a client deck.
 */
class InquirySeeder extends Seeder
{
    public function run(): void
    {
        if (Inquiry::query()->exists()) {
            return;
        }

        $staff = User::query()->whereHas('roles')->orderBy('id')->first();

        // Waiting: the default tab, and the badge in the sidebar.
        Inquiry::factory()
            ->count(3)
            ->untouched()
            ->about('Placeholder — group rates for a team of twelve')
            ->create([
                'message' => 'Placeholder message. Seeded so the inbox can be reviewed with something in it.',
            ]);

        Inquiry::factory()
            ->count(2)
            ->untouched(12)
            ->from(InquirySource::Webinar, 'Placeholder — does this carry HRCI credit?')
            ->create();

        // Being handled, with an owner.
        if ($staff !== null) {
            Inquiry::factory()
                ->count(2)
                ->assignedTo($staff)
                ->about('Placeholder — invoice rather than card')
                ->create([
                    'note' => 'Placeholder note — seeded so the Handling panel has something in it.',
                ]);
        }

        Inquiry::factory()
            ->status(InquiryStatus::Answered)
            ->about('Placeholder — certificate not received')
            ->create([
                'company' => 'Northwind Logistics',
                'contacted_at' => now()->subDays(2),
            ]);

        Inquiry::factory()
            ->status(InquiryStatus::Closed)
            ->from(InquirySource::Contact, 'Placeholder — duplicate of an earlier message')
            ->create();

        $this->seedRepeatSender();
        $this->seedInquiryFromACustomer();
    }

    /**
     * The same address twice, months apart.
     *
     * Why the email column carries no UNIQUE index: somebody writing again is
     * usually somebody whose first message was not answered well enough, and
     * whoever picks up the second should be able to see the first.
     */
    private function seedRepeatSender(): void
    {
        $email = 'returning.sender@example.com';

        Inquiry::factory()->fromAddress($email)->status(InquiryStatus::Closed)->create([
            'name' => 'Priya Raman',
            'company' => 'Cedar & Vale',
            'subject' => 'Placeholder — first message, closed without a reply',
            'created_at' => now()->subMonths(4),
            'updated_at' => now()->subMonths(4),
        ]);

        Inquiry::factory()->fromAddress($email)->untouched(2)->create([
            'name' => 'Priya Raman',
            'company' => 'Cedar & Vale',
            'subject' => 'Placeholder — writing again, still waiting',
            'message' => 'Placeholder message. This address has written before, which the record shows.',
        ]);
    }

    /**
     * A message from somebody who has already bought.
     *
     * Nothing on the row says so. The register works it out by joining the
     * email to an account and asking whether that account has a settled order —
     * which is the behaviour worth being able to see in review, and the reason
     * whoever answers writes a different reply.
     */
    private function seedInquiryFromACustomer(): void
    {
        $customer = Order::query()->settled()->with('user')->first()?->user;

        if ($customer === null) {
            return;
        }

        Inquiry::factory()
            ->matching($customer)
            ->untouched(1)
            ->about('Placeholder — can I move my seat to the recorded version?')
            ->create();
    }
}
