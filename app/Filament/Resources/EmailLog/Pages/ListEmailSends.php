<?php

declare(strict_types=1);

namespace App\Filament\Resources\EmailLog\Pages;

use App\Email\SendBudget;
use App\Enums\EmailStatus;
use App\Filament\Resources\EmailLog\EmailSendResource;
use App\Models\EmailSend;
use App\Support\Csv;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListEmailSends extends ListRecords
{
    protected static string $resource = EmailSendResource::class;

    protected function getHeaderActions(): array
    {
        return [$this->exportAction()];
    }

    /**
     * What is actually on fire, first.
     *
     * Failures get their own tab rather than a filter, because nobody opens this
     * screen to admire the successful sends. "Not sent" is separate from "failed"
     * on purpose: one is a decision the system made and can explain, the other is
     * a mail server refusing, and treating them alike would bury the second in
     * the first.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make('Everything'),

            'failed' => Tab::make('Failed')
                ->badge(fn (): int => EmailSend::query()->where('status', EmailStatus::Failed)->count())
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', EmailStatus::Failed)),

            'waiting' => Tab::make('Waiting for allowance')
                // Null rather than 0, so the tab carries no badge when nothing is
                // waiting. A grey zero reads as a problem that has been measured.
                ->badge(fn (): ?int => SendBudget::deferredCount() ?: null)
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', EmailStatus::Deferred)),

            'not_sent' => Tab::make('Not sent')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('status', [
                    EmailStatus::Suppressed,
                    EmailStatus::Cancelled,
                ])),

            'resent' => Tab::make('Resent')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereNotNull('resent_from_id')),
        ];
    }

    /**
     * The log as a CSV.
     *
     * Gated on `export_data` on top of `view_email_log`, the same split every
     * other export on this panel uses: reading the log and walking away with a
     * file of who was emailed what are different privileges.
     *
     * The body is deliberately absent from the export. It is the most personal
     * data in the system — names, addresses, order contents, reset links — and a
     * spreadsheet of it is a liability nobody asked for. The screen shows it one
     * row at a time, behind a permission.
     */
    private function exportAction(): Action
    {
        return Action::make('export')
            ->label('Export CSV')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->visible(fn (): bool => auth()->user()?->can('export_data') ?? false)
            ->action(fn (): StreamedResponse => $this->export());
    }

    private function export(): StreamedResponse
    {
        $query = $this->getFilteredTableQuery();

        return Csv::download(
            Csv::filename('email-log'),
            ['When', 'To', 'Email', 'Subject', 'Status', 'Why', 'Tries', 'Message ID', 'Resent from'],
            function () use ($query): iterable {
                // lazy() rather than cursor(): cursor cannot eager load, and
                // `related` is a morph read per row.
                foreach ($query->with(['related'])->lazy(200) as $send) {
                    /** @var EmailSend $send */
                    yield [
                        $send->created_at?->format('Y-m-d H:i:s'),
                        $send->to_email,
                        $send->name(),
                        $send->subject,
                        $send->status->label(),
                        $send->outcome_note ?? $send->error,
                        $send->attempts,
                        $send->message_id,
                        $send->resent_from_id,
                    ];
                }
            },
        );
    }
}
