<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Certificates\IssueEarnedCertificates;
use App\Enums\Format;
use App\Models\Entitlement;
use App\Models\Webinar;
use App\Support\CompletionRules;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Certifies everyone who attended a live session, 24 hours afterwards.
 *
 * The client's rule, agreed 2026-09-23: holding live access is the
 * qualification, and the certificate arrives without anybody asking for it.
 *
 * A swept query rather than a job scheduled per webinar, for the reasons
 * ReleaseDueRefunds gives: a delayed job is lost if the queue is flushed,
 * silently ignored on the `sync` connection, and invisible until somebody asks
 * why a certificate never came. This is idempotent, restartable, and the
 * pending work is a query anyone can run.
 *
 * **No progress marker on the webinar**, deliberately. The sweep asks "who
 * holds live access and has no certificate yet" rather than "which webinars
 * have been processed", so somebody who buys live access late — or whose access
 * was restored after a refund was reversed — is picked up by the next run
 * instead of falling through a gap nobody would ever notice.
 */
class IssueDueCertificates extends Command
{
    protected $signature = 'certificates:issue-due';

    protected $description = 'Issue certificates to live attendees whose session finished long enough ago';

    public function handle(IssueEarnedCertificates $issue): int
    {
        $webinars = $this->dueWebinars();

        if ($webinars->isEmpty()) {
            return self::SUCCESS;
        }

        $issued = 0;
        $learners = 0;

        foreach ($webinars as $webinar) {
            foreach ($this->uncertifiedLearners($webinar) as $entitlement) {
                $learner = $entitlement->user;

                if ($learner === null) {
                    continue;
                }

                try {
                    $certificates = $issue($learner, $webinar);
                } catch (Throwable $exception) {
                    // One learner failing must not stop the rest. Theirs stays
                    // outstanding and the next sweep tries again.
                    Log::error('Automatic certificate issue failed.', [
                        'user_id' => $learner->getKey(),
                        'webinar_id' => $webinar->getKey(),
                        'message' => $exception->getMessage(),
                    ]);

                    continue;
                }

                if ($certificates->isNotEmpty()) {
                    $learners++;
                    $issued += $certificates->count();
                }
            }
        }

        $this->components->info("Issued {$issued} certificate(s) to {$learners} learner(s).");

        return self::SUCCESS;
    }

    /**
     * Live sessions whose delay has elapsed and that are worth certifying.
     *
     * @return Collection<int, Webinar>
     */
    private function dueWebinars(): Collection
    {
        return Webinar::query()
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now()->subHours(CompletionRules::liveDelayHours()))
            // Only accredited ones: a webinar awarding nothing has no
            // certificate to issue, and asking for its learners would be work
            // done to discover that.
            ->whereHas('accreditations')
            ->with('accreditations')
            ->get();
    }

    /**
     * Everyone holding live access to this webinar.
     *
     * Active only — revoked access means a refund or a support correction, and
     * certifying somebody whose purchase was undone is exactly the mistake the
     * ledger exists to prevent.
     *
     * @return Collection<int, Entitlement>
     */
    private function uncertifiedLearners(Webinar $webinar): Collection
    {
        return Entitlement::query()
            ->where('webinar_id', $webinar->getKey())
            ->where('format', Format::Live)
            ->active()
            ->with('user')
            ->get();
    }
}
