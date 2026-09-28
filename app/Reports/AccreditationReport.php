<?php

declare(strict_types=1);

namespace App\Reports;

use App\Models\Accreditation;
use App\Models\Certificate;
use App\Support\Period;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Credits awarded, per accrediting body, over a period.
 *
 * Not in §8's list, and the most consequential report here. HRCI and SHRM
 * require an approved provider to **report the credits it has awarded**, and
 * §12's risk register already flags their rules as prescribed and not
 * negotiable. Getting this wrong is not a bad dashboard, it is an
 * accreditation problem.
 *
 * It reads the snapshot on each certificate rather than joining back to the
 * webinar and the accreditation: `accreditation_code`, `credits`, `learner_name`
 * and `webinar_title` were frozen at issue, and a webinar renamed or requstomed
 * since must not change what was already reported. Same principle as an order's
 * billing snapshot in §5.4.
 *
 * **Revoked certificates are excluded from the figures and listed separately.**
 * A revoked certificate is one that should never have been awarded, and
 * reporting it as awarded is the error this report exists to prevent — but it
 * still has to be visible, because a body that received it in a previous
 * submission needs telling.
 */
class AccreditationReport
{
    public function __construct(private readonly Period $period) {}

    /**
     * One row per accrediting body: how many, and how many hours.
     *
     * Grouped by the code on the certificate, not by the `accreditations` row,
     * so a body removed from the catalogue still appears in a period where it
     * awarded credits.
     *
     * @return Collection<int, AccreditationTotal>
     */
    public function summary(): Collection
    {
        $names = Accreditation::query()->pluck('name', 'code');

        return $this->issued()
            // Selected under its own name, not aliased: an aggregate query
            // hydrates a real Certificate, and under
            // Model::preventAccessingMissingAttributes reading a column that
            // was renamed in the SELECT throws rather than returning null.
            ->selectRaw('accreditation_code')
            ->selectRaw('COUNT(*) as certificates')
            ->selectRaw('SUM(credits) as credits')
            ->selectRaw('COUNT(DISTINCT user_id) as learners')
            ->groupBy('accreditation_code')
            ->orderBy('accreditation_code')
            ->get()
            ->map(fn (Certificate $row): AccreditationTotal => new AccreditationTotal(
                code: (string) $row->accreditation_code,
                name: (string) ($names[$row->accreditation_code] ?? $row->accreditation_code),
                certificates: (int) $row->getAttribute('certificates'),
                // A string, not a float: credits are decimal(4,2) and half
                // credits are the whole point (§ CreditHours — 90 minutes is
                // 1.5). Formatting here keeps "1.50" out of float arithmetic.
                credits: number_format((float) $row->getAttribute('credits'), 2, '.', ''),
                learners: (int) $row->getAttribute('learners'),
            ));
    }

    /**
     * Every certificate awarded in the period, for the submission itself.
     *
     * @return Collection<int, Certificate>
     */
    public function awarded(?string $code = null): Collection
    {
        return $this->issued()
            ->when($code !== null, fn (Builder $query) => $query->where('accreditation_code', $code))
            ->with('user')
            ->orderBy('accreditation_code')
            ->orderBy('issued_at')
            ->get();
    }

    /**
     * Certificates revoked in the period.
     *
     * Listed by the date they were *revoked*, not issued — the same reasoning
     * as the sales report charging a refund to the period it happened in. One
     * awarded in March and revoked in May belongs in May's submission as a
     * withdrawal, and March's was correct when it was made.
     *
     * @return Collection<int, Certificate>
     */
    public function revoked(): Collection
    {
        return Certificate::query()
            ->whereNotNull('revoked_at')
            ->whereBetween('revoked_at', [$this->period->from, $this->period->to])
            ->with(['user', 'revokedBy'])
            ->orderBy('revoked_at')
            ->get();
    }

    public function totalCertificates(): int
    {
        return $this->issued()->count();
    }

    /** Total credit hours awarded, as a decimal string. */
    public function totalCredits(): string
    {
        return number_format((float) $this->issued()->sum('credits'), 2, '.', '');
    }

    public function totalLearners(): int
    {
        return (int) $this->issued()->distinct()->count('user_id');
    }

    /**
     * Certificates issued in the period and still standing.
     *
     * @return Builder<Certificate>
     */
    private function issued(): Builder
    {
        return Certificate::query()
            ->valid()
            ->whereBetween('issued_at', [$this->period->from, $this->period->to]);
    }
}
