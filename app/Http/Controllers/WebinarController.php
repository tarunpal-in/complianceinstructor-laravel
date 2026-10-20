<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Format;
use App\Models\Entitlement;
use App\Models\Setting;
use App\Models\Webinar;
use App\Seo\Schema;
use App\Seo\Seo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The public webinar detail page — design-ui/Webinar Page.pdf.
 *
 * §4 rule 2, "Controllers route; Actions do": this resolves the record, refuses
 * the ones that must not be public, and hands the view what it needs. No price
 * is computed here — §5.3 makes `offerings` the only place a price is read
 * from, and the panel renders those rows directly.
 */
class WebinarController extends Controller
{
    public function __invoke(Webinar $webinar): View
    {
        // A draft or archived webinar has a slug and would otherwise resolve.
        // 404 rather than 403: the existence of an unreleased session is itself
        // not public information.
        if (! $webinar->isPubliclyVisible()) {
            throw new NotFoundHttpException;
        }

        // Offerings are loaded whole and filtered in the panel: which ones are
        // on sale is a display decision, and constraining the eager load would
        // leave the collection quietly incomplete for anything else that reads
        // it from the same instance.
        $webinar->load([
            'speaker.media',
            'industry',
            'accreditations',
            'offerings.formats',
            'cardMedia',
            'bannerMedia',
        ]);

        $crumbs = [
            ['name' => 'Home', 'url' => url('/')],
            ['name' => $webinar->title, 'url' => route('webinars.show', $webinar)],
        ];

        return view('webinars.show', [
            'seo' => Seo::for($webinar),
            'breadcrumbs' => $crumbs,
            'schemas' => [Schema::event($webinar), Schema::breadcrumbs($crumbs)],
            'webinar' => $webinar,
            'membership' => $this->membershipAccess($webinar),
            'similar' => $this->similar($webinar),
            'instructions' => $webinar->resolvedInstructions(),
            'qaNote' => Setting::get(
                'webinars.qa_note',
                'During the Q&A session following the live event, ask a question and get a direct response from our expert speaker.',
            ),
        ]);
    }

    /**
     * How the signed-in member can reach this webinar on their plan — §5.2 / P5.
     *
     * Null for a guest or a non-member, so the panel renders nothing. Otherwise,
     * for each format the webinar is sold in *and* the plan includes, one of
     * three states: already `held` (a grant exists — show an open link),
     * `spendable` (an allowance is free this window — offer to redeem), or
     * `exhausted` (included, but spent). The redeem itself is the tested
     * RedeemMembershipAllowance, reached from the panel.
     *
     * @return array{subscription: \App\Models\Subscription, formats: list<array{format: Format, state: string, remaining: string}>}|null
     */
    private function membershipAccess(Webinar $webinar): ?array
    {
        $user = auth()->user();

        if ($user === null) {
            return null;
        }

        $subscription = $user->membership();

        if ($subscription === null || ! $subscription->state()->grantsAccess()) {
            return null;
        }

        $subscription->loadMissing('entitlements', 'plan');

        /** @var list<array{format: Format, state: string, remaining: string}> $rows */
        $rows = [];

        foreach ($this->offeredFormats($webinar) as $format) {
            // Only formats the plan actually grants — otherwise there is nothing
            // to offer and nothing to redeem.
            if ($subscription->allowanceFor($format) === null) {
                continue;
            }

            $state = match (true) {
                Entitlement::grants($user, $webinar, $format) => 'held',
                $subscription->canSpend($format) => 'spendable',
                default => 'exhausted',
            };

            $rows[] = [
                'format' => $format,
                'state' => $state,
                'remaining' => $subscription->describeRemaining($format),
            ];
        }

        return $rows === [] ? null : ['subscription' => $subscription, 'formats' => $rows];
    }

    /**
     * The formats this webinar is sold in, de-duplicated.
     *
     * @return list<Format>
     */
    private function offeredFormats(Webinar $webinar): array
    {
        $seen = [];
        $formats = [];

        foreach ($webinar->offerings as $offering) {
            foreach ($offering->formatEnums() as $format) {
                if (! isset($seen[$format->value])) {
                    $seen[$format->value] = true;
                    $formats[] = $format;
                }
            }
        }

        return $formats;
    }

    /**
     * The rail beside the content — four more in the same industry.
     *
     * Falls back to the rest of the catalogue when an industry is thin, because
     * an empty rail on a page whose layout reserves a third of the width for it
     * looks broken rather than restrained.
     *
     * @return Collection<int, Webinar>
     */
    private function similar(Webinar $webinar): Collection
    {
        $limit = 4;

        $base = fn (): Builder => Webinar::query()
            ->public()
            ->whereKeyNot($webinar->getKey())
            ->with(['speaker.media', 'accreditations', 'cardMedia', 'bannerMedia']);

        // 1. Upcoming sessions in the same industry, soonest first — the most
        //    relevant "similar" there is. An Eloquent collection rather than
        //    collect(): the top-ups below call modelKeys().
        $picks = $webinar->industry_id === null
            ? new Collection
            : $base()->where('industry_id', $webinar->industry_id)->upcoming()->limit($limit)->get();

        // 2. Top up with other upcoming sessions, so the rail stays current and
        //    does not repeat the same four on every page.
        if ($picks->count() < $limit) {
            $picks = $picks->merge(
                $base()->whereKeyNot($picks->modelKeys())->upcoming()->limit($limit - $picks->count())->get()
            );
        }

        // 3. Still short — a young catalogue with few upcoming sessions — so fall
        //    back to the most recent so the column is never empty.
        if ($picks->count() < $limit) {
            $picks = $picks->merge(
                $base()->whereKeyNot($picks->modelKeys())->latest('scheduled_at')->limit($limit - $picks->count())->get()
            );
        }

        return $picks;
    }
}
