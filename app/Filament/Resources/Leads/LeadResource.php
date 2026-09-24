<?php

declare(strict_types=1);

namespace App\Filament\Resources\Leads;

use App\Enums\LeadStatus;
use App\Filament\Resources\Leads\Schemas\LeadForm;
use App\Filament\Resources\Leads\Schemas\LeadOverview;
use App\Filament\Resources\Leads\Tables\LeadsTable;
use App\Models\Lead;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

/**
 * Captured leads — §5.7, and the leads half of §6's Quiz & leads module.
 *
 * The first thing in the Inbox group, which the panel already declared and
 * which is where the rest of what arrives unasked — inquiries, subscribers —
 * belongs as those get built.
 *
 * Note that no intake path exists yet. The quiz engine is P7, and there is no
 * enquiry form block in the page builder. Until one of those lands, leads get
 * here by being typed in or imported, and that is worth knowing before reading
 * an empty screen as a bug.
 */
class LeadResource extends Resource
{
    protected static ?string $model = Lead::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInbox;

    protected static string|null|UnitEnum $navigationGroup = 'Inbox';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Leads';

    protected static ?string $modelLabel = 'lead';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return LeadForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return LeadOverview::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LeadsTable::configure($table);
    }

    /**
     * Everything the list needs, in one query.
     *
     * The two `withExists` subselects are what let the Outcome column be
     * derived rather than stored (see LeadStatus): they answer "does this email
     * have an account" and "has that account ever paid for anything" for the
     * whole page at the cost of two subqueries, instead of two per row.
     *
     * The count does the same for repeat enquiries from one address.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class])
            // `customer` is loaded rather than left lazy because the record
            // pages read it, and Model::shouldBeStrict turns a lazy load into
            // an exception.
            ->with(['industry', 'assignedTo', 'customer'])
            ->withCount('enquiries')
            ->withExists([
                'customer as has_account',
                // The same definition of "bought" the scope and the record use.
                'customer as has_bought' => Lead::constrainToBuyers(...),
            ]);
    }

    /** @return array<int, class-string> */
    public static function getRelations(): array
    {
        return [RelationManagers\HistoryRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLeads::route('/'),
            'create' => Pages\CreateLead::route('/create'),
            'view' => Pages\ViewLead::route('/{record}'),
            'edit' => Pages\EditLead::route('/{record}/edit'),
        ];
    }

    /**
     * How many nobody has picked up.
     *
     * Not the total, and not "open" — specifically the ones with no owner and
     * no contact recorded, because that is the number that represents marketing
     * spend quietly going to waste. Hidden at zero, so it means something when
     * it appears.
     */
    public static function getNavigationBadge(): ?string
    {
        $untouched = Lead::query()->untouched()->count();

        return $untouched > 0 ? (string) $untouched : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return LeadStatus::New->color();
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Leads nobody has picked up';
    }

    /** @return array<int, string> */
    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'email', 'company'];
    }

    /** @return array<string, string> */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var Lead $record */
        return array_filter([
            'Company' => $record->company,
            'Status' => $record->status->label(),
        ]);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('view_any_lead') ?? false;
    }

    public static function canView(Model $record): bool
    {
        return auth()->user()?->can('view_lead') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('create_lead') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can('update_lead') ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->can('delete_lead') ?? false;
    }

    public static function canRestore(Model $record): bool
    {
        return auth()->user()?->can('delete_lead') ?? false;
    }
}
