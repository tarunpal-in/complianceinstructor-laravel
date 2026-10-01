<?php

declare(strict_types=1);

namespace App\Actions\Subscribers;

use App\Email\Dispatcher;
use App\Enums\SubscriberSource;
use App\Enums\SubscriberStatus;
use App\Mail\ConfirmMailingList;
use App\Models\Subscriber;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Somebody joining the mailing list — §4 rule 2.
 *
 * Every route in goes through here: the footer box, the checkout tickbox, an
 * import, a member of staff typing one in. That matters more than usual on this
 * table, because the rules below are the difference between a mailing list and
 * a liability, and they are the kind of rule a second call site gets subtly
 * wrong — §14 #9 in a corner that ends with a sending domain blocked.
 *
 * The rules:
 *
 * - Signing up twice is not two rows. The email is UNIQUE and this updates.
 * - Somebody who unsubscribed and signed up again has changed their mind, and
 *   refusing that would be obstructive. They go back on.
 * - Somebody who reported us as spam does **not** go back on from a form. See
 *   SubscriberStatus::mayResubscribeThemselves.
 * - Consent is stamped from the request, never passed in as a boolean by a
 *   caller who might be guessing.
 */
class Subscribe
{
    public function __invoke(
        string $email,
        SubscriberSource $source,
        ?string $name = null,
        ?Request $request = null,
        bool $asStaff = false,
    ): Subscriber {
        $email = Str::lower(trim($email));

        if ($email === '') {
            throw new RuntimeException('An email address is needed.');
        }

        $existing = Subscriber::query()->where('email', $email)->first();

        if ($existing !== null) {
            return $this->askToConfirm($this->resubscribe($existing, $name, $request, $asStaff));
        }

        return $this->askToConfirm(Subscriber::create([
            'email' => $email,
            'name' => $this->clean($name),
            'source' => $source,
            ...$this->consent($request),
            ...$this->startingState(),
        ]));
    }

    /**
     * Send the confirmation link, where one is being waited for.
     *
     * Driven by the resulting status rather than by the setting, which is the
     * distinction that matters: somebody already on the list who signs up again
     * comes back `Subscribed` and must not be asked to confirm something they
     * confirmed last year.
     *
     * Until this existed, `mailing_list.double_opt_in` could be switched on and
     * every signup would sit at `Pending` forever waiting for an email nothing
     * could send. The setting's own help text said so.
     */
    private function askToConfirm(Subscriber $subscriber): Subscriber
    {
        if ($subscriber->status === SubscriberStatus::Pending) {
            app(Dispatcher::class)->send(new ConfirmMailingList($subscriber));
        }

        return $subscriber;
    }

    /**
     * An address that is already known.
     *
     * Returned rather than refused even when nothing changes, so the footer box
     * can say "thanks" without telling a stranger whether an address is already
     * on the list — which would turn the signup form into a way of testing
     * whether somebody is a customer here.
     */
    private function resubscribe(
        Subscriber $subscriber,
        ?string $name,
        ?Request $request,
        bool $asStaff,
    ): Subscriber {
        // A spam complaint is not overturned by somebody filling in a form.
        // Staff can, deliberately, and that decision lands in the activity log.
        if (! $asStaff && ! $subscriber->status->mayResubscribeThemselves()) {
            return $subscriber;
        }

        if ($subscriber->isMailable()) {
            // Already on the list. Fill in a name if this signup carried one
            // and the row did not, and otherwise leave the consent alone: the
            // original stamp is the one that was actually given.
            if ($this->clean($name) !== null && blank($subscriber->name)) {
                $subscriber->forceFill(['name' => $this->clean($name)])->save();
            }

            return $subscriber;
        }

        $subscriber->forceFill([
            'name' => $this->clean($name) ?? $subscriber->name,
            // A fresh consent, because this is a fresh act of consenting.
            ...$this->consent($request),
            ...$this->startingState(),
            'unsubscribed_at' => null,
            'unsubscribe_reason' => null,
            'bounced_at' => null,
            'bounce_reason' => null,
        ])->save();

        return $subscriber;
    }

    /**
     * Subscribed, or waiting to confirm.
     *
     * @return array<string, mixed>
     */
    private function startingState(): array
    {
        if (Subscriber::requiresDoubleOptIn()) {
            return [
                'status' => SubscriberStatus::Pending,
                'subscribed_at' => now(),
                'confirmed_at' => null,
            ];
        }

        return [
            'status' => SubscriberStatus::Subscribed,
            'subscribed_at' => now(),
            'confirmed_at' => null,
        ];
    }

    /**
     * What we could see of them when they agreed.
     *
     * @return array<string, string|null>
     */
    private function consent(?Request $request): array
    {
        if ($request === null) {
            return [];
        }

        return [
            'consent_ip' => $request->ip(),
            // Truncated to the column: a crafted header must not be able to
            // fail the insert and take the signup box down with it.
            'consent_user_agent' => $this->clean($request->userAgent(), 250),
        ];
    }

    private function clean(?string $value, int $limit = 255): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : Str::limit($value, $limit, '');
    }
}
