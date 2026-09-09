<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\RelationManagers;

use App\Models\OrderAttendee;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The named seats — CLAUDE.md §15.
 *
 * Editable, unlike the lines and payments: a customer who buys six seats and
 * typos an attendee email will ring Support, and the certificate is issued
 * against this name. Nothing here touches money.
 */
class AttendeesRelationManager extends RelationManager
{
    protected static string $relationship = 'attendees';

    protected static ?string $title = 'Attendees';

    protected static ?string $modelLabel = 'attendee';

    /**
     * Relation managers are read-only on a View page by default. Attendees are
     * the exception worth making: a mistyped attendee email is a support call on
     * the morning of the session, and fixing it touches no money.
     */
    public function isReadOnly(): bool
    {
        return false;
    }

    public function canEdit(Model $record): bool
    {
        return auth()->user()?->can('update_order') ?? false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),

            TextInput::make('email')
                ->email()
                ->required()
                ->maxLength(255)
                // Where the joining kit and the certificate go, so a typo here
                // is a support ticket on the morning of the session.
                ->helperText('Joining instructions and the certificate go to this address.'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),

                TextColumn::make('email')->searchable()->copyable(),

                TextColumn::make('orderItem.description')
                    ->label('On line')
                    ->wrap()
                    ->toggleable(),

                IconColumn::make('claimed')
                    ->label('Account claimed')
                    ->boolean()
                    ->state(fn (OrderAttendee $record): bool => $record->isClaimed())
                    // An unclaimed seat is paid for but belongs to no account,
                    // so nobody can reach the content. It is the first thing to
                    // check when someone says they cannot get in.
                    ->tooltip(fn (OrderAttendee $record): string => $record->isClaimed()
                        ? 'This attendee has registered and can reach their content.'
                        : 'Paid for, but nobody has registered with this email yet.'),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (): bool => auth()->user()?->can('update_order') ?? false),
            ])
            ->emptyStateHeading('No attendees named')
            ->emptyStateDescription('Seats are named at checkout, or added here for a phone order.');
    }
}
