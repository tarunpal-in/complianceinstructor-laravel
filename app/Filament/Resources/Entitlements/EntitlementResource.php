<?php

declare(strict_types=1);

namespace App\Filament\Resources\Entitlements;

use App\Filament\Resources\Entitlements\Pages\ListEntitlements;
use App\Filament\Resources\Entitlements\Pages\ViewEntitlement;
use App\Filament\Resources\Entitlements\Schemas\EntitlementOverview;
use App\Filament\Resources\Entitlements\Tables\EntitlementsTable;
use App\Models\Entitlement;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Every grant of access, and what happened to it — CLAUDE.md §5.2.
 *
 * The ledger has been readable one subscription at a time since it was built.
 * This is the register: who has access to what, from which of the three
 * sources, and what was given back. Support's questions start here — "what can
 * this person actually see", "who redeemed this webinar", "what did we comp
 * last month" — and none of them are answerable from a subscription screen.
 *
 * Read-only, like Orders and Payments. Access is granted by buying, by spending
 * an allowance or by a staff decision recorded on the record it belongs to; a
 * form here that wrote a row directly would be a way to grant access that no
 * purchase paid for and no action logged.
 */
class EntitlementResource extends Resource
{
    protected static ?string $model = Entitlement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string|null|UnitEnum $navigationGroup = 'Learners';

    /** The slot the sidebar placeholder held, so nothing reshuffles. */
    protected static ?int $navigationSort = 20;

    /*
     * "Entitlements", not "Access grants", however much the second reads
     * better on a button. It is the word in §15's glossary, the word the
     * sidebar placeholder held, and the word the client signed the navigation
     * off with — and a resource replacing a stub takes its label as well as its
     * slot, or the sidebar reshuffles under them. The plainer phrasing belongs
     * in the descriptions, where it is not also a name.
     */
    protected static ?string $recordTitleAttribute = 'id';

    public static function infolist(Schema $schema): Schema
    {
        return EntitlementOverview::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EntitlementsTable::configure($table);
    }

    /**
     * Every row draws a member, a webinar, and — where access was taken back —
     * who took it.
     *
     * Model::shouldBeStrict() is on outside production, so a missing eager load
     * throws rather than quietly costing three queries a row.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['user', 'webinar', 'revokedBy']);
    }

    /** @return array<int, class-string> */
    public static function getRelations(): array
    {
        return [RelationManagers\HistoryRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEntitlements::route('/'),
            'view' => ViewEntitlement::route('/{record}'),
        ];
    }

    /**
     * How many grants need somebody's attention — none, normally.
     *
     * Deliberately not a count of everything: a badge reading 40,000 is
     * decoration, and this number is only worth showing when it is not zero.
     */
    public static function getNavigationBadge(): ?string
    {
        $expiring = Entitlement::query()
            ->active()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now()->addWeek())
            ->count();

        return $expiring > 0 ? (string) $expiring : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Access expiring within a week';
    }

    /** @return array<string, string> */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var Entitlement $record */
        return [
            'Member' => $record->user->name ?? 'Deleted account',
            'Webinar' => $record->webinar->title ?? 'Deleted webinar',
            'Source' => $record->source_type->label(),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('view_any_entitlement') ?? false;
    }

    public static function canView(Model $record): bool
    {
        return auth()->user()?->can('view_entitlement') ?? false;
    }

    /**
     * Access is granted by an act that records why — a capture, a redemption, a
     * staff decision on the member's own record. Never by typing a row here.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    /**
     * Never. The ledger is append-only (§5.2): access that was granted and then
     * taken away is a thing that happened, and deleting the row would erase the
     * answer to "why could this person see that in March".
     */
    public static function canDelete(Model $record): bool
    {
        return false;
    }
}
