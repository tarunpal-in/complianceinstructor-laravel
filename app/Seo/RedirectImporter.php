<?php

declare(strict_types=1);

namespace App\Seo;

use App\Models\Redirect;
use Illuminate\Support\Str;

/**
 * Bulk-loads the old site's URLs as redirects — client SEO brief §4, the
 * migration's single biggest SEO task.
 *
 * A separate class from the Filament action so the parsing and the guards can be
 * tested without a browser. Every row runs the same checks the manual form does
 * — a duplicate, a chain, a loop, or a source that shadows a live page is
 * skipped with a reason rather than silently creating something that breaks the
 * site. Redirecting everything to the homepage (the brief's explicit "avoid") is
 * left to the person preparing the CSV; what this guarantees is that whatever
 * they load is internally consistent.
 */
final class RedirectImporter
{
    /**
     * @return array{imported: int, skipped: int, problems: array<int, string>}
     */
    public static function fromFile(string $path): array
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return ['imported' => 0, 'skipped' => 0, 'problems' => ['The file could not be read.']];
        }

        $imported = 0;
        $skipped = 0;
        $problems = [];
        $line = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $line++;

            // Skip a header row if present (first column is literally "from").
            if ($line === 1 && isset($row[0]) && Str::startsWith(Str::lower(trim((string) $row[0])), 'from')) {
                continue;
            }

            $from = trim((string) ($row[0] ?? ''));
            $to = trim((string) ($row[1] ?? ''));
            $status = (int) ($row[2] ?? 301);

            if ($from === '' || $to === '') {
                continue; // a blank line
            }

            $problem = self::problemWithRow($from, $to);

            if ($problem !== null) {
                $skipped++;
                if (count($problems) < 20) {
                    $problems[] = "Line {$line} ({$from}): {$problem}";
                }

                continue;
            }

            Redirect::query()->updateOrCreate(
                ['from_path' => Redirect::normalise($from)],
                [
                    'to_path' => Redirect::normaliseTarget($to),
                    'status_code' => in_array($status, [301, 302], true) ? $status : 301,
                    'is_active' => true,
                    'source' => Redirect::SOURCE_IMPORT,
                ],
            );

            $imported++;
        }

        fclose($handle);

        return ['imported' => $imported, 'skipped' => $skipped, 'problems' => $problems];
    }

    /** Why a row cannot be imported, or null if it is safe. */
    private static function problemWithRow(string $from, string $to): ?string
    {
        $normFrom = Redirect::normalise($from);

        if (Redirect::shadowsLiveContent($normFrom)) {
            return 'its path shows a live page';
        }

        return Redirect::problemWith($normFrom, $to);
    }
}
