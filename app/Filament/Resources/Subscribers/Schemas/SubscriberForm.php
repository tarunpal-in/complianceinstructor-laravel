<?php

declare(strict_types=1);

namespace App\Filament\Resources\Subscribers\Schemas;

use App\Enums\SubscriberSource;
use App\Models\Subscriber;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Adding an address by hand.
 *
 * Three fields, and no status: where a manually added address lands is
 * Subscribe's decision, not something typed into a form. There is no edit page
 * for the same reason — see SubscriberResource::canEdit.
 *
 * The source defaults to "Added by staff" rather than to the footer, because a
 * row claiming somebody signed up on the website when a colleague typed them in
 * is exactly the kind of consent record this table exists to keep honest.
 */
class SubscriberForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([

            Section::make()
                ->columns(2)
                ->schema([
                    TextInput::make('email')
                        ->email()
                        ->required()
                        ->maxLength(255)
                        // Unique, unlike `inquiries` — a second row means two
                        // copies of every campaign in one inbox. Signing up
                        // again updates the row instead.
                        ->unique(Subscriber::class, ignoreRecord: true)
                        ->helperText('Already on the list? Use "Put back on" from the list rather than adding them again.'),

                    TextInput::make('name')
                        ->maxLength(255)
                        ->helperText('Optional. Most signups will not have one.'),

                    Select::make('source')
                        ->label('How they agreed')
                        ->options(SubscriberSource::options())
                        ->default(SubscriberSource::Manual->value)
                        ->required()
                        ->helperText('Say where the consent actually came from. This is the record if it is ever questioned.')
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
