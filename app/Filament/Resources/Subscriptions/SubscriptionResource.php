<?php

declare(strict_types=1);

namespace App\Filament\Resources\Subscriptions;

use App\Filament\Resources\Subscriptions\Pages\ListSubscriptions;
use App\Filament\Resources\Subscriptions\Pages\ViewSubscription;
use App\Filament\Resources\Subscriptions\Schemas\SubscriptionOverview;
use App\Filament\Resources\Subscriptions\Tables\SubscriptionsTable;
use App\Models\Subscription;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Subscribers — CLAUDE.md §6, "Plan builder, entitlement rows, subscriber list".
 *
 * A register, not a CRUD screen, and deliberately so. §3 gives Cashier every
 * part of the subscription lifecycle: renewal, proration, dunning and
 * cancellation timing are its job, and a form that wrote `stripe_status` by
 * hand would put this table and Stripe into permanent disagreement. There is no
 * create page for the same reason — a subscription begins with a payment
 * method, which is a customer's action, not an administrator's.
 *
 * What the screen *can* do it does through Cashier (cancel, resume) or against
 * our own tables (re-apply the plan's terms). See ViewSubscription.
 */
class SubscriptionResource extends Resource
{
    protected static ?string $model = Subscription::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    protected static string|null|UnitEnum $navigationGroup = 'Memberships';

    /** The slot the sidebar placeholder held, so nothing reshuffles. */
    protected static ?int $navigationSort = 20;

    protected static ?string $modelLabel = 'subscription';

    protected static ?string $recordTitleAttribute = 'stripe_id';

    public static function infolist(Schema $schema): Schema
    {
        return SubscriptionOverview::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SubscriptionsTable::configure($table);
    }

    /**
     * Every row draws a member, a plan and an allowance summary, and the
     * "differs from plan" column compares the snapshot against the plan's own
     * rows — so both sides of that comparison are loaded here.
     *
     * Model::shouldBeStrict() is on outside production, so a missing eager load
     * throws rather than quietly costing several queries per row.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['member', 'entitlements', 'plan.entitlements']);
    }

    /**
     * What they were sold, then what they have spent of it.
     *
     * @return array<int, class-string>
     */
    public static function getRelations(): array
    {
        return [
            RelationManagers\EntitlementsRelationManager::class,
            RelationManagers\RedemptionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSubscriptions::route('/'),
            'view' => ViewSubscription::route('/{record}'),
        ];
    }

    /** @return array<int, string> */
    public static function getGloballySearchableAttributes(): array
    {
        // The Stripe id is what support is given when Stripe is the thing
        // raising the question.
        return ['stripe_id'];
    }

    /** @return array<string, string> */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var Subscription $record */
        return [
            'Member' => $record->member->name ?? 'Deleted member',
            'Plan' => $record->plan->name ?? 'No plan',
            'Status' => $record->state()->label(),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('view_any_subscription') ?? false;
    }

    public static function canView(Model $record): bool
    {
        return auth()->user()?->can('view_subscription') ?? false;
    }

    /** Cashier creates these, from a checkout. Nothing in the admin does. */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    /**
     * Never. A subscription is the record of a commercial relationship and the
     * only thing holding a member's allowance snapshot — deleting one would
     * destroy the answer to "what was this person actually sold". Ending a
     * membership is a cancellation, which Cashier handles and which leaves the
     * record in place.
     */
    public static function canDelete(Model $record): bool
    {
        return false;
    }
}
