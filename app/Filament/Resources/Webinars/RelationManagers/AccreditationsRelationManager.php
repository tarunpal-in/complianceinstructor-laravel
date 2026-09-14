<?php

declare(strict_types=1);

namespace App\Filament\Resources\Webinars\RelationManagers;

use App\Models\Accreditation;
use App\Models\Webinar;
use App\Support\CreditHours;
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
 * Which bodies accredit this webinar — CLAUDE.md §5.3.
 *
 * The credits are no longer typed here. The client's rule, given 2026-09-14, is
 * the same for every body: 30 minutes of content earns half a credit, so a
 * 90-minute webinar is worth 1.5. Setting the duration sets the credits, and
 * there is no second place for the two to disagree — which is what §12 means by
 * rules "prescribed and not negotiable".
 *
 * The override stays, and stays empty. §12 also warns these rules are not ours
 * to invent, so the day a body awards something different for one session the
 * escape hatch is there and is visible as an exception rather than the norm.
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
                ->label('Override the standard credits')
                ->numeric()
                ->minValue(0)
                ->maxValue(99)
                // decimal(4,2): half and quarter credits are both normal.
                ->step(0.25)
                ->placeholder(fn (): string => $this->owner()->standardCredits().' (standard)')
                ->helperText(CreditHours::describeRule().' Leave this empty unless the body has told you otherwise for this session.'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('code')
            ->columns([
                TextColumn::make('code')->badge()->sortable(),
                TextColumn::make('name')->wrap()->toggleable(),
                TextColumn::make('credits')
                    ->label('Credits')
                    ->badge()
                    ->state(fn (Accreditation $record): string => $this->owner()->creditsFor($record))
                    ->color(fn (Accreditation $record): string => $this->isOverridden($record) ? 'warning' : 'success')
                    // An overridden figure should look different from one the
                    // rule produced, or nobody can tell which is which.
                    ->description(fn (Accreditation $record): string => $this->isOverridden($record)
                        ? 'Overridden'
                        : 'From the '.$this->owner()->duration_minutes.'-minute duration'),
            ])
            ->headerActions([
                AttachAction::make()
                    ->label('Add body')
                    ->preloadRecordSelect()
                    // Only bodies not already attached — the pivot is unique on
                    // (webinar, accreditation), and two rows would double the
                    // credits printed on a certificate.
                    ->recordSelectOptionsQuery(fn ($query) => $query->where('is_active', true))
                    // No credits field: attaching a body is all there is to do,
                    // because the number follows from the duration.
                    ->visible(fn (): bool => auth()->user()?->can('update_webinar') ?? false),
            ])
            ->recordActions([
                EditAction::make()->label('Override'),
                DetachAction::make(),
            ])
            ->toolbarActions([BulkActionGroup::make([DetachBulkAction::make()])])
            ->emptyStateHeading('No credits assigned')
            ->emptyStateDescription('The credits are what customers are buying. A webinar without any cannot be published.');
    }

    /** Whether this body's figure was set by hand rather than by the rule. */
    private function isOverridden(Accreditation $record): bool
    {
        return $record->pivot?->getAttribute('credits') !== null;
    }

    private function owner(): Webinar
    {
        /** @var Webinar $owner */
        $owner = $this->getOwnerRecord();

        return $owner;
    }
}
