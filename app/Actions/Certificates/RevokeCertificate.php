<?php

declare(strict_types=1);

namespace App\Actions\Certificates;

use App\Models\Certificate;
use RuntimeException;

/**
 * Withdraw a certificate — CLAUDE.md §8, "Admin can revoke and reissue".
 *
 * The row stays. An accrediting body that asks about a serial has to be told it
 * was withdrawn and why, not that it never existed — a missing record looks
 * like a forged certificate, which is a far worse conversation than a revoked
 * one.
 *
 * The reason is required for the same reason it is on a revoked entitlement:
 * somebody will ask, and the answer should not live in a memory.
 */
class RevokeCertificate
{
    public function __invoke(Certificate $certificate, string $reason, ?int $revokedBy = null): Certificate
    {
        if ($certificate->isRevoked()) {
            throw new RuntimeException('That certificate has already been revoked.');
        }

        if (trim($reason) === '') {
            throw new RuntimeException('Give a reason — an auditor asking about this serial will be shown it.');
        }

        $certificate->forceFill([
            'revoked_at' => now(),
            'revoked_by' => $revokedBy ?? auth()->id(),
            'revoked_reason' => trim($reason),
        ])->save();

        return $certificate;
    }
}
