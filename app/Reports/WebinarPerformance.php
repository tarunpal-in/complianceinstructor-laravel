<?php

declare(strict_types=1);

namespace App\Reports;

use App\Models\Webinar;
use App\Support\Period;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Which webinars are worth running again.
 *
 * One row per webinar, mixing three things nobody currently sees together:
 * what it sold, whether people watched it, and whether it produced the credits
 * they bought it for.
 *
 * The period applies to *sales* — revenue captured in the window — while views
 * and certificates are counted against the webinar for all time. That is
 * deliberate and it is the one thing on this report worth knowing before
 * reading it: a recording sold in March is still being watched in June, and
 * charging those views to March would make every recent webinar look unwatched.
 *
 * **Completion rate is views completed over views started**, not over seats
 * sold. Somebody who bought a recording and never opened it has not failed to
 * complete it; they have not started. Dividing by seats would report a
 * catalogue of unwatched content as a quality problem.
 */
class WebinarPerformance
{
    public function __construct(private readonly Period $period) {}

    /** @return Collection<int, WebinarRow> */
    public function rows(): Collection
    {
        $sales = $this->salesByWebinar();
        $views = $this->viewsByWebinar();
        $certificates = $this->certificatesByWebinar();

        return Webinar::query()
            ->with(['speaker', 'industry'])
            ->orderByDesc('scheduled_at')
            ->get()
            ->map(function (Webinar $webinar) use ($sales, $views, $certificates): WebinarRow {
                $id = $webinar->getKey();

                return new WebinarRow(
                    webinar: $webinar,
                    seats: (int) ($sales[$id]['seats'] ?? 0),
                    revenueCents: (int) ($sales[$id]['cents'] ?? 0),
                    views: (int) ($views[$id]['started'] ?? 0),
                    completed: (int) ($views[$id]['completed'] ?? 0),
                    certificates: (int) ($certificates[$id] ?? 0),
                );
            })
            // A webinar with no sales, no views and no certificates in scope is
            // noise on a performance report — it has not performed, it has not
            // happened.
            ->filter(fn (WebinarRow $row): bool => $row->isInteresting())
            ->sortByDesc(fn (WebinarRow $row): int => $row->revenueCents)
            ->values();
    }

    /**
     * Seats and money per webinar, over orders paid inside the period.
     *
     * The same definition of "paid in the period" the sales report uses — the
     * capture date, not the order date — so the two screens cannot report
     * different revenue for one month.
     *
     * @return array<int, array{seats: int, cents: int}>
     */
    private function salesByWebinar(): array
    {
        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('payments', 'payments.order_id', '=', 'orders.id')
            ->whereNull('orders.deleted_at')
            ->where('payments.status', 'succeeded')
            ->whereBetween('payments.processed_at', [$this->period->from, $this->period->to])
            ->whereNotNull('order_items.webinar_id')
            ->groupBy('order_items.webinar_id')
            ->selectRaw('order_items.webinar_id as webinar_id')
            ->selectRaw('SUM(order_items.quantity * order_items.seats_per_unit) as seats')
            ->selectRaw('SUM(order_items.line_total_cents) as cents')
            ->get()
            ->mapWithKeys(fn (object $row): array => [
                (int) $row->webinar_id => ['seats' => (int) $row->seats, 'cents' => (int) $row->cents],
            ])
            ->all();
    }

    /**
     * Views started and finished, for all time.
     *
     * @return array<int, array{started: int, completed: int}>
     */
    private function viewsByWebinar(): array
    {
        return DB::table('webinar_views')
            ->groupBy('webinar_id')
            ->selectRaw('webinar_id')
            ->selectRaw('COUNT(*) as started')
            ->selectRaw('SUM(CASE WHEN completed_at IS NULL THEN 0 ELSE 1 END) as completed')
            ->get()
            ->mapWithKeys(fn (object $row): array => [
                (int) $row->webinar_id => ['started' => (int) $row->started, 'completed' => (int) $row->completed],
            ])
            ->all();
    }

    /**
     * Certificates still standing, per webinar.
     *
     * Revoked ones excluded, for the same reason the accreditation report
     * excludes them: a withdrawn credit was not awarded.
     *
     * @return array<int, int>
     */
    private function certificatesByWebinar(): array
    {
        return DB::table('certificates')
            ->whereNull('revoked_at')
            ->groupBy('webinar_id')
            ->selectRaw('webinar_id')
            ->selectRaw('COUNT(*) as total')
            ->pluck('total', 'webinar_id')
            ->map(fn (mixed $total): int => (int) $total)
            ->all();
    }
}
