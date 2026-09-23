<?php

declare(strict_types=1);

namespace App\Actions\Certificates;

use App\Models\Accreditation;
use App\Models\Certificate;
use App\Models\User;
use App\Models\Webinar;
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
 * Safe to call repeatedly. A learner who already holds a valid certificate from
 * a body is skipped rather than refused, because this runs on a schedule and an
 * exception per already-certified attendee would turn a routine sweep into a
 * page of noise.
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

        foreach ($webinar->accreditations as $accreditation) {
            /** @var Accreditation $accreditation */
            if ($this->alreadyDecided($learner, $webinar, $accreditation)) {
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
