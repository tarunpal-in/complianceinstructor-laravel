<?php

declare(strict_types=1);

namespace App\Actions\Leads;

use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * One door in for a lead — §4 rule 2.
 *
 * There is only one intake today (staff typing one in), but there are four
 * coming: the quiz result form, a gated download, a webinar enquiry and the
 * contact form. The predecessor's lesson is not about how many call sites exist
 * now but about what happens when the fourth one is written by somebody who
 * copies the third: §14 #9 is one access rule duplicated four times with an
 * inconsistent filter, and this is the same shape in a different corner.
 *
 * So the rules live here: the status a lead starts at, the campaign it carries,
 * and what it is never allowed to arrive already claiming.
 */
class RecordLead
{
    public function __invoke(
        string $name,
        string $email,
        LeadSource $source,
        ?string $company = null,
        ?string $jobTitle = null,
        ?string $phone = null,
        ?int $industryId = null,
        ?string $sourceDetail = null,
        ?string $message = null,
        bool $marketingOptIn = false,
        ?Request $request = null,
    ): Lead {
        $name = trim($name);
        $email = Str::lower(trim($email));

        if ($name === '' || $email === '') {
            throw new RuntimeException('A lead needs a name and an email address.');
        }

        return Lead::create([
            'name' => $name,
            'email' => $email,
            'company' => $this->clean($company),
            'job_title' => $this->clean($jobTitle),
            'phone' => $this->clean($phone),
            'industry_id' => $industryId,
            'source' => $source,
            'source_detail' => $this->clean($sourceDetail),
            /*
             * Always New, never taken from the caller. A form that could post
             * its own status is §14 #2 with the stakes lowered — and a lead
             * arriving pre-marked "qualified" is one nobody rings.
             */
            'status' => LeadStatus::New,
            // A TEXT column, so a far longer limit than the string fields —
            // but still a limit, because "whatever they typed" is not a size.
            'message' => $this->clean($message, 5000),
            /*
             * Consent is only ever true because somebody said so. An imported
             * list did not agree to anything, whatever the spreadsheet claimed.
             */
            'marketing_opt_in' => $marketingOptIn && $source->isSelfSubmitted(),
            ...$this->attribution($request),
        ]);
    }

    /**
     * The campaign that produced them — §8's lead source attribution.
     *
     * Read from the request rather than passed in, so a new form does not have
     * to remember to forward five query parameters. Truncated because these are
     * query-string values and a crafted one must not be able to fail the insert
     * and take the form down with it.
     *
     * @return array<string, string|null>
     */
    private function attribution(?Request $request): array
    {
        if ($request === null) {
            return [];
        }

        return [
            'utm_source' => $this->clean($request->query('utm_source')),
            'utm_medium' => $this->clean($request->query('utm_medium')),
            'utm_campaign' => $this->clean($request->query('utm_campaign')),
            'ip_address' => $request->ip(),
            'user_agent' => $this->clean($request->userAgent(), 250),
        ];
    }

    private function clean(mixed $value, int $limit = 255): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : Str::limit($value, $limit, '');
    }
}
