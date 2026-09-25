<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\SubscriberSource;
use App\Models\Subscriber;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * A mailing list with every state in it — §5.9.
 *
 * The states are the whole point of this screen, and four of the five are
 * invisible until something is in them: a pending signup, an ordinary
 * unsubscribe, a bounce, and the spam complaint that staff cannot undo from a
 * form. Reviewing the screen without them means reviewing a list of addresses.
 */
class SubscriberSeeder extends Seeder
{
    public function run(): void
    {
        if (Subscriber::query()->exists()) {
            return;
        }

        // The list itself.
        Subscriber::factory()->count(18)->confirmed()->create();
        Subscriber::factory()->count(4)->from(SubscriberSource::Checkout)->confirmed()->create();
        Subscriber::factory()->count(3)->from(SubscriberSource::Blog)->create();

        // Signed up, never confirmed — the tab and its badge.
        Subscriber::factory()->count(2)->pending()->create();

        Subscriber::factory()->unsubscribed('Too many emails')->create();
        Subscriber::factory()->unsubscribed()->create();

        // The Problems tab: a dead mailbox and a spam complaint.
        Subscriber::factory()->bounced()->create();
        Subscriber::factory()->complained()->create([
            'email' => 'complained@example.com',
        ]);

        // An imported row, so the "not a consent this site collected" warning
        // has something to attach to.
        Subscriber::factory()->from(SubscriberSource::Import)->create([
            'consent_ip' => null,
            'consent_user_agent' => null,
        ]);

        $this->seedSubscriberWhoBought();
    }

    /**
     * Somebody on the list who is also a customer.
     *
     * Nothing on the row says so — it is worked out by matching the address to
     * an account, the same way the inquiries register does it.
     */
    private function seedSubscriberWhoBought(): void
    {
        $customer = User::query()->whereHas('orders')->orderBy('id')->first();

        if ($customer === null || Subscriber::query()->where('email', $customer->email)->exists()) {
            return;
        }

        Subscriber::factory()->matching($customer)->confirmed()->from(SubscriberSource::Checkout)->create();
    }
}
