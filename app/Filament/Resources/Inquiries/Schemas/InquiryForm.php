<?php

declare(strict_types=1);

namespace App\Filament\Resources\Inquiries\Schemas;

use App\Enums\InquirySource;
use App\Enums\InquiryStatus;
use App\Models\Industry;
use App\Models\User;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Adding or working an inquiry.
 *
 * Two jobs on one screen, and the order reflects which is more common: almost
 * every visit here is somebody recording what happened on a phone call, not
 * correcting a spelling. So who they are sits at the top and is short, and the
 * follow-up fields — owner, status, when they were last spoken to — are what
 * the screen is really for.
 *
 * What a captured inquiry said about itself is deliberately editable. These rows
 * arrive from forms filled in by hand and arrive misspelt; a register nobody
 * can correct becomes a register nobody trusts.
 */
class InquiryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([

            Section::make('Who they are')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(255),

                    TextInput::make('email')
                        ->email()
                        ->required()
                        ->maxLength(255)
                        // Not unique, on purpose: the same person enquiring
                        // twice is two pieces of evidence about two campaigns,
                        // and the register counts the repeats instead.
                        ->helperText('Lower-cased on save. The same address may appear more than once.'),

                    TextInput::make('company')->maxLength(255),

                    TextInput::make('job_title')
                        ->label('Job title')
                        ->maxLength(255),

                    TextInput::make('phone')
                        ->tel()
                        ->maxLength(40),

                    Select::make('industry_id')
                        ->label('Industry')
                        ->options(fn (): array => Industry::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()
                        ->placeholder('Not given')
                        ->helperText('What they train for — which webinars are worth putting in front of them.'),
                ]),

            Section::make('Where they came from')
                ->columns(2)
                ->schema([
                    Select::make('source')
                        ->options(InquirySource::options())
                        ->default(InquirySource::Manual->value)
                        ->required()
                        ->live(),

                    TextInput::make('source_detail')
                        ->label('Which one')
                        ->maxLength(255)
                        ->placeholder('Which quiz, guide or webinar')
                        // A label, never a key — see the migration. Said here
                        // too, because the next person to touch this screen
                        // will be the one tempted to put an id in it.
                        ->helperText('Free text. Not a link to a record.'),

                    Textarea::make('message')
                        ->label('What they said')
                        ->rows(3)
                        ->maxLength(5000)
                        ->columnSpanFull(),

                    Toggle::make('marketing_opt_in')
                        ->label('Agreed to be emailed')
                        ->helperText('Only ever true because they said so. An imported list did not agree to anything.')
                        ->disabled(fn (callable $get): bool => ! self::sourceIsSelfSubmitted($get('source')))
                        ->columnSpanFull(),
                ]),

            Section::make('Follow-up')
                ->description('Who owns this, and what has happened so far.')
                ->columns(3)
                ->schema([
                    Select::make('status')
                        ->options(InquiryStatus::options())
                        ->default(InquiryStatus::New->value)
                        ->required()
                        // No "converted": whether they bought is worked out
                        // from their orders, not remembered here.
                        ->helperText('Whether they bought is worked out from their account, not set here.'),

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

    /** The toggle is only meaningful where the person themselves filled a form in. */
    private static function sourceIsSelfSubmitted(mixed $source): bool
    {
        return is_string($source)
            && InquirySource::tryFrom($source)?->isSelfSubmitted() === true;
    }
}
