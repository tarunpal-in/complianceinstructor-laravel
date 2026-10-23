<?php

declare(strict_types=1);

namespace App\Filament\Resources\Webinars\RelationManagers;

use App\Models\WebinarHandout;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Study materials that ship with a webinar — CLAUDE.md §6 / P5.
 *
 * Supplementary files (slides, worksheets, spreadsheets, images) handed to any
 * learner who holds access to the webinar in any format. The file goes to the
 * private webinar-assets disk (R2 in production), never public/, and reaches the
 * learner only through a signed, expiring URL by entitlement (§9).
 */
class HandoutsRelationManager extends RelationManager
{
    protected static string $relationship = 'handouts';

    protected static ?string $title = 'Handouts';

    protected static ?string $modelLabel = 'handout';

    /** The study-material file types the client provides. */
    private const ACCEPTED = [
        'application/pdf',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'text/csv',
        'application/csv',
        'image/jpeg',
        'image/png',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ];

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                FileUpload::make('disk_path')
                    ->label('File')
                    ->required()
                    ->disk(config('filesystems.webinar_assets'))
                    ->directory('webinar-handouts')
                    ->visibility('private')
                    ->acceptedFileTypes(self::ACCEPTED)
                    ->maxSize(100 * 1024)
                    ->columnSpanFull()
                    ->helperText('PDF, Excel (xls/xlsx/csv), Word, PowerPoint or an image (jpg/png). Stored privately (Cloudflare R2 in production).'),

                TextInput::make('label')
                    ->label('Title')
                    ->maxLength(120)
                    ->helperText('Optional. Shown to the learner instead of the file name.'),

                TextInput::make('sort_order')
                    ->label('Position')
                    ->numeric()
                    ->default(0)
                    ->helperText('Lower numbers first.'),

                Toggle::make('is_active')
                    ->label('Available')
                    ->default(true)
                    ->helperText('Turn off to hide it from learners without deleting it.'),
            ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('label')
            ->columns([
                TextColumn::make('label')
                    ->label('Title')
                    ->state(fn (WebinarHandout $record): string => $record->displayName())
                    ->description(fn (WebinarHandout $record): string => $record->extension())
                    ->wrap(),

                TextColumn::make('sort_order')->label('Position')->sortable(),

                IconColumn::make('is_active')->label('Available')->boolean(),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->headerActions([
                CreateAction::make()
                    ->label('Add handout')
                    ->visible(fn (): bool => $this->canManage()),
            ])
            ->recordActions([
                EditAction::make()->visible(fn (): bool => $this->canManage()),
                DeleteAction::make()->visible(fn (): bool => $this->canManage()),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->visible(fn (): bool => $this->canManage()),
                ]),
            ])
            ->emptyStateHeading('No handouts yet')
            ->emptyStateDescription('Add slides, worksheets or materials that accompany this webinar.');
    }

    /**
     * Handouts belong to the webinar, so every mutating action is gated on
     * `update_webinar` (§4 #4 / §9), not just create. A view-only role such as
     * Support sees the list but no edit, delete or bulk-delete action.
     */
    private function canManage(): bool
    {
        return auth()->user()?->can('update_webinar') ?? false;
    }
}
