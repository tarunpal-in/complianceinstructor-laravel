<?php

declare(strict_types=1);

namespace App\Filament\Account\Pages;

use App\Enums\Format;
use App\Filament\Account\Concerns\ListsLearningItems;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/** Downloadable course materials the member may save (P5). */
class Downloads extends Page
{
    use ListsLearningItems;

    protected string $view = 'filament.account.pages.learning-list';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownTray;

    protected static ?string $title = 'Downloads';

    protected static ?string $navigationLabel = 'Downloads';

    protected static ?int $navigationSort = 4;

    public function learningFormat(): Format
    {
        return Format::Download;
    }

    public function actionLabel(): string
    {
        return 'Download';
    }

    public function emptyText(): string
    {
        return 'You have no downloads yet.';
    }
}
