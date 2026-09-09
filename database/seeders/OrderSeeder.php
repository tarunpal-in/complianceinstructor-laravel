<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\OrderStatus;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Models\Offering;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use App\Models\Webinar;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Sixty orders spread across a year — CLAUDE.md §5.9 makes this a deliverable,
 * because working alone the seeded database is the QA and client-review
 * environment.
 *
 * The mix matters more than the count. An admin screen that only ever sees paid
 * card orders looks finished right up until the first refund, the first
 * invoiced B2B payment or the first failed capture arrives in production.
 */
class OrderSeeder extends Seeder
{
    public function run(): void
    {
        $webinars = Webinar::query()->with('offerings.formats')->get();

        if ($webinars->isEmpty()) {
            $this->command->warn('No webinars to sell — skipping orders.');

            return;
        }

        $customers = User::factory()->count(18)->create();

        for ($i = 0; $i < 60; $i++) {
            $placedAt = Carbon::now()->subDays(random_int(1, 360))->setTime(random_int(8, 19), random_int(0, 59));

            $order = Order::factory()
                // Roughly one in seven checks out without an account, which is
                // enough for the guest filter to have something to find.
                ->when($i % 7 === 0, fn ($factory) => $factory->guest())
                ->create([
                    'number' => sprintf('CI-%s-%04d', $placedAt->format('Y'), $i + 1),
                    'user_id' => $i % 7 === 0 ? null : $customers->random()->id,
                    'placed_at' => $placedAt,
                    'status' => OrderStatus::Pending,
                ]);

            $subtotal = $this->addLines($order, $webinars);

            // No tax rows yet — `tax_rates` lands with checkout in P3 — so the
            // seeded totals are subtotal only rather than a made-up rate that
            // would then disagree with whatever the client actually configures.
            $order->update([
                'subtotal_cents' => $subtotal,
                'total_cents' => $subtotal,
            ]);

            $this->settle($order, $i);
        }
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, Webinar>  $webinars
     */
    private function addLines(Order $order, $webinars): int
    {
        $subtotal = 0;

        foreach (range(1, random_int(1, 3)) as $ignored) {
            $webinar = $webinars->random();
            $sellable = $webinar->offerings->where('is_active', true);

            // A webinar can exist with nothing on sale — the publish gate stops
            // it going live, but the seeder should not depend on that.
            if ($sellable->isEmpty()) {
                continue;
            }

            $offering = $sellable->random();

            $quantity = random_int(1, 4);
            $unit = $offering->price_cents;

            $item = OrderItem::create([
                'order_id' => $order->id,
                'offering_id' => $offering->id,
                'webinar_id' => $webinar->id,
                'description' => $offering->label(),
                'sku' => $offering->sku,
                'quantity' => $quantity,
                'unit_price_cents' => $unit,
                'line_total_cents' => $unit * $quantity,
                'seats_per_unit' => $offering->seats_per_unit,
            ]);

            // The formats are snapshotted onto the line, not left to be read
            // back off the offering later.
            foreach ($offering->formatEnums() as $format) {
                $item->formats()->create(['format' => $format]);
            }

            // One named seat per seat bought, which is what a certificate is
            // eventually issued against.
            for ($seat = 0; $seat < $item->seatCount(); $seat++) {
                $item->attendees()->create([
                    'name' => fake()->name(),
                    'email' => fake()->unique()->safeEmail(),
                ]);
            }

            $subtotal += $item->line_total_cents;
        }

        return $subtotal;
    }

    /**
     * Give the order a payment history, then let the model derive its status
     * from it rather than assigning one — the same path production takes (§9).
     */
    private function settle(Order $order, int $index): void
    {
        // A realistic spread: mostly captured, some invoiced, a few that failed
        // or were refunded.
        $outcome = match (true) {
            $index % 17 === 0 => 'failed',
            $index % 11 === 0 => 'pending',
            $index % 9 === 0 => 'refunded',
            $index % 8 === 0 => 'partial',
            $index % 5 === 0 => 'manual',
            default => 'paid',
        };

        if ($outcome === 'pending') {
            // An invoice sent and not yet settled — what the Awaiting payment
            // tab is for.
            return;
        }

        $payment = Payment::create([
            'order_id' => $order->id,
            'gateway' => $outcome === 'manual'
                ? PaymentGateway::Manual
                : fake()->randomElement([PaymentGateway::Stripe, PaymentGateway::PayPal]),
            'gateway_payment_id' => $outcome === 'manual' ? null : 'pi_'.Str::lower(Str::random(24)),
            'status' => $outcome === 'failed' ? PaymentStatus::Failed : PaymentStatus::Succeeded,
            'amount_cents' => $order->total_cents,
            'currency' => $order->currency,
            'reference' => $outcome === 'manual' ? 'BACS '.fake()->numerify('########') : null,
            'processed_at' => $outcome === 'failed' ? null : $order->placed_at,
        ]);

        if ($outcome === 'refunded') {
            Refund::create([
                'payment_id' => $payment->id,
                'gateway_refund_id' => 're_'.Str::lower(Str::random(24)),
                'status' => \App\Enums\RefundStatus::Succeeded,
                'amount_cents' => $payment->amount_cents,
                'reason' => 'Customer requested within the 7-day window',
                'processed_at' => $order->placed_at?->copy()->addDays(3),
            ]);
        }

        if ($outcome === 'partial') {
            Refund::create([
                'payment_id' => $payment->id,
                'gateway_refund_id' => 're_'.Str::lower(Str::random(24)),
                'status' => \App\Enums\RefundStatus::Succeeded,
                'amount_cents' => (int) round($payment->amount_cents / 2),
                'reason' => 'One seat released before the session',
                'processed_at' => $order->placed_at?->copy()->addDays(2),
            ]);
        }

        $order->load('payments.refunds');
        $order->update(['status' => $order->derivedStatus()]);
    }
}
