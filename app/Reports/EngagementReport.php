<?php

declare(strict_types=1);

namespace App\Reports;

use App\Enums\CommentStatus;
use App\Models\Comment;
use App\Models\Inquiry;
use App\Models\Post;
use App\Models\Subscriber;
use App\Support\Period;
use Illuminate\Support\Collection;

/**
 * Everything that arrives without an invoice attached.
 *
 * The mailing list, the inbox and the blog, on one screen. None of these
 * figures is worth a report of its own, and together they answer the question
 * the marketing side of this business actually asks: *is anything we publish
 * bringing anybody in.*
 *
 * The one number here that changes behaviour is **how long people wait for an
 * answer**. Everything else is a count.
 */
class EngagementReport
{
    public function __construct(private readonly Period $period) {}

    // The mailing list -----------------------------------------------------------------

    /** How many addresses may be mailed right now, regardless of period. */
    public function listSize(): int
    {
        return Subscriber::query()->mailable()->count();
    }

    public function joined(): int
    {
        return Subscriber::query()
            ->whereBetween('subscribed_at', [$this->period->from, $this->period->to])
            ->count();
    }

    public function left(): int
    {
        return Subscriber::query()
            ->whereBetween('unsubscribed_at', [$this->period->from, $this->period->to])
            ->count();
    }

    /** Joined less left. Negative is the interesting case. */
    public function netListChange(): int
    {
        return $this->joined() - $this->left();
    }

    /**
     * Where the signups came from.
     *
     * @return Collection<int, ReportRow>
     */
    public function signupsBySource(): Collection
    {
        return $this->countBySource(
            Subscriber::query()
                ->whereBetween('subscribed_at', [$this->period->from, $this->period->to])
                ->get()
                ->countBy(fn (Subscriber $subscriber): string => $subscriber->source->label()),
        );
    }

    /**
     * Addresses a mail provider has rejected or that reported us as spam.
     *
     * Not a count of the period — a deliverability problem is a fact about now,
     * and one that appeared last month has not gone away because the month has.
     */
    public function deliverabilityProblems(): int
    {
        return Subscriber::query()->problems()->count();
    }

    // The inbox --------------------------------------------------------------------------

    public function inquiriesReceived(): int
    {
        return $this->inquiriesInPeriod()->count();
    }

    public function inquiriesAnswered(): int
    {
        return $this->inquiriesInPeriod()->whereNotNull('contacted_at')->count();
    }

    /** Received in the period and still waiting, whenever they arrived. */
    public function inquiriesStillWaiting(): int
    {
        return Inquiry::query()->untouched()->count();
    }

    /**
     * How long a typical person waits for a reply, in hours.
     *
     * The **median**, not the mean: one inquiry answered after three weeks
     * would drag an average into meaninglessness, and what somebody wants to
     * know is what most people experience. Null where nothing was answered —
     * an average of nothing is not zero hours.
     */
    public function medianResponseHours(): ?float
    {
        $waits = $this->inquiriesInPeriod()
            ->whereNotNull('contacted_at')
            ->get()
            ->map(fn (Inquiry $inquiry): float => (float) $inquiry->created_at?->diffInHours($inquiry->contacted_at))
            ->filter(fn (float $hours): bool => $hours >= 0)
            ->sort()
            ->values();

        if ($waits->isEmpty()) {
            return null;
        }

        $middle = intdiv($waits->count(), 2);

        $median = $waits->count() % 2 === 1
            ? $waits[$middle]
            : ($waits[$middle - 1] + $waits[$middle]) / 2;

        return round($median, 1);
    }

    /**
     * @return Collection<int, ReportRow>
     */
    public function inquiriesBySource(): Collection
    {
        return $this->countBySource(
            $this->inquiriesInPeriod()
                ->get()
                ->countBy(fn (Inquiry $inquiry): string => $inquiry->source->label()),
        );
    }

    // The blog ------------------------------------------------------------------------------

    public function postsPublished(): int
    {
        return Post::query()
            ->whereBetween('published_at', [$this->period->from, $this->period->to])
            ->count();
    }

    public function commentsReceived(): int
    {
        return Comment::query()
            ->whereBetween('created_at', [$this->period->from, $this->period->to])
            ->count();
    }

    public function commentsPublished(): int
    {
        return Comment::query()
            ->where('status', CommentStatus::Approved)
            ->whereBetween('created_at', [$this->period->from, $this->period->to])
            ->count();
    }

    /** Comments still waiting to be read, whenever they arrived. */
    public function commentsWaiting(): int
    {
        return Comment::query()->awaitingReview()->count();
    }

    // Shared -----------------------------------------------------------------------------------

    /** @return \Illuminate\Database\Eloquent\Builder<Inquiry> */
    private function inquiriesInPeriod(): \Illuminate\Database\Eloquent\Builder
    {
        return Inquiry::query()->whereBetween('created_at', [$this->period->from, $this->period->to]);
    }

    /**
     * Counts rendered as report rows, so the view loops over them the same way
     * it loops over every other breakdown in this section.
     *
     * `cents` carries a count here rather than money. That is a small lie in a
     * field name, and the alternative was a second row class differing only in
     * what one integer means — which is how two rendering paths appear.
     *
     * @param  Collection<string, int>  $counts
     * @return Collection<int, ReportRow>
     */
    private function countBySource(Collection $counts): Collection
    {
        return $counts
            ->sortDesc()
            ->map(fn (int $count, string $label): ReportRow => new ReportRow($label, $count))
            ->values();
    }

    /** Whether anything at all happened, for an empty state that means it. */
    public function isQuiet(): bool
    {
        return $this->joined() === 0
            && $this->inquiriesReceived() === 0
            && $this->commentsReceived() === 0
            && $this->postsPublished() === 0;
    }
}
