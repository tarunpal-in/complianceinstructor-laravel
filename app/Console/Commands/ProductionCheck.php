<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Refuses a deploy that would go live misconfigured.
 *
 * §9's last row asks for a "production config check asserts debug is off", and
 * §11 puts Pint, Larastan and Pest in the pipeline. This belongs beside them,
 * and it is the only one of the four that catches a mistake *in the environment*
 * rather than in the code — which is the category the predecessor actually
 * shipped: live credentials in a committed `.env`, with `APP_DEBUG=true`.
 *
 * Every check is something that is silent when wrong. That is the selection
 * rule: a broken route announces itself on the first page load, whereas
 * `MAIL_MAILER=log` looks exactly like a working site right up until a customer
 * asks where their certificate went.
 *
 * Run it as `php artisan app:production-check` in the deploy, after the
 * environment is in place and before traffic is switched over. A non-zero exit
 * fails the deploy.
 */
class ProductionCheck extends Command
{
    protected $signature = 'app:production-check
        {--pretend-production : Run the production checks outside production, for testing this command}';

    protected $description = 'Assert the environment is safe to serve production traffic';

    /** @var array<int, string> */
    private array $failures = [];

    /** @var array<int, string> */
    private array $warnings = [];

    public function handle(): int
    {
        $production = $this->option('pretend-production') || app()->isProduction();

        if (! $production) {
            $this->components->info('Not production, so only the checks that apply everywhere ran.');
        }

        // Universal: §9 asks CI to fail on a committed secret, and this is the
        // check that does it. Needs no environment of its own.
        $this->checkGitIgnoresEnv();

        if ($production) {
            $this->checkAppKey();
            $this->checkDebugIsOff();
            $this->checkUrlIsHttps();
            $this->checkMailIsReal();
            $this->checkQueueIsNotSync();
            $this->checkDriversArePersistent();
        }

        foreach ($this->warnings as $warning) {
            $this->components->warn($warning);
        }

        if ($this->failures !== []) {
            foreach ($this->failures as $failure) {
                $this->components->error($failure);
            }

            $this->newLine();
            $this->components->error(count($this->failures).' problem(s) must be fixed before this serves traffic.');

            return self::FAILURE;
        }

        $this->components->info('Environment looks safe to serve.');

        return self::SUCCESS;
    }

    /**
     * §9's named finding. A debug page prints the stack trace, the loaded
     * configuration and the environment — which is the database password.
     */
    private function checkDebugIsOff(): void
    {
        if (config('app.debug') === true) {
            $this->failures[] = 'APP_DEBUG is true. A debug page prints your environment, including database credentials, to whoever triggers an error.';
        }
    }

    private function checkAppKey(): void
    {
        if (blank(config('app.key'))) {
            // Everything encrypted with the old key becomes unreadable if this
            // is generated later — including the two-factor secrets.
            $this->failures[] = 'APP_KEY is empty. Sessions, cookies and the two-factor secrets all depend on it.';
        }
    }

    private function checkUrlIsHttps(): void
    {
        $url = (string) config('app.url');

        if (! Str::startsWith($url, 'https://')) {
            // Signed URLs, password-reset links and everything in an email are
            // built from this. http:// means they all go out as http://.
            $this->failures[] = "APP_URL is [{$url}]. Every link in every email and every signed download URL is built from it.";
        }
    }

    /**
     * The one this project would have shipped.
     *
     * `log` and `array` both accept a message and throw it away. With the email
     * log in place that is worse than it sounds: the row says "Handed to Gmail"
     * and nothing was handed to anybody.
     */
    private function checkMailIsReal(): void
    {
        $mailer = (string) config('mail.default');

        if (in_array($mailer, ['log', 'array', 'null'], true)) {
            $this->failures[] = "MAIL_MAILER is [{$mailer}], which discards every message. The email log would show them as sent.";

            return;
        }

        if ($mailer === 'smtp' && blank(config('mail.mailers.smtp.username'))) {
            $this->failures[] = 'MAIL_MAILER is smtp but no username is set, so every send will fail authentication.';
        }
    }

    /** §4 rule 7 — anything slow is queued. `sync` runs it inside the request. */
    private function checkQueueIsNotSync(): void
    {
        if (config('queue.default') === 'sync') {
            $this->failures[] = 'QUEUE_CONNECTION is sync, so PDFs, mail and certificates run inside the web request (§4 rule 7).';
        }
    }

    private function checkDriversArePersistent(): void
    {
        foreach (['session.driver' => 'SESSION_DRIVER', 'cache.default' => 'CACHE_STORE'] as $key => $env) {
            if (config($key) === 'array') {
                // Not fatal — the site works, badly — so a warning. An `array`
                // session means nobody stays logged in between requests.
                $this->warnings[] = "{$env} is array, which keeps nothing between requests.";
            }
        }
    }

    /**
     * §9: "`.env` git-ignored from the first commit".
     *
     * Checked here rather than left to code review, because the predecessor's
     * was committed *with live credentials in it* and nobody noticed for the
     * life of the project. Skipped where git is unavailable, which is the normal
     * case on a release server.
     */
    private function checkGitIgnoresEnv(): void
    {
        if (! is_dir(base_path('.git'))) {
            return;
        }

        $process = new Process(['git', 'ls-files', '--error-unmatch', '.env'], base_path());
        $process->run();

        if ($process->isSuccessful()) {
            $this->failures[] = '.env is tracked by git. Remove it from the index and rotate every secret in it — assume they are public.';
        }
    }
}
