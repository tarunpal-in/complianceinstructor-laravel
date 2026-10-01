<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\EmailCategory;
use App\Models\EmailSend;
use App\Models\Setting;
use Illuminate\Console\Command;

/**
 * Drops the stored content of old emails, keeping the record of the send.
 *
 * **Scheduled, unlike `activitylog:clean`.** The distinction is worth stating,
 * because the two look like the same question and are not. An activity row is
 * tiny, irreplaceable, and the audit trail §9 requires — deleting one is a
 * business decision about compliance. An email body is 20–60 KB, largely
 * reproducible from the template and the record it described, and the only thing
 * that pays for keeping it is the nightly database dump: at a realistic volume
 * this is gigabytes a year, multiplied by thirty days of backup retention. A
 * slow restore is the cost, and §11 requires a rehearsed restore.
 *
 * The row itself is never touched. Who was emailed what, when, and whether it
 * went is kept permanently — that is the part anybody actually asks about.
 */
class PruneEmailBodies extends Command
{
    protected $signature = 'emails:prune-bodies {--dry-run : Report what would be pruned without changing anything}';

    protected $description = 'Drop the stored content of emails past their retention period, keeping the send record';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $total = 0;

        foreach (self::windows() as $settingKey => $categories) {
            $days = self::days($settingKey);
            $cutoff = now()->subDays($days);

            $query = EmailSend::query()
                ->whereIn('category', array_map(
                    fn (EmailCategory $category): string => $category->value,
                    $categories,
                ))
                ->where('created_at', '<', $cutoff)
                ->whereNotNull('body_html');

            $count = $query->count();
            $total += $count;

            $this->line(sprintf(
                '%s: %d %s older than %d days.',
                $settingKey,
                $count,
                $dryRun ? 'would be pruned' : 'to prune',
                $days,
            ));

            if ($dryRun || $count === 0) {
                continue;
            }

            // A bulk update rather than a loop: there is no model event worth
            // firing here, and a year of sends is not something to hydrate.
            $query->update([
                'body_html' => null,
                'body_pruned_at' => now(),
            ]);
        }

        $this->info($dryRun
            ? "{$total} email bodies would be pruned."
            : "{$total} email bodies pruned. Every send record was kept.");

        return self::SUCCESS;
    }

    /**
     * Retention settings, and which categories each governs.
     *
     * Grouped by setting rather than by category so one pass handles each
     * window, and so the mapping stays the one EmailCategory already declares.
     *
     * @return array<string, array<int, EmailCategory>>
     */
    private static function windows(): array
    {
        $windows = [];

        foreach (EmailCategory::cases() as $category) {
            $windows[$category->retentionSettingKey()][] = $category;
        }

        return $windows;
    }

    private static function days(string $key): int
    {
        $value = Setting::declared($key);

        // A floor rather than trusting the setting: a mistyped 0 would prune
        // the body of an email sent a minute ago, which is a support request
        // nobody can answer afterwards.
        return max(7, is_numeric($value) ? (int) $value : 90);
    }
}
