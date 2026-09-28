<?php

declare(strict_types=1);

namespace App\Reports;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Models\Inquiry;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Webinar;
use App\Support\Period;

/**
 * What needs doing today.
 *
 * The operational half of the reporting §8 describes. Everything here is a
 * number that should normally be zero, and a link to the list behind it — which
 * is the difference between a dashboard somebody opens every morning and one
 * they look at twice.
 *
 * Deliberately not on this list: anything derived from an analysis. "Revenue is
 * down 4%" is not something anybody can act on before lunch; "three invoices
 * are more than a fortnight old" is.
 */
class OperationsSnapshot
{
    /**
     * How long an unpaid order waits before it counts as overdue.
     *
     * §1 has this audience frequently invoiced rather than paying by card, so
     * an order sitting unpaid for a few days is normal business rather than a
     * problem. A fortnight is where it stops being normal.
     */
    public const OVERDUE_AFTER_DAYS = 14;

    /** How far back a failed payment is still worth chasing. */
    private const FAILURE_WINDOW_DAYS = 7;

    /** Orders placed and not yet paid for. */
    public function awaitingPayment(): int
    {
        return Order::query()->where('status', OrderStatus::Pending)->count();
    }

    /** ...of which these have been waiting long enough to chase. */
    public function overdue(): int
    {
        return Order::query()
            ->where('status', OrderStatus::Pending)
            ->where('placed_at', '<', Period::now()->subDays(self::OVERDUE_AFTER_DAYS))
            ->count();
    }

    /** What is owed on everything unpaid, in cents. */
    public function outstandingCents(): int
    {
        return (int) Order::query()
            ->where('status', OrderStatus::Pending)
            ->sum('total_cents');
    }

    /**
     * Payments that failed recently.
     *
     * Worth surfacing because a failure is somebody who tried to buy and could
     * not — the most recoverable lost sale there is, and invisible everywhere
     * else in the admin.
     */
    public function recentFailures(): int
    {
        return Payment::query()
            ->where('status', PaymentStatus::Failed)
            ->where('created_at', '>=', Period::now()->subDays(self::FAILURE_WINDOW_DAYS))
            ->count();
    }

    /** Refunds agreed and not yet resolved — money promised and not sent. */
    public function refundsInFlight(): int
    {
        return Refund::query()
            ->whereIn('status', [RefundStatus::Scheduled->value, RefundStatus::Pending->value])
            ->count();
    }

    /**
     * Inquiries nobody has picked up.
     *
     * The same query as the Inbox badge rather than a second definition of
     * "waiting" — §14 #9's rule, which is about access but reads the same way
     * about any number shown in two places.
     */
    public function unansweredInquiries(): int
    {
        return Inquiry::query()->untouched()->count();
    }

    /**
     * Webinars running in the next week.
     *
     * Informational rather than a problem, and the one thing on this screen
     * that is about the days ahead instead of the days behind.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Webinar>
     */
    public function webinarsThisWeek(): \Illuminate\Database\Eloquent\Collection
    {
        return Webinar::query()
            ->whereBetween('scheduled_at', [Period::now(), Period::now()->addWeek()])
            ->orderBy('scheduled_at')
            ->limit(10)
            ->get();
    }

    /**
     * Everything that wants attention, as rows a view can render.
     *
     * Only the non-zero ones. A list of zeroes is furniture, and the point of
     * this panel is that seeing anything in it means there is something to do.
     *
     * @return array<int, array{label: string, count: int, tone: string, url: string|null}>
     */
    public function attention(): array
    {
        $rows = [
            [
                'label' => 'invoices more than '.self::OVERDUE_AFTER_DAYS.' days old',
                'count' => $this->overdue(),
                'tone' => 'danger',
                'url' => $this->orderUrl('pending'),
            ],
            [
                'label' => 'payments failed in the last week',
                'count' => $this->recentFailures(),
                'tone' => 'danger',
                'url' => $this->paymentUrl(),
            ],
            [
                'label' => 'refunds agreed but not yet sent',
                'count' => $this->refundsInFlight(),
                'tone' => 'warning',
                'url' => $this->paymentUrl(),
            ],
            [
                'label' => 'inquiries nobody has picked up',
                'count' => $this->unansweredInquiries(),
                'tone' => 'warning',
                'url' => $this->inquiryUrl(),
            ],
        ];

        return array_values(array_filter($rows, fn (array $row): bool => $row['count'] > 0));
    }

    private function orderUrl(string $tab): ?string
    {
        return class_exists(\App\Filament\Resources\Orders\OrderResource::class)
            ? \App\Filament\Resources\Orders\OrderResource::getUrl('index', ['activeTab' => $tab])
            : null;
    }

    private function paymentUrl(): ?string
    {
        return class_exists(\App\Filament\Resources\Payments\PaymentResource::class)
            ? \App\Filament\Resources\Payments\PaymentResource::getUrl('index')
            : null;
    }

    private function inquiryUrl(): ?string
    {
        return class_exists(\App\Filament\Resources\Inquiries\InquiryResource::class)
            ? \App\Filament\Resources\Inquiries\InquiryResource::getUrl('index')
            : null;
    }
}
