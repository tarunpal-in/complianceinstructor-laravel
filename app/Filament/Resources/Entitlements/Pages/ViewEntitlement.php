<?php

declare(strict_types=1);

namespace App\Filament\Resources\Entitlements\Pages;

use App\Actions\Entitlements\RevokeEntitlement;
use App\Filament\Resources\Entitlements\EntitlementResource;
use App\Models\Entitlement;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Filament\Support\Icons\Heroicon as Icon;
use Throwable;

class ViewEntitlement extends ViewRecord
{
    protected static string $resource = EntitlementResource::class;

    public function getTitle(): string
    {
        /** @var Entitlement $record */
        $record = $this->getRecord();

        return ($record->user->name ?? 'Deleted account').' · '.$record->format->label();
    }

    protected function getHeaderActions(): array
    {
        return [$this->giveBackAction()];
    }

    /**
     * The same act as "Give back" on a membership, reachable from the register.
     *
     * Support arrives here as often as they arrive from a subscription — from a
     * customer's name, or from a webinar — and having to navigate to the
     * membership first to undo something is the kind of friction that ends with
     * somebody editing a row directly instead.
     *
     * Where the grant came from a membership, revoking also returns the
     * allowance, because the quota counts these rows rather than storing a
     * number.
     */
    private function giveBackAction(): Action
    {
        return Action::make('giveBack')
            ->label('Give back')
            ->icon(Icon::OutlinedArrowUturnLeft)
            ->color('warning')
            ->visible(fn (Entitlement $record): bool => ! $record->isRevoked()
                && (auth()->user()?->can('revoke_entitlement') ?? false))
            ->modalHeading('Take this access back?')
            ->modalDescription(fn (Entitlement $record): string => $record->source_type->consumesAllowance()
                ? 'Their access stops, and the allowance it spent returns to them.'
                : 'Their access stops. Nothing was spent on this, so there is no allowance to return.')
            ->schema([
                Textarea::make('reason')
                    ->label('Why')
                    ->required()
                    ->rows(2)
                    ->maxLength(255)
                    ->placeholder('Could not attend; sent the recording instead')
                    ->helperText('Kept on the record, and shown in the History below.'),
            ])
            ->action(function (Entitlement $record, array $data): void {
                try {
                    app(RevokeEntitlement::class)($record, (string) $data['reason']);
                } catch (Throwable $exception) {
                    Notification::make()->danger()->title('Not given back')->body($exception->getMessage())->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title('Access given back')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->send();

                $this->refreshFormData([]);
            });
    }
}
