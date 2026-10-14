<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\RelationManagers;

use App\Actions\Entitlements\GrantEntitlement;
use App\Enums\EntitlementSource;
use App\Enums\Format;
use App\Filament\Resources\Entitlements\EntitlementResource;
use App\Models\Entitlement;
use App\Models\User;
use App\Models\Webinar;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
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
 * Rows are read-only. Taking access back is an act with consequences for a quota,
 * so it happens on the grant itself where the confirmation says what it will do.
 * Giving access, though, belongs here on the member's own record — §8 #3's staff
 * comp — rather than by typing a ledger row, which EntitlementResource refuses.
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
            ->headerActions([$this->grantAccessAction()])
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
     * Give this member access by hand — §8 #3's staff comp.
     *
     * Goes through GrantEntitlement like every other grant (§5.2 / §14 #9), with
     * source Manual, so it reads back on the ledger as "given by staff" and is
     * idempotent: granting the same format twice restores the one row rather than
     * duplicating it. Gated on `grant_entitlement`, so only a role that holds it
     * sees the button.
     */
    public function grantAccessAction(): Action
    {
        return Action::make('grantAccess')
            ->label('Grant access')
            ->icon(Heroicon::OutlinedGift)
            ->visible(fn (): bool => auth()->user()?->can('grant_entitlement') ?? false)
            ->modalHeading('Give this member access')
            ->modalDescription('Grants access without a payment — a comp or a correction. It shows on the ledger as given by staff.')
            ->schema([
                Select::make('webinar_id')
                    ->label('Webinar')
                    ->options(fn (): array => Webinar::query()->orderBy('title')->pluck('title', 'id')->all())
                    ->searchable()
                    ->required(),
                Select::make('format')
                    ->label('Format')
                    ->options(collect(Format::cases())->mapWithKeys(fn (Format $format): array => [$format->value => $format->label()])->all())
                    ->required(),
                Textarea::make('note')
                    ->label('Why')
                    ->rows(2)
                    ->maxLength(255)
                    ->placeholder('Comped after a billing mix-up')
                    ->helperText('Kept on the grant, and shown in its history.'),
            ])
            ->action(function (array $data): void {
                $member = $this->getOwnerRecord();

                if (! $member instanceof User) {
                    return;
                }

                $webinar = Webinar::query()->find($data['webinar_id']);

                if ($webinar === null) {
                    Notification::make()->danger()->title('That webinar no longer exists')->send();

                    return;
                }

                app(GrantEntitlement::class)(
                    $member,
                    $webinar,
                    Format::from((string) $data['format']),
                    EntitlementSource::Manual,
                    null,
                    null,
                    filled($data['note'] ?? null) ? (string) $data['note'] : null,
                );

                Notification::make()
                    ->success()
                    ->title('Access granted')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->send();
            });
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
