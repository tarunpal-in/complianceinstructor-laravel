# Authentication — what owns what

> **Decision record, 2026-10-02.** Supersedes CLAUDE.md §3's entry for
> `laravel/fortify` and §9's phrasing "Fortify owns all password resets".
> The *requirement* in §9 is unchanged and still met; only the named package is.

## The short version

**Filament owns authentication. Laravel's own password broker owns reset tokens.
`laravel/fortify` has been removed.**

Everything §9 asks for is still true, and now true of code that actually runs:

| §9 requires | Provided by |
|---|---|
| Signed, single-use reset tokens | `Illuminate\Auth\Passwords\PasswordBroker` — the token is hashed in `password_reset_tokens` and deleted on use |
| Expiring tokens | `config/auth.php` → `passwords.users.expire` (60 minutes) |
| Throttling on reset | `passwords.users.throttle` (60 seconds), plus `$this->rateLimit(2)` on Filament's request page |
| No hand-rolled reset endpoint | There is no public auth route at all. `AuthRoutesTest` asserts it |

## Why Fortify was removed rather than registered

It was installed but its service provider was **never listed in
`bootstrap/providers.php`**, so none of it ran: no routes, no action bindings,
no config read. Three months of looking live while being inert — and
`config/fortify.php` described a configuration that was not in effect, which is
worse than no config at all, because the next person reads it as truth.

Registering it was the obvious fix and the wrong one. Three reasons, in order of
severity:

**1. It would have opened a second login that skips two-factor.**
`POST /login` is registered unconditionally in Fortify's route file — not behind
`fortify.views`, not behind a feature flag. Filament's multi-factor challenge
lives in Filament's own login page, so a request straight to `POST /login`
authenticates on the same `web` session having never been asked for a code.
Two-factor is optional today, so nobody is relying on it yet; that is luck, not
design, and it is the sort of luck that runs out the week it is switched to
required.

**2. The provider could not boot.** Line 38 called
`Fortify::redirectUserForTwoFactorAuthenticationUsing(RedirectIfTwoFactorAuthenticatable::class)`
with no import and no such class anywhere in the application — so it resolved to
`App\Providers\RedirectIfTwoFactorAuthenticatable` and would have thrown on the
first request after registration.

**3. `Features::registration()` was enabled**, which registers a public
`POST /register` that creates accounts. There is no customer area on this site
yet, so that is an unauthenticated row-writing endpoint with nothing behind it —
the same shape as §9's second critical finding in the predecessor.

There is no configuration of Fortify that is both safe and useful here today.
With `views => false` it registers no reset form; with `ignoreRoutes()` it
registers nothing at all. What was left after disabling everything dangerous was
five stock action classes that nothing called.

## What was deleted

- `app/Providers/FortifyServiceProvider.php`
- `app/Actions/Fortify/` — five classes, all unmodified Laravel scaffolding
- `config/fortify.php`
- the `laravel/fortify` composer dependency

The two-factor **columns** stay. `2026_08_27_000337_add_two_factor_columns_to_users_table.php`
is our migration, Filament reads those columns, and `User` encrypts the secret
itself. Nothing about two-factor changes.

`PasswordValidationRules` is no loss: it returned `Password::default()`, which is
exactly what Filament's reset page already applies.

## Customer auth — decided for P4 (2026-10-14)

P4 (memberships) is the first phase that cannot work without customer accounts:
Cashier attaches the Stripe customer to a billable `User`, and a subscription
belongs to one. Three options were on the table:

1. **A second Filament panel** for customers. Consistent with §2's first leverage
   decision, and it already has login, reset, verification and two-factor built.
2. **Re-add Fortify**, headless, with `registration()` on and the `POST /login`
   conflict resolved by giving customers their own guard or route prefix.
3. **Hand-built Blade auth** on Laravel's broker. Most control, most §9 risk.

**Chosen: option 1 — the `account` panel** (`App\Providers\Filament\AccountPanelProvider`,
served at `/account`). It is the cheapest and the most consistent, and decisively
it registers **no global `POST /login`**: each panel authenticates through its own
page, so the two-factor bypass that kept Fortify out cannot exist.

How the bypass stays closed: it is enforced at *login*, not by partitioning who
may view which panel. `User::canAccessPanel` gates `admin` to the §8 role-holders;
the `account` (learner) panel is open to any active account — see the 2026-10-22
note below. A staff member still cannot *authenticate* through the customer side:
the branded `/login` refuses staff accounts and there is no global `POST /login`,
so their only `web` session is the one `/admin` issues behind two-factor.
`AuthRoutesTest` asserts both the login refusal and the absence of any `POST /login`.

> **Historical note.** Until 2026-10-22, `canAccessPanel` also blocked staff from
> the `account` panel outright, and that block was described here as the line
> preventing the bypass. It never was — the login refusal always was — and the
> block was removed so an admin can open the learner dashboard (see below).

What the panel owns: login, registration (customers get no role, so they are
customers by construction), password reset (Laravel's broker still issues the
token; `AppServiceProvider` carries both the reset and the verification mail
through the Dispatcher so they are logged like every other email), and email
verification (`User implements MustVerifyEmail`). `/login` and `/register` are
plain GET redirect aliases into the panel for the navbar's convenience.

The learner dashboard (P5) will fill this panel's currently-bare dashboard.

## Branded sign-in and social login (2026-10-14)

The Filament-styled account login was replaced by **one branded page at `/login`**
(a Livewire full-page component, `App\Livewire\Auth\AuthPage`) that toggles
between sign-in and sign-up and offers social login. The account panel keeps
password reset, email verification and the profile page, but its own `->login()`
and `->registration()` are off; an unauthenticated visitor to `/account` is sent
to `route('login')`.

This is the "hand-built Blade auth" option, chosen for the on-brand experience —
so the bypass that kept Fortify out has to be closed by hand, and is:

- There is still **no global `POST /login`**. Email/password sign-in is a Livewire
  action, not a route; `AuthRoutesTest` asserts no POST route at `login`.
- The sign-in action and the social callback both **refuse a staff account** and
  send it to the admin login, so neither becomes a second way into a `web`
  session that skipped the admin two-factor challenge.
- New accounts (email or social) are customers — no role. Social identities come
  back email-verified; email/password sign-ups fire `Registered`, which now routes
  the verification mail through the account panel's verify route via the
  Dispatcher (`User::sendEmailVerificationNotification`), like the reset.

**Social providers** are Google, LinkedIn and Microsoft through Socialite
(`SocialAuthController`, `App\Support\SocialProviders`). Each is config-gated on
its credentials, so a button shows only when that provider is set up; the OAuth
round-trip itself is verified once real credentials are added, as with the
payment gateways.

## Admins may view the learner dashboard (2026-10-22)

`canAccessPanel` now returns `true` for the `account` panel for **any active
account, staff included** — previously it blocked staff (`! isStaff()`). So a
signed-in admin can open the learner dashboard from the storefront (the navbar's
"My Account" button points there for everyone; staff reach `/admin` from the
staff bar at the top of the public page, `x-site.admin-bar`).

This does **not** weaken the two-factor separation, because that was never what
the block protected:

- The only sign-in on the `account` side is the branded `/login` (`AuthPage`),
  whose login action and the social callback both **refuse a staff account**. The
  account panel declares no `->login()` of its own. So there is still no way for
  a staff member to obtain a `web` session without going through `/admin` and its
  two-factor challenge.
- The admin panel stays gated to the four §8 roles; a customer is still refused.

`AuthRoutesTest` covers all three: staff *and* customers can open `account`,
customers cannot open `admin`, the branded `/login` still refuses staff, and a
signed-in admin can load the learner dashboard. The change is small on purpose —
an admin's learner view is simply their own (usually empty) entitlements.

## Known gap, not addressed here

**No password policy is configured.** `Password::defaults()` is never called, so
`Password::default()` means eight characters with no complexity or breach check —
on both the Filament reset page and anywhere else. That was equally true before
this change. Setting a policy is one line in `AppServiceProvider`; it is left
out of this change deliberately, because it changes what passwords existing
staff are allowed to set and that is worth agreeing rather than slipping in.
