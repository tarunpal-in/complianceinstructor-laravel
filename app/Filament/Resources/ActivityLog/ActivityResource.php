<?php

declare(strict_types=1);

namespace App\Filament\Resources\ActivityLog;

use App\Filament\Resources\ActivityLog\Schemas\ActivityOverview;
use App\Filament\Resources\ActivityLog\Tables\ActivityTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Activity;
use UnitEnum;

/**
 * Who changed what — §9's audit trail, made readable.
 *
 * Thirteen models write to `activity_log` and until now nothing read it except
 * five per-record History panels. Those answer "what happened to this
 * certificate"; this answers "what did Sarah do last Tuesday", which is the
 * question asked after a complaint, a dispute, or a leaver.
 *
 * **Read-only in the strongest sense.** No create, no edit, no delete, no bulk
 * action — not even for a Super Admin. An audit trail somebody can edit is not
 * an audit trail, and the one person most likely to want a row gone is the one
 * the row is about.
 */
class ActivityResource extends Resource
{
    protected static ?string $model = Activity::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|null|UnitEnum $navigationGroup = 'System';

    /** The slot the placeholder held. */
    protected static ?int $navigationSort = 30;

    protected static ?string $navigationLabel = 'Activity Log';

    protected static ?string $modelLabel = 'entry';

    protected static ?string $pluralModelLabel = 'activity';

    /*
     * Filament derives a slug from the model name, which would put this at
     * /admin/activities. The screen is called the Activity Log everywhere
     * else — in the navigation, in §8, and in whatever somebody is told to go
     * and look at.
     */
    protected static ?string $slug = 'activity-log';

    public static function infolist(Schema $schema): Schema
    {
        return ActivityOverview::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ActivityTable::configure($table);
    }

    /**
     * Causer and subject loaded up front.
     *
     * Both are morphs, so a page of fifty entries spanning a dozen models is
     * where this screen would quietly become a hundred queries — and
     * Model::shouldBeStrict turns the first lazy load into an exception rather
     * than letting it be merely slow.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['causer', 'subject']);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListActivity::route('/'),
            'view' => Pages\ViewActivity::route('/{record}'),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('view_activity_log') ?? false;
    }

    public static function canView(Model $record): bool
    {
        return auth()->user()?->can('view_activity_log') ?? false;
    }

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
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    /** @return array<int, string> */
    public static function getGloballySearchableAttributes(): array
    {
        return ['description'];
    }
}
