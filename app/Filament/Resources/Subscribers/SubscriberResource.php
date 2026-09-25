<?php

declare(strict_types=1);

namespace App\Filament\Resources\Subscribers;

use App\Filament\Resources\Subscribers\Schemas\SubscriberForm;
use App\Filament\Resources\Subscribers\Schemas\SubscriberOverview;
use App\Filament\Resources\Subscribers\Tables\SubscribersTable;
use App\Models\Subscriber;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * The mailing list — §5.7's `subscribers`.
 *
 * Second in the Inbox group, beside Inquiries. The two are kept apart on
 * purpose: an inquiry is a message expecting a reply and is finished with once
 * answered, while a subscriber is a standing permission that has to be honoured
 * for years and proved on request.
 *
 * "Subscribers" here means the mailing list. A membership plan also has
 * subscribers — people paying for it — which live in `subscriptions` under
 * Memberships. The collision comes from §5.5 and §5.7 both using the word; the
 * plan screen's tab was renamed to "Members" so the two cannot be confused in
 * the one place somebody would see them together.
 */
class SubscriberResource extends Resource
{
    protected static ?string $model = Subscriber::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static string|null|UnitEnum $navigationGroup = 'Inbox';

    /** The slot the placeholder held, so the sidebar does not reshuffle. */
    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Subscribers';

    protected static ?string $modelLabel = 'subscriber';

    protected static ?string $recordTitleAttribute = 'email';

    public static function form(Schema $schema): Schema
    {
        return SubscriberForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return SubscriberOverview::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SubscribersTable::configure($table);
    }

    /**
     * No soft-delete scope to strip, because this model has none — the
     * migration explains why. `customer` is eager-loaded because the record
     * pages read it and Model::shouldBeStrict turns a lazy load into an
     * exception.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('customer');
    }

    /** @return array<int, class-string> */
    public static function getRelations(): array
    {
        return [RelationManagers\HistoryRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSubscribers::route('/'),
            'create' => Pages\CreateSubscriber::route('/create'),
            'view' => Pages\ViewSubscriber::route('/{record}'),
        ];
    }

    /*
     * No navigation badge. The number on this screen that anybody cares about
     * is how many people are on the list, and that is not a thing to act on —
     * a badge permanently showing it would be furniture. The tab badges carry
     * the counts instead, where they read as sizes rather than as alerts.
     */

    /** @return array<int, string> */
    public static function getGloballySearchableAttributes(): array
    {
        return ['email', 'name'];
    }

    /** @return array<string, string> */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var Subscriber $record */
        return ['Status' => $record->status->label()];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('view_any_subscriber') ?? false;
    }

    public static function canView(Model $record): bool
    {
        return auth()->user()?->can('view_subscriber') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('create_subscriber') ?? false;
    }

    /**
     * No edit page.
     *
     * Everything worth changing is a decision with a date attached —
     * subscribed, confirmed, unsubscribed — and those go through actions that
     * stamp when and who. A free-text form over the consent columns would let
     * somebody set `confirmed_at` to a date nothing happened on, which is the
     * one field on this table whose value is that it was never typed by hand.
     */
    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->can('delete_subscriber') ?? false;
    }
}
