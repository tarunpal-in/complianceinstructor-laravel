<?php

declare(strict_types=1);

namespace App\Import;

/**
 * The tally one importer returns — what it created, updated, skipped, and could
 * not do. The orchestrator and the dry-run report are built from these, so an
 * import is always accountable: every source row lands in exactly one bucket.
 */
class ImportResult
{
    public int $created = 0;

    public int $updated = 0;

    public int $skipped = 0;

    public int $failed = 0;

    /** @var array<int, string> */
    public array $notes = [];

    public function created(): void
    {
        $this->created++;
    }

    public function updated(): void
    {
        $this->updated++;
    }

    public function skipped(): void
    {
        $this->skipped++;
    }

    public function failed(string $note): void
    {
        $this->failed++;
        $this->note($note);
    }

    public function note(string $message): void
    {
        $this->notes[] = $message;
    }

    public function total(): int
    {
        return $this->created + $this->updated + $this->skipped + $this->failed;
    }

    public function summary(): string
    {
        return sprintf(
            '%d created, %d updated, %d skipped, %d failed (%d seen)',
            $this->created,
            $this->updated,
            $this->skipped,
            $this->failed,
            $this->total(),
        );
    }
}
