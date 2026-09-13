<?php

declare(strict_types=1);

namespace App\Filament\Resources\Certificates\Tables;

use App\Models\Accreditation;
use App\Models\Certificate;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

class CertificatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('serial')
                    ->label('Serial')
                    ->searchable()
                    ->copyable()
                    ->weight('bold')
                    // The thing somebody arrives holding, so it leads and can be
                    // copied straight back out.
                    ->width('11rem'),

                /*
                 * The snapshot, not the relation. `learner_name` is what the
                 * certificate says; `user.name` is what that account is called
                 * today, and the two diverging is exactly the case the snapshot
                 * exists for.
                 */
                TextColumn::make('learner_name')
                    ->label('Learner')
                    ->searchable()
                    ->description(fn (Certificate $record): string => $record->user->email ?? 'Account deleted')
                    ->wrap()
                    ->width('15rem'),

                TextColumn::make('webinar_title')
                    ->label('Webinar')
                    ->searchable()
                    ->wrap()
                    ->width('18rem'),

                TextColumn::make('credits')
                    ->label('Credits')
                    ->badge()
                    ->state(fn (Certificate $record): string => $record->credits.' '.$record->accreditation_code)
                    ->alignEnd()
                    // What the business has actually awarded, which is the
                    // number an accrediting body asks for.
                    ->summarize(
                        Sum::make()
                            ->label('Credits awarded')
                            ->query(fn (QueryBuilder $query): QueryBuilder => $query)
                    ),

                TextColumn::make('issued_at')
                    ->label('Issued')
                    ->dateTime('j M Y')
                    ->sortable()
                    ->description(fn (Certificate $record): ?string => $record->issuedBy->name ?? null),

                TextColumn::make('state')
                    ->label('Status')
                    ->badge()
                    ->state(fn (Certificate $record): string => $record->describeState())
                    ->color(fn (Certificate $record): string => $record->stateColor())
                    ->description(fn (Certificate $record): ?string => $record->revoked_reason),

                IconColumn::make('pdf_path')
                    ->label('PDF')
                    ->boolean()
                    ->state(fn (Certificate $record): bool => $record->hasPdf())
                    // §8 wants a branded PDF on a private disk; that needs
                    // headless Chrome (§11) and is not wired yet, so this reads
                    // false everywhere for now rather than pretending.
                    ->tooltip('Generated PDFs arrive with the certificate render, which needs headless Chrome.')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('issued_at', 'desc')
            ->filters([
                SelectFilter::make('accreditation_id')
                    ->label('Accrediting body')
                    ->options(fn (): array => Accreditation::query()->orderBy('code')->pluck('code', 'id')->all()),

                /*
                 * Deliberately no "valid only" or "revoked" filters — the tabs
                 * answer both, and a filter doing a tab's job narrows every
                 * other tab on top of its own query.
                 */
                Filter::make('this_year')
                    ->label('Issued this year')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->whereYear('issued_at', now()->year)),
            ], layout: FiltersLayout::AboveContentCollapsible)
            ->recordActions([ViewAction::make()])
            // Nothing in bulk. Each of these is a compliance document about one
            // named person.
            ->toolbarActions([])
            ->emptyStateHeading('No certificates issued yet')
            ->emptyStateDescription('Issue one from a learner who has completed an accredited webinar.');
    }
}
