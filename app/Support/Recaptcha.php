<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Server-side Google reCAPTCHA v3 verification.
 *
 * Verifies a token from a protected form against Google, checking success, the
 * action, and a score threshold. When reCAPTCHA is not configured — the client
 * has not pasted their keys in Settings → Integrations — verification passes, so
 * every form keeps working until the keys turn it on. The keys are the switch;
 * no form needs editing to enable or disable protection.
 */
final class Recaptcha
{
    /** Below this v3 score a request is treated as automated. */
    private const SCORE_THRESHOLD = 0.5;

    public static function verify(?string $token, string $action): bool
    {
        // Not configured — nothing to check against, so let the form through.
        if (! Integrations::recaptchaConfigured()) {
            return true;
        }

        $token = trim((string) $token);

        if ($token === '') {
            return false;
        }

        try {
            $response = Http::asForm()->timeout(5)->post(
                'https://www.google.com/recaptcha/api/siteverify',
                ['secret' => Integrations::recaptchaSecretKey(), 'response' => $token],
            );
        } catch (Throwable) {
            // Google unreachable: fail open so an outage at Google does not take
            // the public forms down. The honeypot and rate limits still apply.
            return true;
        }

        if (! $response->successful()) {
            return true;
        }

        /** @var array<string, mixed> $data */
        $data = (array) $response->json();

        if (($data['success'] ?? false) !== true) {
            return false;
        }

        // v3 returns a score and the action it was executed for; both must check out.
        $scoreOk = (float) ($data['score'] ?? 0) >= self::SCORE_THRESHOLD;
        $actionOk = ! isset($data['action']) || $data['action'] === $action;

        return $scoreOk && $actionOk;
    }
}
