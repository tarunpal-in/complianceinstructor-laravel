<?php

declare(strict_types=1);

namespace App\Actions\Webinars;

use App\Actions\Certificates\IssueEarnedCertificates;
use App\Enums\Format;
use App\Models\Entitlement;
use App\Models\User;
use App\Models\Webinar;
use App\Models\WebinarView;
use App\Support\CompletionRules;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Takes a progress report from the player and certifies when it is enough.
 *
 * The recorded half of the client's rule, agreed 2026-09-23: watched to within
 * five or ten minutes of the end counts as complete, and it should be generous
 * rather than "rigid and reluctant to issue".
 *
 * Called from the customer dashboard's player as somebody watches — every
 * thirty seconds or so — which is why it is cheap, idempotent and never throws
 * at a viewer for anything except having no business watching at all.
 *
 * **The certificate is issued the moment the rule is satisfied**, not on a
 * sweep. Recorded has no equivalent of the live session's 24-hour wait: they
 * have finished, so they have earned it, and a learner who reaches the end and
 * is told to come back tomorrow is a support ticket.
 */
class RecordWatchProgress
{
    public function __construct(private readonly IssueEarnedCertificates $issue) {}

    /**
     * @param  int  $positionSeconds  how far into the video they have reached
     * @param  int  $watchedSeconds  how long they have watched since the last report
     */
    public function __invoke(
        User $learner,
        Webinar $webinar,
        int $positionSeconds,
        int $watchedSeconds = 0,
    ): WebinarView {
        $this->guardAgainstWatchingWithoutAccess($learner, $webinar);

        return DB::transaction(function () use ($learner, $webinar, $positionSeconds, $watchedSeconds): WebinarView {
            $view = WebinarView::query()->lockForUpdate()->firstOrNew([
                'user_id' => $learner->getKey(),
                'webinar_id' => $webinar->getKey(),
            ]);

            /*
             * The furthest point only ever moves forward. Scrubbing back to
             * rewatch a section must not undo progress, and a player that
             * reports position every few seconds would otherwise walk the
             * number backwards the moment somebody dragged the scrubber.
             */
            $view->furthest_seconds = max($view->furthest_seconds ?? 0, max(0, $positionSeconds));

            // Accumulated across sittings. Not what the rule reads — somebody
            // who watches the last ten minutes six times has not watched the
            // session, and keeping the two apart is what tells them apart.
            $view->seconds_watched = ($view->seconds_watched ?? 0) + max(0, $watchedSeconds);

            $view->last_watched_at = now();

            $earnedNow = $view->completed_at === null
                && CompletionRules::hasWatchedEnough($webinar, $view->furthest_seconds);

            if ($earnedNow) {
                // Stamped once. "When did they finish it" has one answer, and a
                // later report must not move it.
                $view->completed_at = now();
            }

            $view->save();

            if ($earnedNow) {
                ($this->issue)($learner, $webinar);
            }

            return $view;
        });
    }

    /**
     * Only somebody who may watch it can make progress through it.
     *
     * Without this, a report posted straight at the endpoint would mint a
     * certificate for a webinar nobody bought — §5.2's whole point is that one
     * ledger answers "may this person see this", and progress is a claim to
     * have been watching.
     */
    private function guardAgainstWatchingWithoutAccess(User $learner, Webinar $webinar): void
    {
        $hasAccess = Entitlement::query()
            ->where('user_id', $learner->getKey())
            ->where('webinar_id', $webinar->getKey())
            ->whereIn('format', [Format::Recorded, Format::Live])
            ->active()
            ->exists();

        if (! $hasAccess) {
            throw new RuntimeException('That webinar has not been made available to this learner.');
        }
    }
}
