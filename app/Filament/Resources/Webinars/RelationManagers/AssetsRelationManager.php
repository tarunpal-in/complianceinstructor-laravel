<?php

declare(strict_types=1);

namespace App\Filament\Resources\Webinars\RelationManagers;

use App\Enums\Format;
use App\Models\WebinarAsset;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * What a buyer actually receives — CLAUDE.md §5.3.
 *
 * The live join link, the recording, the transcript PDF, the course materials.
 * Replaces the eight link/file columns the predecessor kept on its webinars
 * table.
 *
 * Files go to the private disk, never public/: §9 requires they reach the
 * customer through a signed, expiring URL resolved by entitlement rather than a
 * guessable path. That serving layer is P5; this is where they are stored.
 */
class AssetsRelationManager extends RelationManager
{
    protected static string $relationship = 'assets';

    protected static ?string $title = 'Deliverables';

    protected static ?string $modelLabel = 'deliverable';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                Select::make('format')
                    ->options(Format::options())
                    ->required()
                    ->live()
                    ->helperText('Which purchased format this satisfies.'),

                TextInput::make('label')
                    ->maxLength(255)
                    ->helperText('Optional. Shown to the learner instead of the format name.'),

                TextInput::make('url')
                    ->label('Link')
                    ->url()
                    ->maxLength(2048)
                    ->columnSpanFull()
                    ->helperText(fn (callable $get): string => $get('format') === Format::Live->value
                        ? 'The Zoom or GoToWebinar join link. Released to attendees at the time below.'
                        : 'Use this for something hosted elsewhere — a video CDN, for example.'),

                FileUpload::make('disk_path')
                    ->label('File')
                    ->disk(config('filesystems.webinar_assets'))
                    ->directory('webinar-assets')
                    ->visibility('private')
                    // 2 GB, so a recording can be uploaded here, not just a small
                    // transcript. PHP's upload_max_filesize / post_max_size and
                    // the web server's body limit must allow it too; for very
                    // large videos, uploading straight into the R2 bucket (or a
                    // streaming platform URL in Link) is still the better path.
                    ->maxSize(2 * 1024 * 1024)
                    ->columnSpanFull()
                    // The private webinar-assets disk (local in dev, Cloudflare
                    // R2 in production); never public/, which would make the file
                    // reachable by anyone who guesses the path (§9).
                    ->helperText('Stored privately (Cloudflare R2 in production). Videos up to 2 GB; transcripts and materials welcome too.'),

                Toggle::make('embed')
                    ->label('Play inside the site')
                    ->default(false)
                    ->visible(fn (callable $get): bool => $get('format') === Format::Recorded->value)
                    ->columnSpanFull()
                    // Off: the recording just opens — a share link (Dropbox, a
                    // CDN) in a new tab, or a download of the file. On: it plays
                    // in the site's own player, for an uploaded file or a
                    // platform embed URL. A plain Dropbox share link will not
                    // iframe, so leave this off for one.
                    ->helperText('Off: the learner opens the link or downloads the file (use for a Dropbox or share link). On: the recording plays in our embedded player — for a file uploaded above, or a streaming platform\'s embed URL.'),

                DateTimePicker::make('available_from')
                    ->label('Release at')
                    ->seconds(false)
                    ->helperText('Leave empty to release immediately. A join link is usually held until shortly before the session.'),

                Toggle::make('is_active')
                    ->label('Active')
                    ->default(true),
            ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('format')
            ->columns([
                TextColumn::make('format')
                    ->badge()
                    ->formatStateUsing(fn (Format $state): string => $state->label()),

                TextColumn::make('label')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('url')
                    ->label('Link')
                    ->limit(40)
                    ->url(fn (WebinarAsset $record): ?string => $record->url)
                    ->openUrlInNewTab()
                    ->placeholder('—'),

                IconColumn::make('disk_path')
                    ->label('File')
                    ->boolean()
                    ->getStateUsing(fn (WebinarAsset $record): bool => filled($record->disk_path)),

                IconColumn::make('embed')
                    ->label('In-site player')
                    ->boolean()
                    ->toggleable(),

                TextColumn::make('available_from')
                    ->label('Released')
                    ->dateTime('j M Y · H:i')
                    ->placeholder('Immediately')
                    ->description(fn (WebinarAsset $record): string => $record->isReleased() ? 'Available now' : 'Not yet available'),

                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->defaultSort('format')
            ->filters([
                SelectFilter::make('format')->options(Format::options()),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Add deliverable')
                    ->visible(fn (): bool => auth()->user()?->can('update_webinar') ?? false),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])])
            ->emptyStateHeading('Nothing to deliver yet')
            ->emptyStateDescription('Add the join link, recording, transcript or materials that buyers receive.');
    }
}
