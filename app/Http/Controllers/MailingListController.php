<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Subscribers\ConfirmSubscription;
use App\Actions\Subscribers\Subscribe;
use App\Actions\Subscribers\Unsubscribe;
use App\Email\Dispatcher;
use App\Enums\SubscriberSource;
use App\Http\Requests\StoreSubscriberRequest;
use App\Mail\UnsubscribeLink;
use App\Models\Subscriber;
use App\Seo\Seo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * The mailing list from a visitor's side: joining it, confirming, and leaving.
 *
 * §4 rule 2 — this routes, the actions decide. Named for the mailing list
 * rather than for "subscriptions", because `Subscription` in this codebase is
 * somebody paying for a membership and the two must not be confused in a
 * controller name.
 */
class MailingListController extends Controller
{
    /** The signup box in the site footer. */
    public function store(StoreSubscriberRequest $request, Subscribe $subscribe): RedirectResponse
    {
        $subscriber = $subscribe(
            email: (string) $request->validated('email'),
            source: SubscriberSource::Footer,
            name: $request->validated('name'),
            request: $request,
        );

        /*
         * The same answer whatever happened — a new signup, an address already
         * on the list, or one held back by a spam complaint. Saying "you are
         * already subscribed" would turn the footer of every page into a way of
         * testing whether a given person is on this client's list.
         */
        return back()->with('mailing_list_status', $subscriber->isAwaitingConfirmation()
            ? 'Almost there — check your inbox for a confirmation link.'
            : 'Thanks. You are on the list.');
    }

    /**
     * The footer "Unsubscribe" button.
     *
     * Emails the address its own signed opt-out link rather than removing it on
     * sight — mirroring the double opt-in on the way in. Typing a stranger's
     * address therefore cannot take them off the list, and the answer is the
     * same whether or not the address is on it, so the box never reveals who is
     * subscribed (the enumeration guard that store() carries too).
     */
    public function requestUnsubscribe(StoreSubscriberRequest $request, Dispatcher $dispatcher): RedirectResponse
    {
        $email = Str::lower(trim((string) $request->validated('email')));
        $subscriber = Subscriber::query()->where('email', $email)->first();

        if ($subscriber !== null && $subscriber->isMailable()) {
            $dispatcher->send(new UnsubscribeLink($subscriber));
        }

        return back()->with('mailing_list_status', 'If that address is on our list, we have emailed you a link to confirm unsubscribing.');
    }

    /** The double opt-in link. */
    public function confirm(Subscriber $subscriber, ConfirmSubscription $confirm): View
    {
        $confirm($subscriber);

        return view('mailing-list.confirmed', ['seo' => Seo::site('Subscribed', robots: 'noindex, follow'), 'subscriber' => $subscriber]);
    }

    /**
     * The link in the footer of every campaign.
     *
     * A page with a button rather than unsubscribing on sight. Mail scanners
     * and link-preview services fetch every URL in an email, and a GET that
     * acts would take people off the list who never clicked anything. One
     * click on this page is still one click.
     */
    public function confirmUnsubscribe(Subscriber $subscriber): View
    {
        return view('mailing-list.unsubscribe', ['seo' => Seo::site('Unsubscribe', robots: 'noindex, follow'), 'subscriber' => $subscriber]);
    }

    public function unsubscribe(Subscriber $subscriber, Unsubscribe $unsubscribe): View
    {
        $unsubscribe($subscriber, 'Used the link in an email.');

        return view('mailing-list.unsubscribed', ['seo' => Seo::site('Unsubscribed', robots: 'noindex, follow'), 'subscriber' => $subscriber]);
    }
}
