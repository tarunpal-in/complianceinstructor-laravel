<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * A record that points at library media and therefore writes `media_usages`.
 *
 * Satisfied by the RecordsMediaUsage trait. It exists so the reindex command can
 * be given a list of model classes and actually know what it may call on them —
 * a trait is not a type, and "call this method and hope" is how a delete guard
 * quietly stops guarding.
 */
interface ReferencesMedia
{
    /**
     * Which library media this record points at, keyed by where.
     *
     * Keys are stable identifiers for the position — 'hero.background',
     * 'brand.logo' — because two blocks on one page using the same file are two
     * usages the client needs to see separately.
     *
     * @return array<string, int|null>
     */
    public function mediaUsageMap(): array;

    public function syncMediaUsage(): void;

    public function clearMediaUsage(): void;
}
