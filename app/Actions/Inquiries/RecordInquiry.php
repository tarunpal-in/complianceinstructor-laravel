<?php

declare(strict_types=1);

namespace App\Actions\Inquiries;

use App\Enums\InquirySource;
use App\Enums\InquiryStatus;
use App\Models\Inquiry;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * One door in for an inquiry — §4 rule 2.
 *
 * Only one intake exists today (staff typing one in), but the website contact
 * form is next and the quiz is P7. The predecessor's lesson is not about how
 * many call sites exist now: §14 #9 is one rule duplicated four times with an
 * inconsistent filter, written by somebody copying the third call site. So the
 * rules live here — what an inquiry starts as, and what it is never allowed to
 * arrive already claiming.
 */
class RecordInquiry
{
    public function __invoke(
        string $name,
        string $email,
        InquirySource $source,
        ?string $subject = null,
        ?string $message = null,
        ?string $company = null,
        ?string $phone = null,
        ?Request $request = null,
    ): Inquiry {
        $name = trim($name);
        $email = Str::lower(trim($email));

        if ($name === '' || $email === '') {
            throw new RuntimeException('An inquiry needs a name and an email address.');
        }

        return Inquiry::create([
            'name' => $name,
            'email' => $email,
            'company' => $this->clean($company),
            'phone' => $this->clean($phone),
            'source' => $source,
            'subject' => $this->clean($subject),
            // A TEXT column, so a far longer limit than the string fields — but
            // still a limit, because "whatever they typed" is not a size.
            'message' => $this->clean($message, 5000),
            /*
             * Always New, never taken from the caller. A form that could post
             * its own status is §14 #2 with the stakes lowered — and an inquiry
             * arriving pre-marked "answered" is one nobody replies to.
             */
            'status' => InquiryStatus::New,
            'ip_address' => $request?->ip(),
            // Truncated to the column: a crafted user agent must not be able to
            // fail the insert and take the contact form down with it.
            'user_agent' => $this->clean($request?->userAgent(), 250),
        ]);
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
