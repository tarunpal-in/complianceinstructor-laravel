<?php

declare(strict_types=1);

namespace App\Filament\Account\Pages;

use App\Enums\Format;
use App\Filament\Account\Concerns\ListsLearningItems;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/** Written e-transcripts the member may open (P5). */
class Transcripts extends Page
{
    use ListsLearningItems;

    protected string $view = 'filament.account.pages.learning-list';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $title = 'E-Transcripts';

    protected static ?string $navigationLabel = 'E-Transcripts';

    protected static ?int $navigationSort = 3;

    public function learningFormat(): Format
    {
        return Format::Transcript;
    }

    public function actionLabel(): string
    {
        return 'Open transcript';
    }

    public function emptyText(): string
    {
        return 'You have no transcripts yet.';
    }
}
