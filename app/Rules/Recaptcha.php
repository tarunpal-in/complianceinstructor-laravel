<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\Recaptcha as RecaptchaService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates a reCAPTCHA v3 token on a form submission.
 *
 * Passes when reCAPTCHA is not configured, so a form request carrying this rule
 * keeps working until the client turns reCAPTCHA on — the keys are the switch
 * (App\Support\Recaptcha). The `$action` must match the one the token component
 * executed for.
 */
class Recaptcha implements ValidationRule
{
    public function __construct(private readonly string $action) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! RecaptchaService::verify(is_string($value) ? $value : null, $this->action)) {
            $fail('We could not verify that you are human. Please try again.');
        }
    }
}
