<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\RelationManagers;

use App\Filament\Resources\Entitlements\EntitlementResource;
use App\Models\Entitlement;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * What this person can actually watch — §5.2's ledger, from the customer's end.
 *
 * The single question every support ticket about access reduces to, and the one
 * the predecessor could only answer by joining three tables in four different
 * ways. All three routes show up together here: what they bought, what they
 * spent an allowance on, and what staff gave them.
 *
 * Read-only. Taking access back is an act with consequences for a quota, so it
 * happens on the grant itself where the confirmation says what it will do.
 */
class AccessRelationManager extends RelationManager
{
    protected static string $relationship = 'entitlements';

    protected static ?string $title = 'Access';

    protected static ?string $modelLabel = 'grant';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('webinar'))
            ->columns([
                TextColumn::make('webinar.title')
                    ->label('Webinar')
                    ->searchable()
                    ->wrap()
                    ->width('20rem')
                    ->url(fn (Entitlement $record): string => EntitlementResource::getUrl('view', ['record' => $record])),

                TextColumn::make('format')
                    ->label('Format')
                    ->badge()
                    ->formatStateUsing(fn (Entitlement $record): string => $record->format->label()),

                TextColumn::make('source_type')
                    ->label('How')
                    ->badge()
                    ->formatStateUsing(fn (Entitlement $record): string => $record->source_type->label())
                    ->color(fn (Entitlement $record): string => $record->source_type->color()),

                TextColumn::make('granted_at')
                    ->label('Since')
                    ->dateTime('j M Y')
                    ->sortable(),

                TextColumn::make('state')
                    ->label('Status')
                    ->badge()
                    ->state(fn (Entitlement $record): string => $record->describeState())
                    ->color(fn (Entitlement $record): string => $record->stateColor()),
            ])
            ->defaultSort('granted_at', 'desc')
            ->filters([
                // What they can watch right now, which is the question being
                // asked nine times in ten.
                Filter::make('active')
                    ->label('Currently accessible only')
                    ->toggle()
                    ->query(self::onlyActive(...)),
            ])
            ->emptyStateHeading('No access yet')
            ->emptyStateDescription('Access appears here as this person buys, spends a membership allowance, or is given something by staff.');
    }

    /**
     * Named rather than inline: a filter is handed an untyped builder, where a
     * model scope is invisible to static analysis.
     *
     * @param  Builder<Entitlement>  $query
     * @return Builder<Entitlement>
     */
    private static function onlyActive(Builder $query): Builder
    {
        return $query->active();
    }
}
