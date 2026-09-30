<?php

declare(strict_types=1);

namespace App\Actions\Certificates;

use App\Enums\Format;
use App\Models\Accreditation;
use App\Models\Certificate;
use App\Models\Entitlement;
use App\Models\User;
use App\Models\Webinar;
use App\Models\WebinarView;
use App\Support\CompletionRules;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Certify one learner for one webinar, across every body that accredits it.
 *
 * The single place automatic issuing goes through — the live sweep calls it,
 * and so does finishing a recording. A webinar carrying HRCI and SHRM produces
 * two certificates, because those are two separate certifications with two
 * separate serials; one document naming both would be no use to either body.
 *
 * **Each body's own rules decide.** §12 has HRCI's and SHRM's requirements as
 * prescribed and not negotiable, and they differ — a learner who skipped eight
 * minutes of a recording may have earned one body's credit and not another's.
 * The loop below asks each body separately rather than deciding once for the
 * webinar, which is the whole point of the override columns on `accreditations`.
 *
 * Safe to call repeatedly. A learner who already holds a valid certificate from
 * a body is skipped rather than refused, because this runs on a schedule and an
 * exception per already-certified attendee would turn a routine sweep into a
 * page of noise. It is also called again as somebody watches further, so a
 * stricter body can be satisfied on a later report than a lenient one.
 *
 * **A revoked certificate is not handed back.** If an administrator withdrew
 * one, the next sweep must not quietly reissue it — that would make revoking
 * useless and is the kind of thing nobody notices until an audit.
 */
class IssueEarnedCertificates
{
    public function __construct(private readonly IssueCertificate $issue) {}

    /**
     * @return Collection<int, Certificate>
     */
    public function __invoke(User $learner, Webinar $webinar, ?int $issuedBy = null): Collection
    {
        $issued = new Collection;

        if (! $webinar->earnsCredits()) {
            // Too short to be worth anything. Skipped rather than issuing a
            // zero-credit certificate, which asserts nothing and would have to
            // be explained to whoever received it.
            return $issued;
        }

        /*
         * Resolved once per learner rather than once per body: a sweep over a
         * popular webinar would otherwise repeat both lookups for every
         * accrediting body on it.
         */
        $holdsLiveAccess = Entitlement::grants($learner, $webinar, Format::Live);
        $furthestSeconds = WebinarView::query()
            ->where('user_id', $learner->getKey())
            ->where('webinar_id', $webinar->getKey())
            ->value('furthest_seconds');

        foreach ($webinar->accreditations as $accreditation) {
            /** @var Accreditation $accreditation */
            if ($this->alreadyDecided($learner, $webinar, $accreditation)) {
                continue;
            }

            $earned = CompletionRules::qualifiesFor(
                $webinar,
                $accreditation,
                $holdsLiveAccess,
                $furthestSeconds === null ? null : (int) $furthestSeconds,
            );

            if (! $earned) {
                // Not yet, by this body's rules. Another body on the same
                // webinar may well have been satisfied already.
                continue;
            }

            try {
                $issued->push(($this->issue)($learner, $webinar, $accreditation, $issuedBy));
            } catch (Throwable $exception) {
                /*
                 * One body's rules being unsatisfiable must not stop the others
                 * being awarded. The learner keeps whatever they have earned,
                 * and the reason is in the log rather than swallowed.
                 */
                Log::warning('Could not issue a certificate automatically.', [
                    'user_id' => $learner->getKey(),
                    'webinar_id' => $webinar->getKey(),
                    'accreditation' => $accreditation->code,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        return $issued;
    }

    /**
     * Whether this learner has already been given, or deliberately denied, this
     * body's certificate for this webinar.
     *
     * Both count. A valid one means there is nothing to do; a revoked one means
     * somebody decided, and the sweep does not get to overrule them.
     */
    private function alreadyDecided(User $learner, Webinar $webinar, Accreditation $accreditation): bool
    {
        return Certificate::query()
            ->where('user_id', $learner->getKey())
            ->where('webinar_id', $webinar->getKey())
            ->where('accreditation_id', $accreditation->getKey())
            ->exists();
    }
}
