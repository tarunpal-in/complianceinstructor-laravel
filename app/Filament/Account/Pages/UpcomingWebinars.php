<?php

declare(strict_types=1);

namespace App\Filament\Account\Pages;

use App\Enums\Format;
use App\Filament\Account\Concerns\ListsLearningItems;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/** Live sessions the member may still join — the learner home (P5). */
class UpcomingWebinars extends Page
{
    use ListsLearningItems;

    protected string $view = 'filament.account.pages.learning-list';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $title = 'Upcoming Webinars';

    protected static ?string $navigationLabel = 'Upcoming Webinars';

    protected static ?int $navigationSort = 1;

    public function learningFormat(): Format
    {
        return Format::Live;
    }

    public function upcomingOnly(): bool
    {
        return true;
    }

    public function actionLabel(): string
    {
        return 'Join session';
    }

    public function opensInNewTab(): bool
    {
        return true;
    }

    public function emptyText(): string
    {
        return 'You have no upcoming live webinars. Browse the catalogue to register for one.';
    }
}
