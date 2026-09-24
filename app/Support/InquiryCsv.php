<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Inquiry;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The inquiry register as a CSV — §6's "inquiry export".
 *
 * Its own class rather than a method on the list page, for two reasons. The
 * columns are a contract with whoever receives the file — a mailing house, a
 * spreadsheet somebody has built formulas against — and a contract belongs
 * somewhere it can be read and tested, not inside a Filament action closure.
 * And when the Inbox grows its inquiries and subscribers screens, this is the
 * shape they copy.
 *
 * Streamed rather than queued. §4 rule 7 puts anything slow on a queue, and
 * that is about PDFs, mail and certificates; a few thousand rows of text that
 * the browser starts receiving immediately is not that, and a queued export
 * would mean building a downloads area to collect it from.
 */
final class InquiryCsv
{
    /** Rows are read in batches so a large export never holds the list in memory. */
    private const CHUNK = 200;

    /**
     * The column headings, in order.
     *
     * The message is included, because an export of an inbox that leaves out
     * what people wrote is a contact list, not a record of what was asked. The
     * derived Customer column is included for the same reason the screen shows
     * it: it is worked out from the orders table and is therefore right.
     *
     * @return array<int, string>
     */
    public static function headers(): array
    {
        return [
            'Name', 'Email', 'Company', 'Phone',
            'Arrived by', 'Subject', 'Message',
            'Status', 'Owner', 'Came in', 'Last contacted', 'Customer',
        ];
    }

    /** @return array<int, string|null> */
    public static function row(Inquiry $inquiry): array
    {
        return [
            $inquiry->name,
            $inquiry->email,
            $inquiry->company,
            $inquiry->phone,
            $inquiry->source->label(),
            $inquiry->subject,
            $inquiry->message,
            $inquiry->status->label(),
            $inquiry->assignedTo?->name,
            $inquiry->created_at?->format('Y-m-d H:i'),
            $inquiry->contacted_at?->format('Y-m-d H:i'),
            $inquiry->describeOutcome(),
        ];
    }

    /**
     * Write the whole of a query out to an open handle.
     *
     * Takes the query the screen is already showing, filters and tab included.
     * An export button that quietly ignored the filters is how the wrong list
     * gets emailed to a mailing house.
     *
     * @param  resource  $handle
     * @param  Builder<Inquiry>  $query
     */
    public static function writeTo($handle, Builder $query): void
    {
        fputcsv($handle, self::headers());

        $query->with(['assignedTo', 'customer'])
            ->chunk(self::CHUNK, function ($inquiries) use ($handle): void {
                /** @var Inquiry $inquiry */
                foreach ($inquiries as $inquiry) {
                    fputcsv($handle, self::row($inquiry));
                }
            });
    }

    /** @param  Builder<Inquiry>  $query */
    public static function download(Builder $query): StreamedResponse
    {
        $name = 'inquiries-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($query): void {
            $handle = fopen('php://output', 'wb');

            if ($handle === false) {
                return;
            }

            self::writeTo($handle, $query);

            fclose($handle);
        }, $name, ['Content-Type' => 'text/csv']);
    }
}
