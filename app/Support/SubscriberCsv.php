<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Subscriber;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The mailing list as a CSV.
 *
 * Its own class rather than a method on the list page, for the same reason as
 * InquiryCsv: the columns are a contract with whatever receives the file, and a
 * contract belongs somewhere it can be read and tested.
 *
 * The consent columns are included deliberately. A mailing tool being handed
 * this list should be able to carry the date and the source across, because the
 * moment this file leaves the system it becomes the only record of why those
 * addresses are on a list — and "we exported it from the website" is not an
 * answer to a complaint.
 */
final class SubscriberCsv
{
    /** Rows are read in batches so a large export never holds the list in memory. */
    private const CHUNK = 500;

    /** @return array<int, string> */
    public static function headers(): array
    {
        return [
            'Email', 'Name', 'Status', 'Signed up via',
            'Subscribed at', 'Confirmed at', 'Consent IP',
            'Unsubscribed at', 'Unsubscribe reason', 'Has an account',
        ];
    }

    /** @return array<int, string|null> */
    public static function row(Subscriber $subscriber): array
    {
        return [
            $subscriber->email,
            $subscriber->name,
            $subscriber->status->label(),
            $subscriber->source->label(),
            $subscriber->subscribed_at?->format('Y-m-d H:i'),
            $subscriber->confirmed_at?->format('Y-m-d H:i'),
            $subscriber->consent_ip,
            $subscriber->unsubscribed_at?->format('Y-m-d H:i'),
            $subscriber->unsubscribe_reason,
            $subscriber->customer === null ? 'No' : 'Yes',
        ];
    }

    /**
     * Write the whole of a query out to an open handle.
     *
     * @param  resource  $handle
     * @param  Builder<Subscriber>  $query
     */
    public static function writeTo($handle, Builder $query): void
    {
        fputcsv($handle, self::headers());

        $query->with('customer')->chunk(self::CHUNK, function ($subscribers) use ($handle): void {
            /** @var Subscriber $subscriber */
            foreach ($subscribers as $subscriber) {
                fputcsv($handle, self::row($subscriber));
            }
        });
    }

    /** @param  Builder<Subscriber>  $query */
    public static function download(Builder $query): StreamedResponse
    {
        $name = 'subscribers-'.now()->format('Y-m-d-His').'.csv';

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
