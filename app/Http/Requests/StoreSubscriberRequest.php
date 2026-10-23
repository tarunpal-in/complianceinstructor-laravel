<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Rules\Recaptcha;
use Illuminate\Foundation\Http\FormRequest;

/**
 * What the footer signup box may send — §4 rule 3.
 *
 * Note what is not here: no status, no source, no consent flag. Where a signup
 * lands and what it is recorded as having consented to are Subscribe's
 * decisions, never a field somebody can post — §14 #2's rule, which was written
 * about `status=place_order` and reads the same way about a form that could
 * mark itself confirmed.
 */
class StoreSubscriberRequest extends FormRequest
{
    /** Hidden from people by CSS, filled in by scripts walking the DOM. */
    public const TRAP_FIELD = 'company_website';

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string|\Illuminate\Contracts\Validation\ValidationRule>|string> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email:rfc', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            self::TRAP_FIELD => ['nullable', 'prohibited'],
            // Only enforced once the client sets reCAPTCHA keys — the rule passes
            // through until then, and the field is absent when it is off.
            'recaptcha_token' => [new Recaptcha('newsletter')],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'email.required' => 'Please enter your email address.',
            'email.email' => 'That email address does not look right.',
            // The trap never fires for a person, so this only has to be honest.
            self::TRAP_FIELD.'.prohibited' => 'That could not be accepted.',
        ];
    }
}
