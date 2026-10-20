<?php

declare(strict_types=1);

namespace App\Filament\Account\Concerns;

use App\Enums\Format;
use App\Models\Entitlement;
use App\Models\User;
use App\Models\Webinar;
use App\Models\WebinarAsset;

/**
 * The one list every learner page shares — the member's active grants for a
 * single format (§5.2 / P5).
 *
 * Each learner page shows exactly one thing (Upcoming / Recorded / Transcripts /
 * Downloads), so the member clicks the word for what they want rather than
 * learning a combined page — the simplicity-first direction for a non-technical
 * audience. The query is the entitlement ledger, keyed on the signed-in user, so
 * a learner only ever sees their own access (§14 #10) and the union of one-off
 * purchases and membership redemptions is true by construction (§14 #9).
 */
trait ListsLearningItems
{
    /**
     * This page's format, and whether it lists only still-upcoming sessions.
     */
    abstract public function learningFormat(): Format;

    public function upcomingOnly(): bool
    {
        return false;
    }

    /**
     * The webinars this member may open in this page's format, each with its
     * asset (null until the asset row exists) and any study handouts. Upcoming
     * sorts soonest first.
     *
     * Handouts accompany every format — a buyer gets them whatever they bought
     * (confirmed with the client) — so they are loaded on every page, Upcoming
     * included, not just the on-demand ones.
     *
     * @return list<array{webinar: Webinar, asset: WebinarAsset|null, handouts: \Illuminate\Support\Collection<int, \App\Models\WebinarHandout>}>
     */
    public function items(): array
    {
        /** @var User $user */
        $user = auth()->user();
        $format = $this->learningFormat();

        $grants = Entitlement::query()
            ->where('user_id', $user->getKey())
            ->where('format', $format->value)
            ->active()
            ->with([
                'webinar.speaker',
                'webinar.assets',
                'webinar.handouts' => fn ($query) => $query->where('is_active', true),
            ])
            ->get();

        /** @var list<array{webinar: Webinar, asset: WebinarAsset|null, handouts: \Illuminate\Support\Collection<int, \App\Models\WebinarHandout>}> $rows */
        $rows = [];

        foreach ($grants as $grant) {
            $webinar = $grant->webinar;

            if ($webinar === null) {
                continue;
            }

            // "Upcoming" is live sessions still ahead; a past live grant is an
            // attendance record, not something to join.
            if ($this->upcomingOnly() && ($webinar->scheduled_at === null || ! $webinar->scheduled_at->isFuture())) {
                continue;
            }

            $rows[] = [
                'webinar' => $webinar,
                'asset' => $webinar->assets->first(fn (WebinarAsset $asset): bool => $asset->format === $format),
                'handouts' => $webinar->relationLoaded('handouts') ? $webinar->handouts : collect(),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => ($a['webinar']->scheduled_at?->getTimestamp() ?? PHP_INT_MAX)
            <=> ($b['webinar']->scheduled_at?->getTimestamp() ?? PHP_INT_MAX));

        return $rows;
    }
}
