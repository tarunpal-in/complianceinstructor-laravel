<?php

declare(strict_types=1);

namespace App\Filament\Account\Pages;

use App\Enums\Format;
use App\Filament\Account\Concerns\ListsLearningItems;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/** On-demand recordings the member may watch (P5). */
class RecordedVideos extends Page
{
    use ListsLearningItems;

    protected string $view = 'filament.account.pages.learning-list';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedVideoCamera;

    protected static ?string $title = 'Recorded Webinars';

    protected static ?string $navigationLabel = 'Recorded Webinars';

    protected static ?int $navigationSort = 2;

    public function learningFormat(): Format
    {
        return Format::Recorded;
    }

    public function actionLabel(): string
    {
        return 'Watch webinar';
    }

    public function emptyText(): string
    {
        return 'You have no recorded webinars yet.';
    }
}
