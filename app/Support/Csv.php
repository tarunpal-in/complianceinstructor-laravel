<?php

declare(strict_types=1);

namespace App\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Writing a CSV out to the browser.
 *
 * The fourth export in this codebase asked for the same twelve lines as the
 * first three, so they live here now: open a handle, write a header row, write
 * the rows, stream it. InquiryCsv, SubscriberCsv and OrderCsv keep their own
 * classes because the *columns* are the part worth reading and testing, and
 * that is theirs. This is only the plumbing.
 *
 * Streamed rather than queued. §4 rule 7 puts anything slow on a queue, and
 * that means PDFs, mail and certificates — not a text file the browser starts
 * receiving immediately. A queued export would mean building somewhere to
 * collect it from, which is a feature nobody asked for.
 */
final class Csv
{
    /**
     * @param  array<int, string>  $headers
     * @param  iterable<int, array<int, string|int|null>>  $rows
     * @param  resource  $handle
     */
    public static function writeTo($handle, array $headers, iterable $rows): void
    {
        fputcsv($handle, $headers);

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
    }

    /**
     * @param  array<int, string>  $headers
     * @param  callable(): iterable<int, array<int, string|int|null>>  $rows
     */
    public static function download(string $filename, array $headers, callable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows): void {
            $handle = fopen('php://output', 'wb');

            if ($handle === false) {
                return;
            }

            self::writeTo($handle, $headers, $rows());

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /** A dated filename, so two exports of the same thing do not overwrite. */
    public static function filename(string $prefix): string
    {
        return $prefix.'-'.Period::now()->format('Y-m-d-His').'.csv';
    }

    /**
     * Cents as a decimal string.
     *
     * A string, never a float: `27900 / 100` is fine until it is not, and §4
     * rule 6 does not stop being true because the number is on its way into a
     * spreadsheet. Excel reads "279.00" as a number anyway.
     */
    public static function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
