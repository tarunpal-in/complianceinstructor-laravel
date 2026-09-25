<?php

declare(strict_types=1);

namespace App\Filament\Resources\Subscribers\Pages;

use App\Filament\Resources\Subscribers\SubscriberResource;
use App\Models\Subscriber;
use App\Support\SubscriberCsv;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListSubscribers extends ListRecords
{
    protected static string $resource = SubscriberResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->exportAction(),
            CreateAction::make()->label('Add an address'),
        ];
    }

    /**
     * The list, then the exceptions.
     *
     * "On the list" leads and is the default, because it is the only tab whose
     * count means anything to the business — it is the size of the audience.
     * The badge is a size here rather than a queue of work, which is why this
     * resource has no sidebar badge at all.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'subscribed' => Tab::make('On the list')
                ->modifyQueryUsing(self::onlyMailable(...))
                ->badge(fn (): int => Subscriber::query()->mailable()->count())
                ->badgeColor('success'),

            'pending' => Tab::make('Waiting to confirm')
                ->modifyQueryUsing(self::onlyAwaitingConfirmation(...))
                ->badge(fn (): int => Subscriber::query()->awaitingConfirmation()->count())
                ->badgeColor('warning'),

            'unsubscribed' => Tab::make('Unsubscribed')
                ->modifyQueryUsing(self::onlyUnsubscribed(...)),

            /*
             * Bounces and spam complaints together, apart from ordinary
             * unsubscribes: those are somebody's choice, these are a
             * deliverability problem and the only ones worth chasing.
             */
            'problems' => Tab::make('Problems')
                ->modifyQueryUsing(self::onlyProblems(...))
                ->badge(fn (): int => Subscriber::query()->problems()->count())
                ->badgeColor('danger'),

            'all' => Tab::make('Everyone'),
        ];
    }

    public function getDefaultActiveTab(): string
    {
        return 'subscribed';
    }

    /**
     * The list as a CSV, for whatever actually sends the mail.
     *
     * Gated on `export_data` rather than on read access, because looking at one
     * address and walking away with the whole list are different privileges —
     * which is exactly why §8 named that permission separately.
     *
     * It exports the tab and filters on screen. That matters more here than on
     * other screens: the default tab is the mailable list, so the obvious use
     * of this button produces a file that is safe to hand to a mailing tool,
     * and an export containing unsubscribes takes a deliberate change of tab.
     */
    private function exportAction(): Action
    {
        return Action::make('export')
            ->label('Export CSV')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->visible(fn (): bool => auth()->user()?->can('export_data') ?? false)
            ->action(fn (): StreamedResponse => SubscriberCsv::download($this->getFilteredTableQuery()));
    }

    /**
     * Named rather than inline: a tab is handed an untyped builder, where a
     * model scope is invisible to static analysis.
     *
     * @param  Builder<Subscriber>  $query
     */
    private static function onlyMailable(Builder $query): void
    {
        $query->mailable();
    }

    /** @param  Builder<Subscriber>  $query */
    private static function onlyAwaitingConfirmation(Builder $query): void
    {
        $query->awaitingConfirmation();
    }

    /** @param  Builder<Subscriber>  $query */
    private static function onlyUnsubscribed(Builder $query): void
    {
        $query->where('status', \App\Enums\SubscriberStatus::Unsubscribed);
    }

    /** @param  Builder<Subscriber>  $query */
    private static function onlyProblems(Builder $query): void
    {
        $query->problems();
    }
}
