<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Memberships\CancelSubscription;
use App\Actions\Memberships\ChangePlan;
use App\Actions\Memberships\ResumeSubscription;
use App\Actions\Memberships\StartSubscription;
use App\Enums\CorePageKey;
use App\Enums\Format;
use App\Models\CorePage;
use App\Models\MembershipPlan;
use App\Models\Subscription;
use App\Models\User;
use App\Seo\Seo;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Laravel\Cashier\Exceptions\IncompletePayment;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Memberships — CLAUDE.md §6 ("Pricing page, subscribe, manage plan") / P4.
 *
 * §4 rule 2 keeps this thin: the public pricing list and the subscribe page are
 * reads over the live `membership_plans` (§5.5), and taking payment is the next
 * slice's Livewire component, not a fat controller action.
 */
class MembershipController extends Controller
{
    /** The pricing page: every active plan, with the allowances it grants. */
    public function index(): View
    {
        $corePage = CorePage::for(CorePageKey::Membership);

        return view('membership.index', [
            'seo' => $corePage ? Seo::for($corePage) : Seo::site('Membership Plans'),
            'corePage' => $corePage,
            'plans' => MembershipPlan::query()->active()->with('entitlements')->get(),
        ]);
    }

    /**
     * The subscribe page for one plan.
     *
     * A 404 for a plan that cannot be sold (inactive, or missing its Stripe
     * price) rather than a form that would fail the moment it is submitted —
     * `isSellable()` is the same gate the admin sees on the plan screen.
     *
     * Signing in is required before paying: a subscription belongs to an
     * account, and Cashier needs a billable user to attach the Stripe customer
     * to. A guest is sent to log in and bounced straight back here afterwards.
     */
    public function subscribe(MembershipPlan $plan): View|RedirectResponse
    {
        if (! $plan->is_active || ! $plan->isSellable()) {
            throw new NotFoundHttpException;
        }

        if (auth()->guest()) {
            return redirect()->guest(route('login'));
        }

        return view('membership.subscribe', [
            'seo' => Seo::site('Subscribe — '.$plan->name, robots: 'noindex, follow'),
            'plan' => $plan->load('entitlements'),
        ]);
    }

    /**
     * Start a SetupIntent so the browser can collect and confirm a card inline.
     *
     * A SetupIntent, not a PaymentIntent: the card is set up as a reusable
     * payment method (3DS handled here, at the card step), and Cashier charges
     * the first invoice against it when the subscription is created. Cashier
     * creates the Stripe customer on demand.
     */
    public function intent(MembershipPlan $plan): JsonResponse
    {
        $this->assertSubscribable($plan);

        if (! $this->stripeConfigured()) {
            return response()->json(['error' => 'Card payments are not configured yet.'], 422);
        }

        return response()->json([
            'clientSecret' => $this->member()->createSetupIntent()->client_secret,
        ]);
    }

    /**
     * Create the subscription from the confirmed payment method.
     *
     * The amount and the price are the server's: the browser sends only the
     * payment-method id, never a price (§14 #1). A first invoice that needs
     * further authentication comes back as IncompletePayment, which the browser
     * resolves and retries.
     */
    public function confirm(Request $request, MembershipPlan $plan): JsonResponse
    {
        $this->assertSubscribable($plan);

        $paymentMethod = (string) $request->input('payment_method');

        if ($paymentMethod === '') {
            return response()->json(['error' => 'No payment method was provided.'], 422);
        }

        try {
            app(StartSubscription::class)($this->member(), $plan, $paymentMethod);
        } catch (IncompletePayment $exception) {
            return response()->json([
                'requiresAction' => true,
                'paymentIntentClientSecret' => $exception->payment->clientSecret(),
            ], 202);
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }

        return response()->json(['redirect' => route('membership.manage')]);
    }

    /**
     * The member's own membership — plan, status, term and remaining allowance.
     *
     * Resolved by `user_id` from the account, never from the request, so there is
     * no id to tamper with (§14 #10). No membership yet means nothing to manage,
     * so back to the pricing page.
     */
    public function manage(): View|RedirectResponse
    {
        $subscription = $this->currentSubscription();

        if ($subscription === null) {
            return redirect()->route('membership.index');
        }

        $subscription->loadMissing('entitlements', 'plan');

        return view('membership.manage', [
            'seo' => Seo::site('Your membership', robots: 'noindex, nofollow'),
            'subscription' => $subscription,
            'formats' => Format::cases(),
            'otherPlans' => MembershipPlan::query()
                ->active()
                ->when($subscription->membership_plan_id !== null, fn ($query) => $query->whereKeyNot((int) $subscription->membership_plan_id))
                ->with('entitlements')
                ->get()
                ->filter(fn (MembershipPlan $plan): bool => $plan->isSellable() && $plan->billing_period->isRecurring())
                ->values(),
        ]);
    }

    public function cancel(CancelSubscription $cancel): RedirectResponse
    {
        return $this->runOnCurrent(fn (Subscription $s) => $cancel($s), 'Your membership will end at the close of this period.');
    }

    public function resume(ResumeSubscription $resume): RedirectResponse
    {
        return $this->runOnCurrent(fn (Subscription $s) => $resume($s), 'Your membership will continue — the cancellation has been undone.');
    }

    public function change(Request $request, ChangePlan $change): RedirectResponse
    {
        $target = MembershipPlan::query()->where('slug', (string) $request->input('plan'))->first();

        if ($target === null) {
            return redirect()->route('membership.manage')->with('membershipError', 'That plan was not recognised.');
        }

        return $this->runOnCurrent(fn (Subscription $s) => $change($s, $target), 'Your plan has been changed.');
    }

    /**
     * Run a management action on the signed-in member's own subscription.
     *
     * One place that resolves the subscription from the account and turns a
     * RuntimeException from the action into a flash, so cancel/resume/change
     * cannot act on anyone else's membership and all three report failure the
     * same way.
     *
     * @param  Closure(Subscription): mixed  $action
     */
    private function runOnCurrent(Closure $action, string $success): RedirectResponse
    {
        $subscription = $this->currentSubscription();

        if ($subscription === null) {
            return redirect()->route('membership.index');
        }

        try {
            $action($subscription);
        } catch (RuntimeException $exception) {
            return redirect()->route('membership.manage')->with('membershipError', $exception->getMessage());
        }

        return redirect()->route('membership.manage')->with('membershipStatus', $success);
    }

    /** The signed-in member's current `default` subscription, as our model, or null. */
    private function currentSubscription(): ?Subscription
    {
        return $this->member()->membership();
    }

    /** A 404 for a plan that cannot be sold, the same gate the admin screen shows. */
    private function assertSubscribable(MembershipPlan $plan): void
    {
        if (! $plan->is_active || ! $plan->isSellable()) {
            throw new NotFoundHttpException;
        }
    }

    /** The signed-in billable user. The route's `auth` middleware guarantees one. */
    private function member(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    private function stripeConfigured(): bool
    {
        return filled(config('services.stripe.secret') ?? config('cashier.secret'));
    }
}
