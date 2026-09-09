<?php

declare(strict_types=1);

namespace App\Filament\Resources\Payments;

use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Filament\Resources\Payments\Pages\ViewPayment;
use App\Filament\Resources\Payments\Schemas\PaymentOverview;
use App\Filament\Resources\Payments\Tables\PaymentsTable;
use App\Models\Payment;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * The payment log — CLAUDE.md §6.
 *
 * §8 improvement #1 is one `PaymentGateway` contract with both drivers writing
 * to the same table, and this screen is the point of that: one list showing
 * Stripe, PayPal and invoiced settlements together, so reconciliation never
 * branches by gateway.
 *
 * Read-only, like Orders and for the same reason. A payment is written by a
 * gateway confirming money moved, or — for an invoice — by someone recording
 * that it arrived, which happens on the order where the outstanding amount is
 * known. Neither is a thing to type into a general edit form.
 */
class PaymentResource extends Resource
{
    protected static ?string $model = Payment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static string|null|UnitEnum $navigationGroup = 'Sales';

    protected static ?string $recordTitleAttribute = 'gateway_payment_id';

    protected static ?int $navigationSort = 20;

    public static function infolist(Schema $schema): Schema
    {
        return PaymentOverview::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PaymentsTable::configure($table);
    }

    /**
     * Every row draws its order, that order's customer and its refunds.
     *
     * Model::shouldBeStrict() is on outside production, so a missing eager load
     * throws rather than quietly costing three queries a row.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['order.user', 'refunds', 'recordedBy']);
    }

    /** @return array<int, class-string> */
    public static function getRelations(): array
    {
        return [RelationManagers\RefundsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPayments::route('/'),
            'view' => ViewPayment::route('/{record}'),
        ];
    }

    /** @return array<int, string> */
    public static function getGloballySearchableAttributes(): array
    {
        return ['gateway_payment_id', 'reference'];
    }

    /** @return array<string, string> */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var Payment $record */
        return [
            'Order' => (string) $record->order->number,
            'Amount' => \App\Support\Money::format($record->amount_cents, $record->currency),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('view_any_payment') ?? false;
    }

    public static function canView(Model $record): bool
    {
        return auth()->user()?->can('view_payment') ?? false;
    }

    /** Gateways write payments; an invoiced one is recorded on its order. */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        // A payment is evidence. Even a mistaken one is corrected by a refund,
        // which leaves both entries on the record.
        return false;
    }
}
