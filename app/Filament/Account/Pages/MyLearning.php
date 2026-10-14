<?php

declare(strict_types=1);

namespace App\Filament\Account\Pages;

use App\Enums\Format;
use App\Models\Entitlement;
use App\Models\User;
use App\Models\Webinar;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

/**
 * What the learner may watch — CLAUDE.md §5.2 / §6 / P5.
 *
 * The list is the entitlement ledger and nothing else: one query over the
 * member's active grants (not revoked, not expired), grouped by webinar. A
 * member with a one-off purchase and a membership redemption sees the union and
 * nothing more — the P5 exit criterion, true by construction rather than by a
 * join nobody can get right (§14 #9). Each format links to the gated access
 * route, which is where the signed URL is minted (§9).
 */
class MyLearning extends Page
{
    protected string $view = 'filament.account.pages.my-learning';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?string $title = 'My learning';

    protected static ?string $navigationLabel = 'My learning';

    protected static ?int $navigationSort = 1;

    /**
     * The webinars this member may open, each with the formats they hold.
     *
     * @return Collection<int, array{webinar: Webinar, formats: list<Format>}>
     */
    public function webinars(): Collection
    {
        /** @var User $user */
        $user = auth()->user();

        $grants = Entitlement::query()
            ->where('user_id', $user->getKey())
            ->active()
            ->with(['webinar.speaker', 'webinar.assets'])
            ->get();

        /** @var array<int, array{webinar: Webinar, formats: list<Format>}> $grouped */
        $grouped = [];

        foreach ($grants as $grant) {
            $webinar = $grant->webinar;

            if ($webinar === null) {
                continue;
            }

            $id = (int) $webinar->getKey();
            $grouped[$id] ??= ['webinar' => $webinar, 'formats' => []];
            $grouped[$id]['formats'][] = $grant->format;
        }

        return collect(array_values($grouped));
    }
}
