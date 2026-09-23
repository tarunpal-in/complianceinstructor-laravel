<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * What a reader may send when leaving a comment — §4 rule 3.
 *
 * Note what is *not* here: no status, no post id, no parent beyond an id that
 * the action then re-checks against the article. Where a comment lands is
 * decided by SubmitComment, never by the request — §14 #2's rule, which was
 * written about `status=place_order` but reads the same way for a field called
 * `status` on a comment form.
 */
class StoreCommentRequest extends FormRequest
{
    /**
     * The honeypot.
     *
     * Named after something a form filler wants to fill in. It is hidden from
     * people by CSS and left empty by them; a bot walking the DOM fills every
     * input it finds. Cheap, silent, and it catches most of what a spam filter
     * would otherwise have to read.
     */
    public const TRAP_FIELD = 'website';

    public function authorize(): bool
    {
        // Anyone may try. Whether the article is open, and whether what they
        // wrote gets published, are both decided further in.
        return true;
    }

    /** @return array<string, array<int, string>|string> */
    public function rules(): array
    {
        return [
            'author_name' => ['required', 'string', 'min:2', 'max:80'],
            'author_email' => ['required', 'email:rfc', 'max:255'],
            'body' => ['required', 'string', 'min:2', 'max:2000'],
            'parent_id' => ['nullable', 'integer', 'exists:comments,id'],
            self::TRAP_FIELD => ['nullable', 'prohibited'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'author_name.required' => 'Please give a name to show with your comment.',
            'author_email.required' => 'Please give an email address. It is not published.',
            'author_email.email' => 'That email address does not look right.',
            'body.required' => 'Write something first.',
            'body.max' => 'Please keep it under 2,000 characters.',
            // The trap should never fire for a person, so the message only has
            // to be honest rather than helpful.
            self::TRAP_FIELD.'.prohibited' => 'That comment could not be accepted.',
        ];
    }

    /** Whether the trap was sprung — checked separately where it matters. */
    public function looksAutomated(): bool
    {
        return filled($this->input(self::TRAP_FIELD));
    }
}
