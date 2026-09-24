<?php

declare(strict_types=1);

namespace App\Filament\Resources\Inquiries;

use App\Enums\InquiryStatus;
use App\Filament\Resources\Inquiries\Schemas\InquiryForm;
use App\Filament\Resources\Inquiries\Schemas\InquiryOverview;
use App\Filament\Resources\Inquiries\Tables\InquiriesTable;
use App\Models\Inquiry;
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
 * Everyone who got in touch — §5.7's `inquiries`.
 *
 * The Inbox's one register. §5.7 also lists `leads`, and this screen was
 * briefly built under that name on 2026-09-24 before the client's intake was
 * settled as the website contact form. The distinction between the two tables
 * turned out to be thin — an inquiry is somebody asking a question, a lead is
 * somebody worth ringing back, and on a site selling invoiced team seats the
 * same message is usually both — so they are one table with a `source` column.
 * Subscribers stays separate, because a mailing-list membership is genuinely a
 * different thing from a message expecting a reply.
 *
 * Note that no intake path exists yet: the contact form has not been built and
 * the quiz engine is P7. Until one lands, rows get here by being typed in or
 * imported, which is worth knowing before reading an empty screen as a bug.
 */
class InquiryResource extends Resource
{
    protected static ?string $model = Inquiry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInbox;

    protected static string|null|UnitEnum $navigationGroup = 'Inbox';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Inquiries';

    protected static ?string $modelLabel = 'inquiry';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return InquiryForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return InquiryOverview::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return InquiriesTable::configure($table);
    }

    /**
     * Everything the list needs, in one query.
     *
     * The two `withExists` subselects are what let the Outcome column be
     * derived rather than stored (see InquiryStatus): they answer "does this email
     * have an account" and "has that account ever paid for anything" for the
     * whole page at the cost of two subqueries, instead of two per row.
     *
     * The count does the same for repeat inquiries from one address.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class])
            // `customer` is loaded rather than left lazy because the record
            // pages read it, and Model::shouldBeStrict turns a lazy load into
            // an exception.
            ->with(['industry', 'assignedTo', 'customer'])
            ->withCount('fromSameEmail')
            ->withExists([
                'customer as has_account',
                // The same definition of "bought" the scope and the record use.
                'customer as has_bought' => Inquiry::constrainToBuyers(...),
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
            'index' => Pages\ListInquiries::route('/'),
            'create' => Pages\CreateInquiry::route('/create'),
            'view' => Pages\ViewInquiry::route('/{record}'),
            'edit' => Pages\EditInquiry::route('/{record}/edit'),
        ];
    }

    /**
     * How many nobody has picked up.
     *
     * Not the total, and not "open" — specifically the ones with no owner and
     * no reply recorded, because that is the number that means somebody asked a
     * question and is still waiting. Hidden at zero, so it means something when
     * it appears.
     */
    public static function getNavigationBadge(): ?string
    {
        $untouched = Inquiry::query()->untouched()->count();

        return $untouched > 0 ? (string) $untouched : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return InquiryStatus::New->color();
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Inquiries nobody has picked up';
    }

    /** @return array<int, string> */
    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'email', 'company'];
    }

    /** @return array<string, string> */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var Inquiry $record */
        return array_filter([
            'Company' => $record->company,
            'Status' => $record->status->label(),
        ]);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('view_any_inquiry') ?? false;
    }

    public static function canView(Model $record): bool
    {
        return auth()->user()?->can('view_inquiry') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('create_inquiry') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can('update_inquiry') ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->can('delete_inquiry') ?? false;
    }

    public static function canRestore(Model $record): bool
    {
        return auth()->user()?->can('delete_inquiry') ?? false;
    }
}
