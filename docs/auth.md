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

## What this means for P5

The learner area needs public registration, login, email verification and
password reset for customers. Three options, to be chosen **then** rather than
assumed now:

1. **A second Filament panel** for customers. Consistent with §2's first leverage
   decision, and it already has login, reset and two-factor built.
2. **Re-add Fortify**, headless, with `registration()` on and the `POST /login`
   conflict resolved by giving customers their own guard or route prefix.
3. **Hand-built Blade auth** on Laravel's broker. Most control, most §9 risk.

Option 1 is the cheapest and the most consistent; option 2 is what the brief
assumed. Either way the decision wants the public auth design in front of it.

## Known gap, not addressed here

**No password policy is configured.** `Password::defaults()` is never called, so
`Password::default()` means eight characters with no complexity or breach check —
on both the Filament reset page and anywhere else. That was equally true before
this change. Setting a policy is one line in `AppServiceProvider`; it is left
out of this change deliberately, because it changes what passwords existing
staff are allowed to set and that is worth agreeing rather than slipping in.
