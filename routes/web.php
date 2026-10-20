<?php

declare(strict_types=1);

use App\Http\Controllers\BlogController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\MailingListController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\PagePreviewController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\PostPreviewController;
use App\Http\Controllers\SpeakerController;
use App\Http\Controllers\WebinarController;
use App\Models\Post;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

/*
 * Crawl directives — client SEO brief §5/§6. Both have a file extension, so the
 * trailing-slash and redirect middleware leave them alone, and both sit above
 * the page catch-all so a page can never shadow them.
 */
Route::get('/robots.txt', App\Http\Controllers\RobotsController::class)->name('robots');
Route::get('/sitemap.xml', App\Http\Controllers\SitemapController::class)->name('sitemap');

/*
 * The comp's breadcrumb reads Home › Live Webinars › Human Resources › Title,
 * which suggests /live-webinars/{industry}/{slug}. That is left until P2 builds
 * the catalogue those first two segments link to — inventing the nesting now
 * would mean guessing how type and industry route, then redirecting away from a
 * URL that had already been indexed. §5.6 has a `redirects` table for the move
 * when it happens, and this slug stays the canonical tail of it either way.
 */
/*
 * The catalogue (§7 P2) — the filtered grid the breadcrumb above links into.
 * Above the {webinar:slug} show route so `/webinars` is the list, not a webinar
 * whose slug is empty. Industry landing pages share the same page with the topic
 * preset; `webinars` and `industry` are reserved slugs.
 */
Route::get('/webinars', [App\Http\Controllers\CatalogueController::class, 'index'])->name('webinars.index');
Route::get('/webinars/{webinar:slug}', WebinarController::class)->name('webinars.show');
Route::get('/industry/{industry:slug}', [App\Http\Controllers\IndustryController::class, 'show'])->name('industries.show');

/*
 * The faculty — §6's "Speakers | Index & profile".
 *
 * Its own `/speakers` prefix above the page catch-all, with `speakers` reserved
 * so a marketing page cannot shadow it. The profile route sits after the index
 * so `/speakers` is the list, not a profile whose slug happens to be empty.
 */
Route::get('/speakers', [SpeakerController::class, 'index'])->name('speakers.index');
Route::get('/speakers/{speaker:slug}', [SpeakerController::class, 'show'])->name('speakers.show');

/*
 * Cart — §5.4 / §6 (P3 Commerce). The cart page is a Livewire component; adding
 * is a throttled POST that re-derives every price from the offering (§14 #1).
 * Above the page catch-all, and `cart`/`checkout` are reserved slugs.
 */
Route::get('/cart', [CartController::class, 'index'])->name('cart.index');
Route::post('/cart/add', [CartController::class, 'add'])->middleware('throttle:cart')->name('cart.add');

/*
 * Checkout — the Livewire checkout component collects details and places the
 * order through PlaceOrder (one transaction, totals recomputed server-side).
 * The received page is shown to the owner or whoever just placed it.
 */
Route::get('/checkout', [CheckoutController::class, 'index'])->name('checkout.index');

/*
 * Taking payment on a pending order. The page renders the gateway UI; its two
 * AJAX endpoints set up and finalise the payment through the same idempotent
 * RecordCapture the webhook uses.
 */
Route::get('/checkout/pay/{order}', [PaymentController::class, 'show'])->name('checkout.pay');
Route::post('/checkout/pay/{order}/start', [PaymentController::class, 'start'])->middleware('throttle:cart')->name('checkout.pay.start');
Route::post('/checkout/pay/{order}/complete', [PaymentController::class, 'complete'])->middleware('throttle:cart')->name('checkout.pay.complete');

Route::get('/checkout/complete/{order}', [CheckoutController::class, 'received'])->name('checkout.complete');

/*
 * Public auth entry points — docs/auth.md. Customer auth lives in the `account`
 * Filament panel; these are just friendly GET aliases to its login and register
 * pages (pure redirects, no auth logic — nothing like Fortify's global
 * POST /login), so the navbar and redirect()->guest() can say route('login').
 */
/*
 * One branded customer sign-in / sign-up page (a Livewire full-page component),
 * replacing the Filament account panel's own login. Email and password go
 * through Laravel's guard; the social routes hand off to Socialite. Staff are
 * refused here and use /admin, so there is still no customer login that skips the
 * admin two-factor challenge (docs/auth.md).
 */
Route::get('/login', App\Livewire\Auth\AuthPage::class)->name('login');
Route::get('/register', fn () => redirect()->route('login', ['mode' => 'register']))->name('register');

Route::get('/auth/{provider}/redirect', [App\Http\Controllers\Auth\SocialAuthController::class, 'redirect'])
    ->name('auth.redirect');
Route::get('/auth/{provider}/callback', [App\Http\Controllers\Auth\SocialAuthController::class, 'callback'])
    ->name('auth.callback');

Route::post('/logout', function () {
    Illuminate\Support\Facades\Auth::guard('web')->logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();

    return redirect()->route('home');
})->name('logout');

/*
 * Memberships — §6 / P4. The pricing page is public; the subscribe page needs a
 * signed-in, billable account (its controller sends a guest to log in). Above
 * the page catch-all, and `membership` is a reserved slug.
 */
Route::get('/membership', [App\Http\Controllers\MembershipController::class, 'index'])->name('membership.index');

/*
 * Self-service management of the member's own subscription (§6). All resolve the
 * subscription from the signed-in account, never from the request.
 */
Route::get('/membership/manage', [App\Http\Controllers\MembershipController::class, 'manage'])
    ->middleware('auth')->name('membership.manage');
Route::post('/membership/manage/cancel', [App\Http\Controllers\MembershipController::class, 'cancel'])
    ->middleware(['auth', 'throttle:cart'])->name('membership.cancel');
Route::post('/membership/manage/resume', [App\Http\Controllers\MembershipController::class, 'resume'])
    ->middleware(['auth', 'throttle:cart'])->name('membership.resume');
Route::post('/membership/manage/change', [App\Http\Controllers\MembershipController::class, 'change'])
    ->middleware(['auth', 'throttle:cart'])->name('membership.change');

/*
 * Spend a membership allowance to unlock a webinar (§5.2). The webinar is the
 * route key; the format comes in the body. Redeeming grants one ledger row, and
 * the webinar page then shows the format as held.
 */
Route::post('/membership/watch/{webinar:slug}', [App\Http\Controllers\MembershipAccessController::class, 'watch'])
    ->middleware(['auth', 'throttle:cart'])->name('membership.watch');
Route::get('/membership/{plan:slug}/subscribe', [App\Http\Controllers\MembershipController::class, 'subscribe'])->name('membership.subscribe');
Route::post('/membership/{plan:slug}/subscribe/intent', [App\Http\Controllers\MembershipController::class, 'intent'])
    ->middleware(['auth', 'throttle:cart'])->name('membership.subscribe.intent');
Route::post('/membership/{plan:slug}/subscribe/confirm', [App\Http\Controllers\MembershipController::class, 'confirm'])
    ->middleware(['auth', 'throttle:cart'])->name('membership.subscribe.confirm');

/*
 * Learner access to what they are entitled to (§5.2 / §9). Gated by the
 * entitlement ledger in the controller, not by the route; the file itself is
 * reached through a signed, expiring URL the controller hands back.
 */
// A study-material file — any active entitlement for its webinar opens it,
// through a signed, expiring URL. Declared before the {webinar}/{format} routes
// so the literal "handout" segment is matched first, not as a webinar slug.
Route::get('/learning/handout/{handout}', [App\Http\Controllers\LearningAccessController::class, 'handout'])
    ->middleware('auth')->name('learning.handout');

// A learner's own CEU certificate PDF — owner-scoped, through a signed, expiring
// URL. Literal "certificate" segment, so declared before the {webinar}/{format}
// routes for the same reason as the handout route above.
Route::get('/learning/certificate/{certificate}', [App\Http\Controllers\LearningAccessController::class, 'certificate'])
    ->middleware('auth')->name('learning.certificate');

Route::get('/learning/{webinar:slug}/{format}', [App\Http\Controllers\LearningAccessController::class, 'access'])
    ->middleware('auth')->name('learning.access');

// The in-site player: the same gate, but the recording is embedded rather than
// redirected away, so the learner watches without leaving the site.
Route::get('/learning/{webinar:slug}/{format}/watch', [App\Http\Controllers\LearningAccessController::class, 'watch'])
    ->middleware('auth')->name('learning.watch');

/*
 * The invoice PDF — §8 / §9. `signed` is the whole gate: a guest buyer has no
 * login, so an unexpired signed link (handed out only by the confirmation email
 * and the received page) stands in for one, and because the link names the order
 * it grants that one invoice and no other (closing §14 #10's IDOR).
 */
Route::get('/invoice/{order}', [App\Http\Controllers\InvoiceController::class, 'download'])
    ->middleware('signed')
    ->name('invoice.download');

/*
 * PayPal payment webhook — the source of truth for a payment, independent of the
 * browser (§14 #6). Server-to-server, so CSRF is excluded; its authenticity is
 * PayPal's signature, verified in the controller. Finalising is idempotent.
 */
Route::post('/paypal/webhook', App\Http\Controllers\Webhooks\PayPalWebhookController::class)
    ->withoutMiddleware([
        Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
        Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
    ])
    ->name('webhooks.paypal');

/*
 * The blog — §5.7 and §6.
 *
 * Its own `/blog` prefix rather than living under the page catch-all, because
 * an article and a marketing page are different things with different
 * templates, and one namespace for both would mean a post and a page competing
 * for the same slug.
 */
Route::get('/'.Post::PATH_PREFIX, BlogController::class)->name('blog.index');

Route::get('/'.Post::PATH_PREFIX.'/preview/{post}', PostPreviewController::class)
    ->middleware('signed')
    ->name('blog.preview');

/*
 * After the preview route, so `/blog/preview/…` is not read as an article
 * whose slug happens to be "preview".
 */
Route::get('/'.Post::PATH_PREFIX.'/{slug}', PostController::class)->name('blog.show');

/*
 * Leaving a comment.
 *
 * Unauthenticated by design — most readers of a marketing blog do not have an
 * account and never will — which makes it an open endpoint that writes a row,
 * so it is throttled. Nothing it writes reaches the site: SubmitComment decides
 * between the queue and the spam tab, and only the Comments screen publishes.
 */
Route::post('/'.Post::PATH_PREFIX.'/{slug}/comments', CommentController::class)
    ->middleware('throttle:comments')
    ->name('blog.comments.store');

/*
 * A draft, shown to somebody who was sent the link.
 *
 * Before the catch-all so the path is unambiguous, and `signed` is the whole
 * access control — §9 makes signed expiring URLs the rule for anything that
 * must not be reachable by guessing. No auth: the point is to send it to a
 * client for review, and requiring an admin account would mean either no review
 * or an account they should not have.
 */
Route::get('/preview/{page}', PagePreviewController::class)
    ->middleware('signed')
    ->name('pages.preview');

/*
 * The mailing list — §5.7.
 *
 * Above the page catch-all, and under their own prefix, so a marketing page can
 * never take a slug that shadows an unsubscribe link.
 *
 * The signup box is throttled for the same reason the comment form is: an
 * unauthenticated endpoint that writes a row. The two links are `signed`, which
 * §9 makes the rule for anything that must not be reachable by guessing — and
 * here it is doing real work, because a guessable unsubscribe URL would let
 * anybody take anybody else off the list.
 *
 * Unsubscribing is a GET that shows a page and a POST that acts. Mail scanners
 * and link-preview services fetch every URL in an email, and a GET that acted
 * would silently unsubscribe people who never clicked.
 */
Route::post('/newsletter', [MailingListController::class, 'store'])
    ->middleware('throttle:comments')
    ->name('newsletter.store');

Route::get('/mailing-list/confirm/{subscriber}', [MailingListController::class, 'confirm'])
    ->middleware('signed')
    ->name('mailing-list.confirm');

Route::get('/mailing-list/unsubscribe/{subscriber}', [MailingListController::class, 'confirmUnsubscribe'])
    ->middleware('signed')
    ->name('mailing-list.unsubscribe');

Route::post('/mailing-list/unsubscribe/{subscriber}', [MailingListController::class, 'unsubscribe'])
    ->middleware('signed')
    ->name('mailing-list.unsubscribe.confirm');

/*
 * Client-built marketing pages — §5.6 and §2's second leverage decision.
 *
 * Last, and deliberately a catch-all: `/about`, `/privacy-policy` and whatever
 * else the client makes are all one route, because inventing a route per page
 * is the thing the page builder exists to stop.
 *
 * Being last is what keeps it safe — Laravel matches in order, so every route
 * above wins. Page::RESERVED_SLUGS covers the rest (the admin panel, the auth
 * paths a public login would claim, Horizon), refusing them at the point a slug is saved rather than
 * leaving a page to be created that could never be reached.
 *
 * The {slug} pattern excludes slashes by default, so this claims one path
 * segment only. Nested pages, if they are ever wanted, are a deliberate change
 * rather than something that happens by accident.
 */
Route::get('/{slug}', PageController::class)->name('pages.show');
