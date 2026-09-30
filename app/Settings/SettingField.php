<?php

declare(strict_types=1);

namespace App\Settings;

use DateTimeZone;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;

/**
 * Draws the plain settings.
 *
 * Only the groups whose fields are genuinely plain go through here — a name, a
 * number of hours, a paragraph of copy, a toggle. The order-number section
 * validates three fields against each other and previews the result; the promo
 * bar hides most of itself behind a switch; brand images pick from the media
 * library and record what uses them. Those stay hand-written, because a
 * generator that could express them would be a worse Filament.
 *
 * The split is deliberate and worth keeping: metadata draws what is boring, and
 * anything interesting is written out in full where it can be read.
 */
final class SettingField
{
    public static function for(SettingDefinition $setting, bool $editable): Component
    {
        return self::control($setting)
            ->label($setting->label)
            ->helperText($setting->help)
            // Disabled rather than hidden: somebody who cannot change the
            // refund window should still be able to see what it is, and a
            // missing field leaves them unable to tell whether the value is
            // absent or whether they simply may not see it.
            ->disabled(! $editable)
            // Filament drops disabled fields from the submitted state. The save
            // skips groups the user cannot edit anyway, and this keeps the two
            // agreeing rather than relying on one of them.
            ->dehydrated($editable);
    }

    private static function control(SettingDefinition $setting): TextInput|Textarea|Toggle|Select
    {
        return match ($setting->input) {
            'toggle' => Toggle::make($setting->field()),

            'textarea' => Textarea::make($setting->field())
                ->rows(3)
                ->maxLength(2000)
                ->columnSpanFull(),

            'number' => TextInput::make($setting->field())
                ->numeric()
                ->minValue($setting->range[0] ?? 0)
                ->maxValue($setting->range[1] ?? PHP_INT_MAX),

            'url' => TextInput::make($setting->field())
                ->url()
                ->maxLength($setting->maxLength ?? 255)
                ->prefixIcon('heroicon-o-link'),

            'email' => TextInput::make($setting->field())
                ->email()
                ->maxLength($setting->maxLength ?? 255),

            'tel' => TextInput::make($setting->field())
                ->tel()
                ->maxLength($setting->maxLength ?? 40),

            // A searchable list rather than free text: a mistyped zone falls
            // back silently and every daily figure quietly shifts, which is
            // the worst way for a setting to be wrong.
            'timezone' => Select::make($setting->field())
                ->options(self::timezones())
                ->searchable()
                ->required(),

            default => TextInput::make($setting->field())
                ->maxLength($setting->maxLength ?? 255),
        };
    }

    /**
     * US zones first, then everywhere else.
     *
     * §1 has the audience as US professionals, so the useful answer is four
     * entries down a list of several hundred.
     *
     * @return array<string, string>
     */
    private static function timezones(): array
    {
        $american = DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, 'US');
        $everywhere = array_diff(DateTimeZone::listIdentifiers(), $american);

        $options = [];

        foreach ([...$american, ...$everywhere] as $zone) {
            $options[$zone] = str_replace('_', ' ', $zone);
        }

        return $options;
    }
}
