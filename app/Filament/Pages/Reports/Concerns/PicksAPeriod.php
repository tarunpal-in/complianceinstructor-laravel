<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports\Concerns;

use App\Support\Period;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;

/**
 * The date range every report opens with.
 *
 * Four reports wanted the same three fields and the same fallback behaviour, so
 * they live here. The alternative — a copy per page — is how two reports end up
 * disagreeing about what "this month" means, which is the sort of difference
 * nobody notices until two people quote different numbers in one meeting.
 */
trait PicksAPeriod
{
    /** @var array<string, mixed> */
    public array $data = [];

    public function mount(): void
    {
        // getForm() rather than $this->form: the property is magic on
        // InteractsWithForms and invisible to static analysis.
        $this->getForm('form')?->fill(['preset' => $this->defaultPreset(), 'from' => null, 'to' => null]);
    }

    /**
     * The picker itself.
     *
     * Not called `form()`: InteractsWithForms defines that, and a trait cannot
     * quietly win a collision with it. Each page has a one-line `form()` that
     * calls this, which also leaves somewhere obvious to add a field that only
     * one report needs.
     */
    public function periodForm(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Grid::make(3)->schema([
                    Select::make('preset')
                        ->label('Period')
                        ->options([...Period::options(), 'custom' => 'Custom range'])
                        ->default($this->defaultPreset())
                        ->selectablePlaceholder(false)
                        ->live(),

                    DatePicker::make('from')
                        ->label('From')
                        ->visible(fn (callable $get): bool => $get('preset') === 'custom')
                        ->live(),

                    DatePicker::make('to')
                        ->label('To')
                        ->visible(fn (callable $get): bool => $get('preset') === 'custom')
                        ->live(),
                ]),
            ]);
    }

    public function getPeriod(): Period
    {
        $preset = $this->data['preset'] ?? $this->defaultPreset();

        if ($preset !== 'custom') {
            return Period::preset(is_string($preset) ? $preset : $this->defaultPreset());
        }

        $from = $this->data['from'] ?? null;
        $to = $this->data['to'] ?? null;

        // An incomplete custom range falls back rather than erroring: both
        // fields are empty the moment "Custom" is chosen, and a red page
        // between choosing it and filling it in would be the screen's fault.
        if (! is_string($from) || ! is_string($to) || $from === '' || $to === '') {
            return Period::preset($this->defaultPreset());
        }

        return Period::between($from, $to);
    }

    /**
     * Which range a report opens on.
     *
     * Overridden where the useful default differs: sales is read month to date,
     * an accreditation submission is usually made for the month just gone.
     */
    protected function defaultPreset(): string
    {
        return 'mtd';
    }

    public function money(int $cents): string
    {
        return \App\Support\Money::format($cents);
    }
}
