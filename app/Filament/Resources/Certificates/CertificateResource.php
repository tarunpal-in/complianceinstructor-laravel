<?php

declare(strict_types=1);

namespace App\Filament\Resources\Certificates;

use App\Filament\Resources\Certificates\Pages\ListCertificates;
use App\Filament\Resources\Certificates\Pages\ViewCertificate;
use App\Filament\Resources\Certificates\Schemas\CertificateOverview;
use App\Filament\Resources\Certificates\Tables\CertificatesTable;
use App\Models\Certificate;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * CEU certificates — CLAUDE.md §8 improvement #4, over the §5.8 schema.
 *
 * The credits are what customers are actually buying (§1), so this register is
 * the platform's compliance record as much as its output. Every screen here
 * treats a certificate as a document that was issued on a date and said a
 * particular thing, rather than as a row that can be tidied up afterwards.
 *
 * Read-only in the CRUD sense. Issuing is an act with its own confirmation and
 * its own permission, revoking likewise, and neither is a form field. Nothing
 * deletes: a serial an auditor can look up must resolve to "withdrawn on this
 * date for this reason", never to nothing at all — an absent record reads as a
 * forgery.
 */
class CertificateResource extends Resource
{
    protected static ?string $model = Certificate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static string|null|UnitEnum $navigationGroup = 'Learners';

    /** The slot the sidebar placeholder held, so nothing reshuffles. */
    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'serial';

    public static function infolist(Schema $schema): Schema
    {
        return CertificateOverview::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CertificatesTable::configure($table);
    }

    /**
     * Rows draw the learner and who issued them.
     *
     * The webinar and accrediting body are read off the snapshot columns rather
     * than the relations — that is the point of the snapshot — so neither is
     * loaded here.
     *
     * Model::shouldBeStrict() is on outside production, so a missing eager load
     * throws rather than quietly costing two queries a row.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['user', 'issuedBy', 'revokedBy']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCertificates::route('/'),
            'view' => ViewCertificate::route('/{record}'),
        ];
    }

    /**
     * A serial is the thing somebody arrives holding.
     *
     * @return array<int, string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['serial', 'learner_name', 'webinar_title'];
    }

    /** @return array<string, string> */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var Certificate $record */
        return [
            'Learner' => $record->learner_name,
            'Webinar' => $record->webinar_title,
            'Credits' => $record->describeCredits(),
            'Status' => $record->describeState(),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('view_any_certificate') ?? false;
    }

    public static function canView(Model $record): bool
    {
        return auth()->user()?->can('view_certificate') ?? false;
    }

    /** Issuing is an action with its own permission, never a create form. */
    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * A certificate is what it said on the day it was issued. Correcting one
     * means revoking it and issuing another, which leaves both on the record.
     */
    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }
}
