<?php

declare(strict_types=1);

namespace App\Actions\Certificates;

use App\Models\Accreditation;
use App\Models\Certificate;
use App\Models\User;
use App\Models\Webinar;
use App\Support\CertificateSerial;
use App\Support\CreditHours;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Issue one CEU certificate — CLAUDE.md §8 improvement #4.
 *
 * Manual for now, and deliberately: §13's descope ladder puts automatic issue
 * last on the list to cut, with "admin issues manually at launch". The
 * completion rules that would drive it — an attendance threshold for live, a
 * watch-through for recorded, a passing quiz where the accreditor requires
 * assessment — need the attendance and quiz data that P5 and P7 produce. The
 * record, the serial and the register come first, because automation that
 * writes into a shape nobody has agreed is worse than none.
 *
 * §12 makes credit rules "prescribed by HRCI/SHRM and not negotiable", so the
 * credits are taken from the webinar's own accreditation pivot and never from a
 * form. A certificate asserting a number somebody typed is exactly the thing an
 * audit would object to. If the credits are wrong, the webinar is wrong.
 */
class IssueCertificate
{
    public function __invoke(
        User $learner,
        Webinar $webinar,
        Accreditation $accreditation,
        ?int $issuedBy = null,
    ): Certificate {
        return DB::transaction(function () use ($learner, $webinar, $accreditation, $issuedBy): Certificate {
            $credits = $this->creditsFor($webinar, $accreditation);

            $this->guardAgainstDuplicate($learner, $webinar, $accreditation);

            return Certificate::create([
                'user_id' => $learner->getKey(),
                'webinar_id' => $webinar->getKey(),
                'accreditation_id' => $accreditation->getKey(),

                'serial' => CertificateSerial::generate(),

                /*
                 * Frozen here, not read through the relations later. An
                 * accrediting body auditing this in 2029 must see what it said
                 * in 2026, however the webinar has been retitled or the learner
                 * renamed since.
                 */
                'learner_name' => $learner->name,
                'webinar_title' => $webinar->title,
                'accreditation_code' => $accreditation->code,
                'credits' => $credits,

                'issued_at' => now(),
                'issued_by' => $issuedBy ?? auth()->id(),
            ]);
        });
    }

    /**
     * What this webinar is worth from this body.
     *
     * Through the webinar, so the standard rule applies — 30 minutes of content
     * is half a credit (agreed 2026-09-14) — unless the body has an override
     * recorded for this session. One place decides, and it is not this one.
     *
     * Refuses rather than defaulting to zero: a certificate for a body the
     * webinar is not accredited by is not a clerical slip to be smoothed over,
     * it is a claim the business cannot support.
     */
    private function creditsFor(Webinar $webinar, Accreditation $accreditation): string
    {
        $isAccredited = $webinar->accreditations()
            ->where('accreditations.id', $accreditation->getKey())
            ->exists();

        if (! $isAccredited) {
            throw new RuntimeException(sprintf(
                '"%s" is not accredited by %s, so no certificate can be issued for it.',
                $webinar->title,
                $accreditation->code,
            ));
        }

        $credits = $webinar->creditsFor($accreditation);

        if ((float) $credits <= 0) {
            throw new RuntimeException(sprintf(
                '"%s" awards no credits, so there is nothing to certify. %s',
                $webinar->title,
                $this->whyNoCredits($webinar, $accreditation),
            ));
        }

        return $credits;
    }

    /**
     * Which of the two reasons it is.
     *
     * Worth separating: "too short" is fixed by changing the duration, and "set
     * to zero by hand" is fixed somewhere else entirely. One message covering
     * both would send somebody to the wrong screen.
     */
    private function whyNoCredits(Webinar $webinar, Accreditation $accreditation): string
    {
        $isOverridden = $webinar->accreditations()
            ->where('accreditations.id', $accreditation->getKey())
            ->first()
            ?->pivot?->getAttribute('credits') !== null;

        if ($isOverridden) {
            return sprintf('Its %s credits are overridden to zero on the webinar.', $accreditation->code);
        }

        return sprintf(
            'It runs %d minutes. %s',
            (int) $webinar->duration_minutes,
            CreditHours::describeRule(),
        );
    }

    /**
     * One live certificate per learner, per webinar, per body.
     *
     * Not a database constraint, because reissuing after a revocation needs a
     * second row and an index cannot tell the difference — the same reasoning
     * that made "one refund in flight per order" a guard rather than a UNIQUE.
     */
    private function guardAgainstDuplicate(User $learner, Webinar $webinar, Accreditation $accreditation): void
    {
        $existing = Certificate::query()
            ->valid()
            ->where('user_id', $learner->getKey())
            ->where('webinar_id', $webinar->getKey())
            ->where('accreditation_id', $accreditation->getKey())
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            throw new RuntimeException(sprintf(
                'They already hold a valid %s certificate for this webinar (%s). Revoke it before issuing another.',
                $accreditation->code,
                $existing->serial,
            ));
        }
    }
}
