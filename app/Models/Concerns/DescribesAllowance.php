<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Enums\AllowanceWindow;
use App\Enums\Format;

/**
 * Shared reading of one allowance row.
 *
 * `plan_entitlements` and `subscription_entitlements` hold the same three
 * columns for the same reason — one is the template, the other the copy taken
 * at signup. Describing them separately would let the plan screen and the
 * subscriber screen eventually disagree about what "unlimited" reads as, which
 * is exactly the sort of drift §14 #12 is about.
 *
 * @property Format $format
 * @property int|null $quantity
 * @property AllowanceWindow $window
 */
trait DescribesAllowance
{
    /**
     * NULL quantity means unlimited (§5.5) — never 0, never -1.
     *
     * Asked as a method so no caller has to remember which sentinel was chosen,
     * and so arithmetic can never run on the word "unlimited".
     */
    public function isUnlimited(): bool
    {
        return $this->quantity === null;
    }

    /** "4 live per term", "unlimited transcripts". */
    public function describe(): string
    {
        $format = strtolower($this->format->label());

        return $this->isUnlimited()
            ? 'unlimited '.$format
            : $this->quantity.' '.$format.' '.$this->window->shortLabel();
    }

    /**
     * How many are left after `$used` in the current window.
     *
     * Null means there is no limit to be left of. P4's redemption check reads
     * this rather than comparing a nullable quantity itself — the predecessor
     * lost a whole quota check to a comparison written slightly differently in
     * one of four places.
     */
    public function remainingAfter(int $used): ?int
    {
        if ($this->isUnlimited()) {
            return null;
        }

        return max(0, (int) $this->quantity - $used);
    }
}
