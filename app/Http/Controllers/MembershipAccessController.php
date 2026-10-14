<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Memberships\RedeemMembershipAllowance;
use App\Enums\Format;
use App\Models\Offering;
use App\Models\User;
use App\Models\Webinar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Spends a membership allowance to unlock a webinar — CLAUDE.md §5.2 / §6 / P5.
 *
 * §4 rule 2: the controller resolves the member and the format, refuses what it
 * must, and hands the one write to the tested RedeemMembershipAllowance (which
 * checks state, inclusion and quota under a lock, and grants exactly one ledger
 * row). The subscription is resolved from the account, never the request, so a
 * member can only ever spend their own allowance.
 */
class MembershipAccessController extends Controller
{
    public function watch(Request $request, Webinar $webinar, RedeemMembershipAllowance $redeem): RedirectResponse
    {
        $format = Format::tryFrom((string) $request->input('format'));

        if ($format === null) {
            return back()->with('membershipError', 'That format was not recognised.');
        }

        /** @var User $user */
        $user = auth()->user();
        $subscription = $user->membership();

        if ($subscription === null) {
            return redirect()->route('membership.index');
        }

        // The webinar must actually be sold in this format — otherwise there is
        // nothing to grant access to, however much allowance the plan holds.
        if (! $this->webinarOffers($webinar, $format)) {
            return back()->with('membershipError', 'This webinar is not available in that format.');
        }

        try {
            $redeem($subscription, $webinar, $format);
        } catch (RuntimeException $exception) {
            return back()->with('membershipError', $exception->getMessage());
        }

        return back()->with('membershipStatus', 'Added to your account — watch it any time from My Learning.');
    }

    private function webinarOffers(Webinar $webinar, Format $format): bool
    {
        return $webinar->offerings()->get()->contains(
            fn (Offering $offering): bool => $offering->formatEnums()->contains($format),
        );
    }
}
