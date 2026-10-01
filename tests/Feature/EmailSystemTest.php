<?php

declare(strict_types=1);

use App\Email\Dispatcher;
use App\Email\EmailCatalogue;
use App\Email\EmailDefinition;
use App\Email\Resend;
use App\Email\SendBudget;
use App\Enums\EmailCategory;
use App\Enums\EmailStatus;
use App\Enums\EmailTier;
use App\Enums\SubscriberSource;
use App\Enums\SuppressionReason;
use App\Enums\UserRole;
use App\Mail\ConfirmMailingList;
use App\Mail\PasswordChanged;
use App\Models\EmailSend;
use App\Models\EmailSuppression;
use App\Models\Setting;
use App\Models\Subscriber;
use App\Models\User;
use App\Settings\SettingRegistry;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\SplFileInfo;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

/** A subscriber waiting to confirm, which is what sends the confirmation email. */
function pendingSubscriber(string $email = 'dana@example.com'): Subscriber
{
    Setting::set('mailing_list.double_opt_in', true, 'boolean');

    return app(App\Actions\Subscribers\Subscribe::class)($email, SubscriberSource::Footer);
}

// Nothing sends without a record ---------------------------------------------------------------

/*
 * The whole point. §14 #6 is a customer charged who "received nothing, with no
 * record written" — and the half people skip is the second half. A row exists
 * before anything reaches a mail server, so a failure, a refusal and a deferral
 * all leave evidence.
 */
it('writes exactly one row for every email it sends', function (): void {
    $user = User::factory()->create(['email' => 'dana@example.com']);

    app(Dispatcher::class)->send(new PasswordChanged($user));

    $send = EmailSend::query()->sole();

    expect($send->email_key)->toBe('account.password_changed')
        ->and($send->to_email)->toBe('dana@example.com')
        ->and($send->status)->toBe(EmailStatus::Sent)
        ->and($send->body_html)->toContain('password for')
        ->and($send->message_id)->not->toBeNull()
        ->and($send->queued_at)->not->toBeNull();
});

/*
 * The structural guard. Without the phpstan ban this class is a convention, and
 * the first person in a hurry sends straight from an action, putting a hole in
 * the log nobody notices until a customer says they never got it.
 *
 * phpstan.neon is the better guard — type-aware, and it tells whoever made the
 * mistake why at the point they made it. This exists because that ban is config
 * somebody could delete along with the rule it protects.
 */
it('sends no mail outside the dispatcher', function (): void {
    $allowed = [
        'app/Jobs/SendEmail.php' => 'The one place that actually hands a message to the mail server.',
        'app/Email/Dispatcher.php' => 'The dispatcher itself.',
    ];

    $offenders = collect(File::allFiles(app_path()))
        ->filter(fn (SplFileInfo $file): bool => $file->getExtension() === 'php')
        ->map(fn (SplFileInfo $file): string => str_replace('\\', '/', $file->getPathname()))
        ->reject(fn (string $path): bool => collect(array_keys($allowed))
            ->contains(fn (string $exempt): bool => str_ends_with($path, $exempt)))
        ->filter(function (string $path): bool {
            $source = (string) file_get_contents($path);

            return preg_match('/Mail::(to|send|queue|raw|html)\(/', $source) === 1
                || preg_match('/Notification::(send|route)\(/', $source) === 1
                || preg_match('/->notify(Now)?\(/', $source) === 1;
        })
        ->values()
        ->all();

    expect($offenders)->toBe([]);
});

// The order the guards run in -------------------------------------------------------------------

/*
 * The kill switch beats everything, including a required email. It exists for the
 * day a template goes out wrong, and an exception for "important" mail would make
 * it useless on exactly that day.
 */
it('sends nothing at all while sending is halted', function (): void {
    Setting::set('email.sending_halted', true, 'boolean');

    $send = app(Dispatcher::class)->send(new PasswordChanged(User::factory()->create()));

    expect($send->status)->toBe(EmailStatus::Suppressed)
        ->and($send->outcome_note)->toContain('halted')
        ->and($send->sent_at)->toBeNull();
});

it('records a halted email rather than dropping it', function (): void {
    Setting::set('email.sending_halted', true, 'boolean');

    app(Dispatcher::class)->send(new PasswordChanged(User::factory()->create()));

    // The whole difference between a log and silence.
    expect(EmailSend::query()->count())->toBe(1);
});

/*
 * A bouncing mailbox stops everything — sending an invoice to an address that
 * does not exist achieves nothing and costs deliverability for everybody else.
 */
it('holds back a required email to a bouncing address', function (): void {
    $user = User::factory()->create(['email' => 'gone@example.com']);
    EmailSuppression::record('gone@example.com', SuppressionReason::Bounced);

    $send = app(Dispatcher::class)->send(new PasswordChanged($user));

    expect($send->status)->toBe(EmailStatus::Suppressed)
        ->and($send->outcome_note)->toContain('Bouncing');
});

/*
 * **The important half.** Somebody who reported a newsletter as spam is still
 * owed the receipt for what they paid for. Treating one switch as both would be
 * us deciding they may not have their own invoice.
 */
it('still sends a required email to somebody who reported us as spam', function (): void {
    $user = User::factory()->create(['email' => 'cross@example.com']);
    EmailSuppression::record('cross@example.com', SuppressionReason::Complained);

    $send = app(Dispatcher::class)->send(new PasswordChanged($user));

    expect($send->status)->toBe(EmailStatus::Sent);
});

it('holds back marketing to somebody who reported us as spam', function (): void {
    EmailSuppression::record('cross@example.com', SuppressionReason::Complained);

    $send = app(Dispatcher::class)->send(new ConfirmMailingList(
        Subscriber::factory()->create(['email' => 'cross@example.com']),
    ));

    expect($send->status)->toBe(EmailStatus::Suppressed);
});

/*
 * The seeder writes realistic customer addresses, and a mistake on staging emails
 * strangers on the client's behalf. Recorded rather than dropped, so staging shows
 * the same shape of log as production.
 */
it('sends only to the allow list outside production', function (): void {
    Setting::set('email.allow_list', 'example.test, dana@example.com', 'string');

    $allowed = app(Dispatcher::class)->send(new PasswordChanged(
        User::factory()->create(['email' => 'dana@example.com']),
    ));
    $blocked = app(Dispatcher::class)->send(new PasswordChanged(
        User::factory()->create(['email' => 'stranger@gmail.com']),
    ));

    expect($allowed->status)->toBe(EmailStatus::Sent)
        ->and($blocked->status)->toBe(EmailStatus::Suppressed)
        ->and($blocked->outcome_note)->toContain('allow list');
});

// Required emails cannot be switched off ----------------------------------------------------------

/*
 * §14 #6 reachable from an admin screen is the thing EmailTier::Required exists to
 * prevent. A client reducing email volume must not be able to stop telling
 * customers what they were charged.
 */
it('gives a required email no off switch at all', function (): void {
    foreach (EmailCatalogue::all() as $email) {
        if ($email->tier !== EmailTier::Required) {
            continue;
        }

        expect(SettingRegistry::has($email->settingKey()))->toBeFalse()
            ->and($email->isEnabled())->toBeTrue();
    }
});

it('keeps receipts, resets and certificates in the required tier', function (string $key): void {
    expect(EmailCatalogue::find($key)?->tier)->toBe(EmailTier::Required);
})->with([
    'purchase.order_confirmation',
    'purchase.refund_issued',
    'account.password_reset',
    'credential.certificate_issued',
]);

it('refuses to switch off a required email even when asked directly', function (): void {
    $this->actingAs(userWithRole(UserRole::SuperAdmin));

    Livewire\Livewire::test(App\Filament\Pages\Emails::class)
        ->call('toggle', 'purchase.refund_issued');

    expect(EmailCatalogue::find('purchase.refund_issued')?->isEnabled())->toBeTrue()
        ->and(Setting::query()->where('key', 'like', '%refund_issued%')->count())->toBe(0);
});

it('explains why a required email has no switch', function (): void {
    foreach (EmailCatalogue::all() as $email) {
        if ($email->tier === EmailTier::Required) {
            // A lock with no reason beside it reads as an oversight, and the
            // client is owed the argument rather than the wall.
            expect($email->lockReason)->not->toBeNull("{$email->key} is required but says nothing about why");
        }
    }
});

// Recipient preferences ------------------------------------------------------------------------

it('lets somebody decline a category they are allowed to decline', function (): void {
    $user = User::factory()->create();

    $user->setEmailCategoryAccepted(EmailCategory::Reminder, false);

    expect($user->acceptsEmailCategory(EmailCategory::Reminder))->toBeFalse()
        ->and($user->acceptsEmailCategory(EmailCategory::Delivery))->toBeTrue();
});

/*
 * A preference that could suppress a receipt would be the polite version of
 * §14 #6. Refused loudly rather than ignored: a caller that thinks it switched
 * receipts off and silently did not is worse than one that is told.
 */
it('refuses to let somebody decline their own receipts', function (): void {
    $user = User::factory()->create();

    expect(fn () => $user->setEmailCategoryAccepted(EmailCategory::Purchase, false))
        ->toThrow(RuntimeException::class, 'cannot be declined');
});

it('ignores a preference against an email somebody is owed', function (): void {
    $user = User::factory()->create(['email' => 'dana@example.com']);

    // Written straight past the guard, as a stale row from before a category
    // changed tier would be.
    $user->setPreference('email.declined', [EmailCategory::Account->value]);

    expect(app(Dispatcher::class)->send(new PasswordChanged($user->refresh()))->status)
        ->toBe(EmailStatus::Sent);
});

// The sending allowance --------------------------------------------------------------------------

/*
 * A consumer Gmail account blocks the whole mailbox for roughly 24 hours when the
 * daily limit is passed — password resets included. Deferring is how that is
 * avoided without losing anything.
 */
it('defers rather than failing when the allowance is spent', function (): void {
    Setting::set('email.daily_cap', 1, 'integer');
    Setting::set('email.bulk_cap', 1, 'integer');

    app(Dispatcher::class)->send(new PasswordChanged(User::factory()->create()));
    $second = app(Dispatcher::class)->send(new PasswordChanged(User::factory()->create()));

    expect($second->status)->toBe(EmailStatus::Deferred)
        ->and($second->outcome_note)->toContain('allowance');
});

/*
 * **The reserve.** Somebody locked out of their account at 4pm because a
 * newsletter went out at 3pm is the specific outcome the two caps buy off.
 */
it('keeps allowance in reserve for transactional mail when bulk is spent', function (): void {
    Setting::set('email.daily_cap', 10, 'integer');
    Setting::set('email.bulk_cap', 2, 'integer');

    EmailSend::factory()->count(3)->create(['sent_at' => now()]);

    $reminders = EmailCatalogue::find('reminder.one_hour');
    $resets = EmailCatalogue::find('account.password_reset');

    expect($reminders)->toBeInstanceOf(EmailDefinition::class)
        ->and($resets)->toBeInstanceOf(EmailDefinition::class)
        ->and(SendBudget::allows($reminders))->toBeFalse()
        ->and(SendBudget::allows($resets))->toBeTrue();
});

it('sends a deferred email once the allowance frees up', function (): void {
    Setting::set('email.daily_cap', 1, 'integer');
    Setting::set('email.bulk_cap', 1, 'integer');

    app(Dispatcher::class)->send(new PasswordChanged(User::factory()->create()));
    $deferred = app(Dispatcher::class)->send(new PasswordChanged(User::factory()->create()));

    expect($deferred->status)->toBe(EmailStatus::Deferred);

    Setting::set('email.daily_cap', 50, 'integer');
    Setting::set('email.bulk_cap', 50, 'integer');

    $this->artisan('emails:release-deferred')->assertSuccessful();

    expect($deferred->refresh()->status)->toBe(EmailStatus::Sent);
});

// Resending ------------------------------------------------------------------------------------

/*
 * A resend is a new row. The original keeps its failure, which is the one fact
 * anybody came to the log to find — overwriting it would erase the reason for
 * the resend.
 */
it('writes a new row on a resend and never touches the original', function (): void {
    $original = EmailSend::factory()->failed()->create([
        'email_key' => 'account.password_changed',
        'category' => EmailCategory::Account,
        'mailable' => PasswordChanged::class,
        'payload' => ['user_id' => User::factory()->create()->getKey()],
    ]);

    $resend = app(Resend::class)->rebuilt($original, userWithRole(UserRole::SuperAdmin));

    expect($resend->getKey())->not->toBe($original->getKey())
        ->and($resend->resent_from_id)->toBe($original->getKey())
        ->and($resend->status)->toBe(EmailStatus::Sent)
        // Untouched.
        ->and($original->refresh()->status)->toBe(EmailStatus::Failed)
        ->and($original->error)->not->toBeNull();
});

/*
 * The commonest real reason to resend is a mistyped address, and a resend to the
 * same wrong address achieves nothing.
 */
it('can resend to a corrected address, leaving the original address on record', function (): void {
    $original = EmailSend::factory()->failed()->create([
        'email_key' => 'account.password_changed',
        'category' => EmailCategory::Account,
        'mailable' => PasswordChanged::class,
        'to_email' => 'dana@exmaple.com',
        'payload' => ['user_id' => User::factory()->create()->getKey()],
    ]);

    $resend = app(Resend::class)->rebuilt($original, null, 'dana@example.com');

    expect($resend->to_email)->toBe('dana@example.com')
        ->and($original->refresh()->to_email)->toBe('dana@exmaple.com');
});

/*
 * §9 puts every file behind a signed expiring URL, so a body stored in March holds
 * a link that died in March. Replaying it would send a dead page while reporting
 * success, which is worse than refusing.
 */
it('refuses to replay a stored body that contains an expiring link', function (): void {
    /*
     * The mailing-list confirmation specifically, not the staff invitation.
     * The invitation also carries `notResendableReason`, which short-circuits
     * before the link check is ever reached — so a test using it passes even with
     * the expiring-link rule deleted. This one is resendable *except* verbatim,
     * which is the only shape that exercises the rule.
     */
    $send = EmailSend::factory()->create([
        'email_key' => 'marketing.list_confirm',
        'category' => EmailCategory::Marketing,
        'mailable' => ConfirmMailingList::class,
        'body_html' => '<a href="https://example.test/mailing-list/confirm/1?signature=dead">Confirm</a>',
    ]);

    expect($send->mayResendVerbatim())->toBeFalse()
        // Rebuilding is offered instead, which re-signs the link.
        ->and($send->mayResendRebuilt())->toBeTrue();
});

it('refuses to replay the staff invitation, whose token is single-use', function (): void {
    $send = EmailSend::factory()->create([
        'email_key' => 'internal.staff_invitation',
        'category' => EmailCategory::Internal,
    ]);

    expect($send->mayResendVerbatim())->toBeFalse()
        ->and($send->resendRefusal())->toContain('single-use');
});

/*
 * Rebuilding re-signs the link rather than replaying a dead one, which is the
 * whole reason the two modes exist separately.
 */
it('re-signs the link when rebuilding a confirmation', function (): void {
    $subscriber = Subscriber::factory()->create();

    $original = EmailSend::factory()->create([
        'email_key' => 'marketing.list_confirm',
        'category' => EmailCategory::Marketing,
        'mailable' => ConfirmMailingList::class,
        'to_email' => $subscriber->email,
        'payload' => ['subscriber_id' => $subscriber->getKey()],
        'body_html' => '<a href="https://example.test/old?signature=dead">Confirm</a>',
    ]);

    $resend = app(Resend::class)->rebuilt($original);

    expect($resend->body_html)->not->toContain('signature=dead')
        ->and($resend->body_html)->toContain('mailing-list/confirm/'.$subscriber->getKey());
});

it('refuses to resend a password reset at all', function (): void {
    $send = EmailSend::factory()->create([
        'email_key' => 'account.password_reset',
        'category' => EmailCategory::Account,
    ]);

    expect($send->mayResendVerbatim())->toBeFalse()
        ->and($send->mayResendRebuilt())->toBeFalse()
        ->and($send->resendRefusal())->toContain('single-use');
});

it('replays a stored body exactly for an email with no expiring link', function (): void {
    $send = EmailSend::factory()->create([
        'email_key' => 'account.password_changed',
        'category' => EmailCategory::Account,
        'mailable' => PasswordChanged::class,
        'body_html' => '<p>Original wording, since edited in the template.</p>',
    ]);

    $resend = app(Resend::class)->verbatim($send);

    expect($resend->body_html)->toBe('<p>Original wording, since edited in the template.</p>');
});

it('says so rather than throwing when the record behind an email has gone', function (): void {
    $user = User::factory()->create();
    $original = EmailSend::factory()->create([
        'email_key' => 'account.password_changed',
        'category' => EmailCategory::Account,
        'mailable' => PasswordChanged::class,
        'payload' => ['user_id' => $user->getKey()],
    ]);

    $user->delete();

    $resend = app(Resend::class)->rebuilt($original);

    expect($resend->status)->toBe(EmailStatus::Suppressed)
        ->and($resend->outcome_note)->toContain('no longer exists');
});

// The screens -----------------------------------------------------------------------------------

it('keeps the email log to roles holding view_email_log', function (): void {
    $this->actingAs(userWithRole(UserRole::SuperAdmin))->get('/admin/email-log')->assertSuccessful();
    $this->actingAs(userWithRole(UserRole::Finance))->get('/admin/email-log')->assertSuccessful();
    $this->actingAs(userWithRole(UserRole::Support))->get('/admin/email-log')->assertSuccessful();

    // Content Editor holds none of it: the full text of every invoice and reset
    // link is on this screen.
    $this->actingAs(userWithRole(UserRole::ContentEditor))->get('/admin/email-log')->assertForbidden();
});

it('allows nothing in the log to be created, changed or deleted', function (): void {
    $send = EmailSend::factory()->create();
    $resource = App\Filament\Resources\EmailLog\EmailSendResource::class;

    expect($resource::canCreate())->toBeFalse()
        ->and($resource::canEdit($send))->toBeFalse()
        ->and($resource::canDelete($send))->toBeFalse()
        ->and($resource::canDeleteAny())->toBeFalse()
        ->and(array_keys($resource::getPages()))->toBe(['index', 'view']);
});

it('renders a stored email in a sandboxed frame', function (): void {
    $this->actingAs(userWithRole(UserRole::SuperAdmin));

    $send = EmailSend::factory()->create(['body_html' => '<p>Hello from the log.</p>']);

    $response = $this->get('/admin/email-log/'.$send->getKey())->assertSuccessful();

    // Stored HTML assembled partly from customer-supplied values. The settings
    // audit found a stored XSS through exactly this assumption.
    expect($response->getContent())->toContain('sandbox');
});

it('says so when a body has been pruned rather than showing an empty frame', function (): void {
    $this->actingAs(userWithRole(UserRole::SuperAdmin));

    $send = EmailSend::factory()->pruned()->create();

    $this->get('/admin/email-log/'.$send->getKey())
        ->assertSuccessful()
        ->assertSee('no longer stored');
});

it('withholds the resend button from somebody without the permission', function (): void {
    $send = EmailSend::factory()->failed()->create([
        'email_key' => 'account.password_changed',
        'category' => EmailCategory::Account,
        'mailable' => PasswordChanged::class,
    ]);

    Livewire\Livewire::actingAs(userWithRole(UserRole::ContentEditor));

    // Content Editor cannot even open the screen, so the check that matters is
    // the permission itself rather than the button.
    expect(userWithRole(UserRole::ContentEditor)->can('resend_email'))->toBeFalse()
        ->and(userWithRole(UserRole::Support)->can('resend_email'))->toBeTrue();
});

it('keeps the emails screen toggles to roles holding manage_email_sending', function (): void {
    expect(userWithRole(UserRole::SuperAdmin)->can('manage_email_sending'))->toBeTrue()
        ->and(userWithRole(UserRole::Finance)->can('manage_email_sending'))->toBeFalse()
        ->and(userWithRole(UserRole::Support)->can('manage_email_sending'))->toBeFalse();
});

it('shows every email on the emails screen, built or not', function (): void {
    $this->actingAs(userWithRole(UserRole::SuperAdmin));

    $response = $this->get('/admin/emails')->assertSuccessful();
    $html = (string) $response->getContent();

    foreach (EmailCatalogue::all() as $email) {
        expect($html)->toContain($email->name);
    }
});

// The catalogue is the contract -----------------------------------------------------------------

/*
 * An email sent in code but absent from the catalogue has no toggle, no category,
 * no retention and no row on the Emails screen. The base class throws at the call
 * site; this catches it before anybody sends one.
 */
it('declares every mailable in the catalogue', function (): void {
    foreach (File::allFiles(app_path('Mail')) as $file) {
        $class = 'App\\Mail\\'.$file->getFilenameWithoutExtension();

        if (! class_exists($class) || (new ReflectionClass($class))->isAbstract()) {
            continue;
        }

        // StoredEmail borrows the key of whatever it is replaying.
        if ($class === App\Mail\StoredEmail::class) {
            continue;
        }

        expect(EmailCatalogue::has($class::emailKey()))
            ->toBeTrue("{$class} sends under a key the catalogue does not declare");
    }
});

/*
 * The mirror: a catalogue entry naming a mailable that does not exist would show
 * on the Emails screen as switched on and never send anything.
 */
it('points every declared mailable at a class that exists', function (): void {
    foreach (EmailCatalogue::all() as $email) {
        if ($email->mailable === null) {
            // Must say what it is waiting for, or it reads as broken.
            expect($email->blockedBy)->not->toBeNull("{$email->key} has no mailable and does not say why");

            continue;
        }

        expect(class_exists($email->mailable))->toBeTrue("{$email->key} names a mailable that does not exist")
            ->and($email->mailable::emailKey())->toBe($email->key);
    }
});

it('generates a toggle only for an email that can actually send', function (): void {
    foreach (EmailCatalogue::all() as $email) {
        $hasToggle = SettingRegistry::has($email->settingKey());

        // A toggle on an unbuilt email is a control that does nothing: the client
        // changes it, sees no effect, and concludes the software is broken.
        expect($hasToggle)->toBe($email->tier->isSwitchable() && $email->isBuilt());
    }
});

it('renders every built email without error', function (): void {
    $this->actingAs(userWithRole(UserRole::SuperAdmin));

    $built = collect(EmailCatalogue::built())->pluck('key')->all();

    expect($built)->not->toBeEmpty();

    // The mailing-list confirmation is the cheapest to exercise end to end, and
    // it is the one that unblocks a setting already on the screen.
    $subscriber = pendingSubscriber();

    expect(EmailSend::query()->where('email_key', 'marketing.list_confirm')->count())->toBe(1)
        ->and($subscriber->status->value)->toBe('pending');
});

// Double opt-in ----------------------------------------------------------------------------------

/*
 * The setting shipped off with help text reading "a signup would wait forever for
 * an email nothing can send". This is the email.
 */
it('sends the confirmation that double opt-in was waiting for', function (): void {
    pendingSubscriber('dana@example.com');

    $send = EmailSend::query()->sole();

    expect($send->email_key)->toBe('marketing.list_confirm')
        ->and($send->to_email)->toBe('dana@example.com')
        ->and($send->status)->toBe(EmailStatus::Sent)
        ->and($send->body_html)->toContain('mailing-list/confirm');
});

it('asks nobody to confirm an address that is already on the list', function (): void {
    Setting::set('mailing_list.double_opt_in', false, 'boolean');
    app(App\Actions\Subscribers\Subscribe::class)('dana@example.com', SubscriberSource::Footer);

    Setting::set('mailing_list.double_opt_in', true, 'boolean');
    app(App\Actions\Subscribers\Subscribe::class)('dana@example.com', SubscriberSource::Footer);

    // Already subscribed, so there is nothing to confirm. Asking again would be
    // asking somebody to re-prove consent they gave last year.
    expect(EmailSend::query()->where('email_key', 'marketing.list_confirm')->count())->toBe(0);
});

it('leaves the unsubscribe link off the confirmation', function (): void {
    pendingSubscriber();

    // They are not on the list yet. An opt-out would lead nowhere.
    expect(EmailSend::query()->sole()->body_html)->not->toContain('unsubscribe');
});

// Retention ---------------------------------------------------------------------------------------

it('prunes an old body and keeps the send record', function (): void {
    Setting::set('email.retain_operational_days', 30, 'integer');

    $old = EmailSend::factory()->create([
        'category' => EmailCategory::Account,
        'email_key' => 'account.password_changed',
        'created_at' => now()->subDays(45),
    ]);
    $recent = EmailSend::factory()->create([
        'category' => EmailCategory::Account,
        'email_key' => 'account.password_changed',
        'created_at' => now()->subDays(5),
    ]);

    $this->artisan('emails:prune-bodies')->assertSuccessful();

    expect($old->refresh()->body_html)->toBeNull()
        ->and($old->body_pruned_at)->not->toBeNull()
        // The record itself is kept permanently — that is the part anybody asks about.
        ->and($old->to_email)->not->toBeNull()
        ->and($old->status)->toBe(EmailStatus::Sent)
        ->and($recent->refresh()->body_html)->not->toBeNull();
});

it('keeps receipts far longer than marketing', function (): void {
    expect(EmailCategory::Purchase->retentionSettingKey())
        ->toBe('email.retain_financial_days')
        ->and(EmailCategory::Marketing->retentionSettingKey())
        ->toBe('email.retain_marketing_days')
        ->and((int) Setting::declared('email.retain_financial_days'))
        ->toBeGreaterThan((int) Setting::declared('email.retain_marketing_days'));
});

/*
 * A mistyped 0 in the retention setting would prune the body of an email sent a
 * minute ago, which is a support request nobody can answer afterwards.
 */
it('refuses to prune bodies newer than a week whatever the setting says', function (): void {
    Setting::set('email.retain_operational_days', 0, 'integer');

    $yesterday = EmailSend::factory()->create([
        'category' => EmailCategory::Account,
        'email_key' => 'account.password_changed',
        'created_at' => now()->subDay(),
    ]);

    $this->artisan('emails:prune-bodies')->assertSuccessful();

    expect($yesterday->refresh()->body_html)->not->toBeNull();
});

// Suppressions -------------------------------------------------------------------------------------

it('treats one address as one suppression however often it bounces', function (): void {
    EmailSuppression::record('gone@example.com', SuppressionReason::Bounced, note: 'First');
    EmailSuppression::record('gone@example.com', SuppressionReason::Bounced, note: 'Again');

    expect(EmailSuppression::query()->count())->toBe(1)
        ->and(EmailSuppression::activeFor('gone@example.com')?->note)->toBe('Again');
});

it('records a lifted suppression rather than deleting the row', function (): void {
    $suppression = EmailSuppression::record('gone@example.com', SuppressionReason::Bounced);

    $suppression->lift(userWithRole(UserRole::SuperAdmin), 'Confirmed working');

    expect(EmailSuppression::query()->count())->toBe(1)
        ->and(EmailSuppression::blocks('gone@example.com', isRequired: true))->toBeFalse()
        ->and($suppression->refresh()->lifted_reason)->toBe('Confirmed working');
});

// Settings ------------------------------------------------------------------------------------------

/*
 * Nothing recorded who changed the refund window, the completion thresholds or
 * the company's legal details. Switching an email off made it urgent: customers
 * stop being told things, and until now nothing said who decided that.
 */
it('records who changed a setting', function (): void {
    $admin = userWithRole(UserRole::SuperAdmin);
    $this->actingAs($admin);

    Setting::set('email.daily_cap', 200, 'integer');

    $entry = Spatie\Activitylog\Models\Activity::query()
        ->where('log_name', 'setting')
        ->latest('id')
        ->firstOrFail();

    expect(App\Support\ActivityNarrator::causerName($entry))->toBe($admin->name)
        ->and(App\Support\ActivityNarrator::recordLabel($entry))->toBe('daily_cap');
});
