<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Lead;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The lead register as a CSV — §6's "lead export".
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
final class LeadCsv
{
    /** Rows are read in batches so a large export never holds the list in memory. */
    private const CHUNK = 200;

    /**
     * The column headings, in order.
     *
     * The derived Outcome is included deliberately: the whole point of working
     * it out from the orders table is that it is right, and an export that left
     * it out would send somebody away with a list that cannot say which of
     * these people already bought.
     *
     * @return array<int, string>
     */
    public static function headers(): array
    {
        return [
            'Name', 'Email', 'Company', 'Job title', 'Phone', 'Industry',
            'Source', 'Which one', 'Status', 'Owner', 'Agreed to email',
            'Campaign source', 'Campaign medium', 'Campaign name',
            'Came in', 'Last spoken to', 'Outcome',
        ];
    }

    /** @return array<int, string|null> */
    public static function row(Lead $lead): array
    {
        return [
            $lead->name,
            $lead->email,
            $lead->company,
            $lead->job_title,
            $lead->phone,
            $lead->industry?->name,
            $lead->source->label(),
            $lead->source_detail,
            $lead->status->label(),
            $lead->assignedTo?->name,
            $lead->marketing_opt_in ? 'Yes' : 'No',
            $lead->utm_source,
            $lead->utm_medium,
            $lead->utm_campaign,
            $lead->created_at?->format('Y-m-d H:i'),
            $lead->contacted_at?->format('Y-m-d H:i'),
            $lead->describeOutcome(),
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
     * @param  Builder<Lead>  $query
     */
    public static function writeTo($handle, Builder $query): void
    {
        fputcsv($handle, self::headers());

        $query->with(['industry', 'assignedTo', 'customer'])
            ->chunk(self::CHUNK, function ($leads) use ($handle): void {
                /** @var Lead $lead */
                foreach ($leads as $lead) {
                    fputcsv($handle, self::row($lead));
                }
            });
    }

    /** @param  Builder<Lead>  $query */
    public static function download(Builder $query): StreamedResponse
    {
        $name = 'leads-'.now()->format('Y-m-d-His').'.csv';

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
