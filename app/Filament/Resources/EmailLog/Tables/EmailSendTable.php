<?php

declare(strict_types=1);

namespace App\Filament\Resources\EmailLog\Tables;

use App\Email\EmailCatalogue;
use App\Email\EmailDefinition;
use App\Enums\EmailStatus;
use App\Models\EmailSend;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The log, newest first.
 *
 * Somebody arriving here has been asked a question — usually "did this customer
 * get their receipt?" — so the recipient is searchable, the status is loud, and
 * the record it concerns links straight out.
 */
class EmailSendTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime('j M Y, H:i')
                    ->description(fn (EmailSend $record): ?string => $record->created_at?->diffForHumans())
                    ->sortable()
                    ->width('12rem'),

                TextColumn::make('to_email')
                    ->label('To')
                    ->description(fn (EmailSend $record): ?string => $record->to_name)
                    ->searchable()
                    ->copyable()
                    ->width('16rem'),

                TextColumn::make('email_key')
                    ->label('Email')
                    ->state(fn (EmailSend $record): string => $record->name())
                    ->description(fn (EmailSend $record): string => $record->subject)
                    ->wrap(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (EmailStatus $state): string => $state->label())
                    ->color(fn (EmailStatus $state): string => $state->color())
                    // Why, where there is a why. A status of "not sent" on its own
                    // answers nothing, and this screen exists to answer.
                    ->description(fn (EmailSend $record): ?string => $record->outcome_note ?? $record->error)
                    ->width('12rem'),

                TextColumn::make('related')
                    ->label('Record')
                    ->state(fn (EmailSend $record): string => self::relatedLabel($record))
                    ->url(fn (EmailSend $record): ?string => self::relatedUrl($record))
                    ->color(fn (EmailSend $record): string => self::relatedUrl($record) === null ? 'gray' : 'primary')
                    ->width('14rem'),

                TextColumn::make('attempts')
                    ->label('Tries')
                    ->alignRight()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('resentBy.name')
                    ->label('Resent by')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('email_key')
                    ->label('Email')
                    ->options(fn (): array => collect(EmailCatalogue::all())
                        ->map(fn (EmailDefinition $email): string => $email->name)
                        ->all())
                    ->multiple()
                    ->searchable(),

                SelectFilter::make('status')
                    ->label('Status')
                    ->options(EmailStatus::options())
                    ->multiple(),

                /*
                 * A date range rather than presets, for the reason the activity
                 * log has one: the question arrives with a date attached — "the
                 * receipt for the order on the 14th".
                 */
                Filter::make('period')
                    ->schema([
                        \Filament\Forms\Components\DatePicker::make('from')->label('From'),
                        \Filament\Forms\Components\DatePicker::make('until')->label('Until'),
                    ])
                    ->query(self::withinPeriod(...))
                    ->indicateUsing(self::describePeriod(...)),
            ], layout: FiltersLayout::AboveContentCollapsible)
            ->recordActions([ViewAction::make()])
            // No edit, no delete, no bulk action. Resending lives on the view
            // page, where the admin can see what they are about to send again.
            ->emptyStateHeading('Nothing sent yet')
            ->emptyStateDescription('Every email the system sends appears here, including the ones it decided not to send.');
    }

    /**
     * What the email was about.
     *
     * Falls back to the morph type rather than showing nothing, because "the
     * order it belonged to has been deleted" is itself an answer.
     */
    private static function relatedLabel(EmailSend $record): string
    {
        $related = $record->related;

        if ($related === null) {
            return $record->related_type === null
                ? '—'
                : class_basename($record->related_type).' (deleted)';
        }

        $attributes = $related->getAttributes();

        foreach (['number', 'serial', 'title', 'name', 'email'] as $attribute) {
            $value = $attributes[$attribute] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return class_basename($related).' #'.$related->getKey();
    }

    /**
     * The record's own screen, if the reader may open it.
     *
     * Same rule as the activity log: a log must not become a way round the
     * permissions on everything else. Seeing that a receipt was sent is not
     * seeing the order.
     */
    private static function relatedUrl(EmailSend $record): ?string
    {
        $related = $record->related;

        if ($related === null) {
            return null;
        }

        $resource = \Filament\Facades\Filament::getPanel('admin')
            ->getModelResource($related::class);

        if ($resource === null || ! $resource::canViewAny()) {
            return null;
        }

        $pages = array_keys($resource::getPages());

        foreach (['view', 'edit'] as $page) {
            if (in_array($page, $pages, true)) {
                return $resource::getUrl($page, ['record' => $related], panel: 'admin');
            }
        }

        return null;
    }

    /**
     * @param  Builder<EmailSend>  $query
     * @param  array<string, mixed>  $data
     * @return Builder<EmailSend>
     */
    private static function withinPeriod(Builder $query, array $data): Builder
    {
        return $query
            ->when(
                filled($data['from'] ?? null),
                fn (Builder $from): Builder => $from->whereDate('created_at', '>=', $data['from']),
            )
            ->when(
                filled($data['until'] ?? null),
                fn (Builder $until): Builder => $until->whereDate('created_at', '<=', $data['until']),
            );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    private static function describePeriod(array $data): array
    {
        $indicators = [];

        if (filled($data['from'] ?? null)) {
            $indicators[] = 'From '.Carbon::parse((string) $data['from'])->format('j M Y');
        }

        if (filled($data['until'] ?? null)) {
            $indicators[] = 'Until '.Carbon::parse((string) $data['until'])->format('j M Y');
        }

        return $indicators;
    }
}
