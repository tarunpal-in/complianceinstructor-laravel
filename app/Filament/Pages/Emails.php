<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Email\Dispatcher;
use App\Email\EmailCatalogue;
use App\Email\EmailDefinition;
use App\Email\EmailRow;
use App\Email\SendBudget;
use App\Enums\EmailCategory;
use App\Enums\EmailStatus;
use App\Models\EmailSend;
use App\Models\Setting;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;

/**
 * Every email this website can send, with a switch where there is one.
 *
 * Two jobs, and the second is worth as much as the first. It is where the client
 * turns an email off — and it is the only place that answers **"what does this
 * site email people?"**, which they will ask repeatedly and which nothing could
 * answer before.
 *
 * A page rather than a resource because the catalogue is code, not rows. Thirty-
 * four emails are declared in EmailCatalogue; the settings table only holds the
 * handful of on/off values, and modelling the list itself would mean a table that
 * has to be kept in step with the classes that send.
 *
 * **Required emails show a lock and the reason, not a disabled switch.** Hiding
 * them would leave the client thinking they could not see the full list; a
 * greyed-out switch invites somebody to go looking for a way to force it. The
 * reason is there because if they disagree it should be a conversation, not a wall
 * they discover in month three.
 */
class Emails extends Page
{
    /** The first tab of the Emails cluster, and where the cluster lands. */
    protected static ?string $cluster = \App\Filament\Clusters\Emails::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedListBullet;

    // First in the cluster's sub-navigation, so it is the tab the cluster opens on.
    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Overview';

    protected static ?string $title = 'Emails';

    // Under the cluster slug this is /admin/emails/overview. The cluster itself
    // owns /admin/emails and redirects here.
    protected static ?string $slug = 'overview';

    protected string $view = 'filament.pages.emails';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view_email_log') ?? false;
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Everything the system can send, what sets it off, and whether it is switched on.';
    }

    protected function getHeaderActions(): array
    {
        return [$this->haltAction(), $this->resumeAction()];
    }

    // The emails -----------------------------------------------------------------

    /**
     * The catalogue, grouped by kind, with a 30-day count beside each.
     *
     * The count is what makes the screen honest about itself: an email declared,
     * switched on and showing zero for thirty days is either not wired up or not
     * happening, and both are worth noticing from here.
     *
     * A plain array rather than a Collection: PHPStan's Collection template is
     * not covariant, so `filter` narrowing the value type to a non-empty array
     * puts the declared return type permanently out of step with the real one.
     *
     * @return array<string, array<int, EmailRow>>
     */
    public function rows(): array
    {
        $counts = EmailSend::query()
            ->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('email_key, count(*) as total, max(created_at) as last_sent')
            ->groupBy('email_key')
            ->get()
            ->keyBy('email_key');

        return collect(EmailCategory::cases())
            ->mapWithKeys(fn (EmailCategory $category): array => [
                $category->label() => array_map(
                    function (EmailDefinition $email) use ($counts): EmailRow {
                        $row = $counts->get($email->key);
                        $lastSent = $row->last_sent ?? null;

                        return new EmailRow(
                            email: $email,
                            sent: (int) ($row->total ?? 0),
                            lastSent: blank($lastSent) ? null : Carbon::parse((string) $lastSent),
                            enabled: $email->isEnabled(),
                            // Only a built, switchable email gets a switch. The
                            // rest show a lock or what blocks them.
                            switchable: $email->tier->isSwitchable() && $email->isBuilt(),
                        );
                    },
                    EmailCatalogue::inCategory($category),
                ),
            ])
            ->filter(fn (array $rows): bool => $rows !== [])
            ->all();
    }

    /**
     * Switch one email on or off.
     *
     * Refuses a required email rather than ignoring the request. A caller — or a
     * crafted Livewire call — that thinks it switched off order confirmations and
     * silently did not is worse than one that is told no.
     */
    public function toggle(string $key): void
    {
        abort_unless($this->mayManage(), 403);

        $email = EmailCatalogue::find($key);

        if ($email === null || ! $email->tier->isSwitchable() || ! $email->isBuilt()) {
            Notification::make()->danger()
                ->title('That email cannot be switched off')
                ->body($email === null ? 'It is not an email this system sends.' : ($email->lockReason ?? 'It has no switch.'))
                ->send();

            return;
        }

        $now = ! $email->isEnabled();

        // Through Setting::set, so the change lands in `activity_log` with the
        // actor. Switching off membership renewal notices has revenue
        // consequences and until now nothing recorded who decided that.
        Setting::set($email->settingKey(), $now, 'boolean');

        Notification::make()->success()
            ->title($email->name.($now ? ' switched on' : ' switched off'))
            ->body($now ? null : 'Nothing will be sent, and each one it would have sent is recorded in the email log.')
            ->send();
    }

    // Allowance -----------------------------------------------------------------

    /**
     * Today's sending budget.
     *
     * On this screen because this is where somebody stands before scheduling
     * something — and because a consumer Gmail account answers an over-run by
     * blocking the whole mailbox for a day, password resets included. Nothing
     * else in the application would tell them.
     *
     * @return array<string, int|bool|string|null>
     */
    public function budget(): array
    {
        return [
            // Whether the allowance applies at all. False on a real SMTP provider,
            // where there is no per-account daily cliff to manage.
            'capped' => SendBudget::capped(),
            'host' => SendBudget::host(),
            'used' => SendBudget::used(),
            'dailyCap' => SendBudget::dailyCap(),
            'bulkCap' => SendBudget::bulkCap(),
            'percent' => SendBudget::percentUsed(),
            'remainingTransactional' => SendBudget::remainingForTransactional(),
            'remainingBulk' => SendBudget::remainingForBulk(),
            'deferred' => SendBudget::deferredCount(),
            'nearLimit' => SendBudget::isNearLimit(),
            'halted' => Dispatcher::isHalted(),
            'failed' => EmailSend::query()->where('status', EmailStatus::Failed)
                ->where('created_at', '>=', now()->subDays(7))->count(),
        ];
    }

    // The kill switch -----------------------------------------------------------------

    /**
     * Stop everything.
     *
     * For the day a template goes out wrong. Two hours of work against the
     * alternative, which is a deploy under pressure while a mistake keeps sending.
     * Nothing is lost: every send is recorded as not sent, with the reason.
     */
    private function haltAction(): Action
    {
        return Action::make('halt')
            ->label('Stop all sending')
            ->icon(Heroicon::OutlinedExclamationTriangle)
            ->color('danger')
            ->visible(fn (): bool => $this->mayManage() && ! Dispatcher::isHalted())
            ->requiresConfirmation()
            ->modalHeading('Stop all outgoing email?')
            ->modalDescription('Nothing will be sent — including password resets and receipts — until this is switched back on. Everything that would have gone out is recorded in the email log with the reason, so nothing is lost.')
            ->modalSubmitActionLabel('Stop sending')
            ->action(function (): void {
                Setting::set('email.sending_halted', true, 'boolean');

                Notification::make()->warning()
                    ->title('Sending stopped')
                    ->body('Remember to switch it back on.')
                    ->persistent()
                    ->send();
            });
    }

    private function resumeAction(): Action
    {
        return Action::make('resume')
            ->label('Resume sending')
            ->icon(Heroicon::OutlinedPlay)
            ->color('success')
            ->visible(fn (): bool => $this->mayManage() && Dispatcher::isHalted())
            ->requiresConfirmation()
            ->modalHeading('Start sending again?')
            ->modalDescription('Emails held back while sending was stopped are not sent retroactively — they stay in the log as not sent. Resend any that still matter from there.')
            ->action(function (): void {
                Setting::set('email.sending_halted', false, 'boolean');

                Notification::make()->success()->title('Sending resumed')->send();
            });
    }

    public function mayManage(): bool
    {
        return auth()->user()?->can('manage_email_sending') ?? false;
    }
}
