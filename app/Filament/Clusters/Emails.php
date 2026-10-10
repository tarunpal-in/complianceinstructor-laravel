<?php

declare(strict_types=1);

namespace App\Filament\Clusters;

use BackedEnum;
use Filament\Clusters\Cluster;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * One sidebar entry for everything to do with email.
 *
 * Two screens were competing for the reader's attention in the System group —
 * "Emails" (what the site sends, and the switches for it) and "Email Log" (what
 * it has sent). They are the same subject looked at from two ends, so they read
 * better as one item with two tabs than as two items a reader has to know to
 * check separately.
 *
 * A cluster is the Filament shape for that: it registers a single navigation
 * item and shows its members — the overview page and the log — as sub-navigation
 * once you are inside. Opening it lands on the overview and redirects there, so
 * the cluster itself is never a dead page.
 *
 * Gated the same way its contents are. `shouldRegisterNavigation` already hides
 * the cluster when no member is reachable, and `canAccess` repeats the check for
 * a direct URL, so a Content Editor — who holds no `view_email_log` — neither
 * sees it nor can open it.
 */
class Emails extends Cluster
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInbox;

    protected static string|null|UnitEnum $navigationGroup = 'System';

    /** The slot the two separate items used to share. */
    protected static ?int $navigationSort = 31;

    protected static ?string $navigationLabel = 'Emails';

    protected static ?string $slug = 'emails';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view_email_log') ?? false;
    }
}
