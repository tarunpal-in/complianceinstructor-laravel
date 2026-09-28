<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Reports\OperationsSnapshot;
use Filament\Widgets\Widget;

/**
 * The short list of things that are waiting on somebody.
 *
 * A custom widget rather than a stats strip, because every row has to be able
 * to disappear. Stats sit in a fixed grid and a permanent row reading "0
 * overdue invoices" is furniture — this panel is only worth having if the fact
 * that it has anything in it *means* something.
 *
 * When everything is clear it says so in one line, which is the state it should
 * be in most mornings.
 */
class NeedsAction extends Widget
{
    protected static ?int $sort = -15;

    protected static bool $isLazy = false;

    protected string $view = 'filament.widgets.needs-action';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->can('view_reports') ?? false;
    }

    /** @return array<int, array{label: string, count: int, tone: string, url: string|null}> */
    public function getRows(): array
    {
        return (new OperationsSnapshot)->attention();
    }
}
