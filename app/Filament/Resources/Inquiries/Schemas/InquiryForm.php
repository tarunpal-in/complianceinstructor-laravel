<?php

declare(strict_types=1);

namespace App\Filament\Resources\Inquiries\Schemas;

use App\Enums\InquirySource;
use App\Enums\InquiryStatus;
use App\Models\User;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Adding an inquiry by hand, or correcting one.
 *
 * The fields are exactly what a contact form collects, plus the handling
 * columns. There is no job title, industry or campaign here because nobody
 * types those into a contact form — an earlier version of this screen carried
 * all three, left over from when this register was for captured marketing
 * leads.
 *
 * Correcting a mistyped address or a name in caps is legitimate; the message
 * itself is editable for the same reason, though rewriting what somebody sent
 * is not what it is for.
 */
class InquiryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([

            Section::make('Who wrote')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(255),

                    TextInput::make('email')
                        ->email()
                        ->required()
                        ->maxLength(255)
                        // Not unique, on purpose: somebody writing twice is two
                        // messages, and the second is often because the first
                        // was not answered well enough.
                        ->helperText('Lower-cased on save. The same address may appear more than once.'),

                    TextInput::make('company')->maxLength(255),

                    TextInput::make('phone')
                        ->tel()
                        ->maxLength(40),
                ]),

            Section::make('What they asked')
                ->schema([
                    TextInput::make('subject')
                        ->maxLength(255)
                        ->placeholder('Group rates for twelve seats')
                        ->helperText('What the message is about. Shown in the list, so it is worth filling in.'),

                    Textarea::make('message')
                        ->label('Message')
                        ->rows(6)
                        ->maxLength(5000),

                    Select::make('source')
                        ->label('Arrived by')
                        ->options(InquirySource::options())
                        ->default(InquirySource::Manual->value)
                        ->required()
                        ->helperText('Manual is right for anything typed in from a call or a forwarded email.'),
                ]),

            Section::make('Handling')
                ->columns(3)
                ->schema([
                    Select::make('status')
                        ->options(InquiryStatus::options())
                        ->default(InquiryStatus::New->value)
                        ->required()
                        // Whether they bought is read off their orders, not set
                        // here — see InquiryStatus.
                        ->helperText('Use the Log a reply action rather than this, so the date is stamped too.'),

                    Select::make('assigned_to')
                        ->label('Owner')
                        ->options(fn (): array => User::query()
                            ->whereHas('roles')
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->searchable()
                        ->placeholder('Nobody yet')
                        ->helperText('Staff only. An inquiry with no owner is one nobody answers.'),

                    DateTimePicker::make('contacted_at')
                        ->label('Last contacted')
                        ->seconds(false)
                        ->placeholder('Never'),

                    Textarea::make('note')
                        ->label('Notes')
                        ->rows(4)
                        ->columnSpanFull()
                        ->helperText('Changes are kept in the History below, so an overwritten note is not lost.'),
                ]),
        ]);
    }
}
