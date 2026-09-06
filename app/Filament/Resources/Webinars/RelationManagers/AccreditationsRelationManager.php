<?php

declare(strict_types=1);

namespace App\Filament\Resources\Webinars\RelationManagers;

use App\Models\Accreditation;
use Filament\Actions\AttachAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * CEU credits per accrediting body — CLAUDE.md §5.3.
 *
 * The credits are what customers are actually buying (§1), and §12 has the
 * awarding rules "prescribed by HRCI/SHRM and not negotiable". Same body,
 * different amount per webinar, so the number lives on the pivot.
 */
class AccreditationsRelationManager extends RelationManager
{
    protected static string $relationship = 'accreditations';

    protected static ?string $title = 'Credits';

    protected static ?string $modelLabel = 'accreditation';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('credits')
                ->label('Credits awarded')
                ->numeric()
                ->required()
                ->minValue(0)
                ->maxValue(99)
                // decimal(4,2): half-credits are normal, and the value is
                // prescribed by the body rather than chosen.
                ->step(0.25)
                ->helperText('As specified by the accrediting body. Quarter and half credits are normal.'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('code')
            ->columns([
                TextColumn::make('code')->badge()->sortable(),
                TextColumn::make('name')->wrap()->toggleable(),
                TextColumn::make('pivot.credits')
                    ->label('Credits')
                    ->numeric(decimalPlaces: 2)
                    ->badge()
                    ->color('success'),
            ])
            ->headerActions([
                AttachAction::make()
                    ->label('Add body')
                    ->preloadRecordSelect()
                    // Only bodies not already attached — the pivot is unique on
                    // (webinar, accreditation), and two rows would double the
                    // credits printed on a certificate.
                    ->recordSelectOptionsQuery(fn ($query) => $query->where('is_active', true))
                    ->schema(fn (AttachAction $action): array => [
                        $action->getRecordSelect(),
                        TextInput::make('credits')
                            ->label('Credits awarded')
                            ->numeric()
                            ->required()
                            ->minValue(0)
                            ->step(0.25),
                    ])
                    ->visible(fn (): bool => auth()->user()?->can('update_webinar') ?? false),
            ])
            ->recordActions([
                EditAction::make()->label('Credits'),
                DetachAction::make(),
            ])
            ->toolbarActions([BulkActionGroup::make([DetachBulkAction::make()])])
            ->emptyStateHeading('No credits assigned')
            ->emptyStateDescription('The credits are what customers are buying. A webinar without any cannot be published.');
    }
}
