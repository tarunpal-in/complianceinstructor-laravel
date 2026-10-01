<?php

declare(strict_types=1);

namespace App\Filament\Resources\EmailLog;

use App\Filament\Resources\EmailLog\Schemas\EmailSendOverview;
use App\Filament\Resources\EmailLog\Tables\EmailSendTable;
use App\Models\EmailSend;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Every email the application has tried to send.
 *
 * The question this screen answers is "why didn't they get it", and the answer is
 * almost never "we didn't try". It is a bounced address, an email switched off, a
 * spent daily allowance, or a template that would not render — and before this
 * existed, all four looked identical from the outside: silence.
 *
 * **Read-only, like the activity log**, with one exception: resending. That is a
 * create rather than an edit — a new row pointing at the original — so the
 * original keeps the failure that prompted it.
 *
 * `view_email_log` is a privilege worth its own permission rather than riding on
 * `view_any_user`. The full content of every email is here: invoices, reset
 * links, certificate details. Reading somebody's email is not the same as
 * reading their order.
 */
class EmailSendResource extends Resource
{
    protected static ?string $model = EmailSend::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static string|null|UnitEnum $navigationGroup = 'System';

    /** Beside the activity log, which answers the neighbouring question. */
    protected static ?int $navigationSort = 32;

    protected static ?string $navigationLabel = 'Email Log';

    protected static ?string $modelLabel = 'email';

    protected static ?string $pluralModelLabel = 'emails';

    /** Filament would derive /admin/email-sends; the screen is the Email Log everywhere else. */
    protected static ?string $slug = 'email-log';

    public static function infolist(Schema $schema): Schema
    {
        return EmailSendOverview::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EmailSendTable::configure($table);
    }

    /**
     * The recipient and the record it concerns, loaded up front.
     *
     * `related` is a morph spanning orders, users and subscribers, so a page of
     * fifty rows is exactly where this becomes a hundred queries — and
     * Model::shouldBeStrict turns the first lazy load into an exception rather
     * than letting it be merely slow.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['user', 'related', 'resentBy']);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEmailSends::route('/'),
            'view' => Pages\ViewEmailSend::route('/{record}'),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('view_email_log') ?? false;
    }

    public static function canView(Model $record): bool
    {
        return auth()->user()?->can('view_email_log') ?? false;
    }

    /*
     * Nothing may be written by hand. A log somebody can edit is not a log, and
     * the one row most likely to be inconvenient is the one that says an email
     * was never sent.
     */
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
        return ['to_email', 'subject'];
    }
}
