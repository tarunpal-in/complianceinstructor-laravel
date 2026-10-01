{{--
    The footer every email shares.

    Kept deliberately plain. The client sends through a consumer Gmail account —
    a free-domain sender with no DKIM for its own domain, mailing HR departments
    behind Mimecast and Proofpoint. A light template, few links and no images are
    among the only deliverability levers available without paying a provider, so
    this is a signature rather than a design.

    The unsubscribe link appears only for marketing that somebody may decline.
    Offering one on a receipt invites a customer to opt out of their own invoices
    and then ask where the invoice went.
--}}
@php
    $businessName = \App\Models\Setting::declared('business.name');
    $businessAddress = \App\Models\Setting::declared('business.address');
    $unsubscribeUrl = $unsubscribeUrl ?? null;
@endphp

<x-mail::subcopy>
{{ $businessName }}@if (filled($businessAddress)) · {{ str_replace("\n", ', ', trim((string) $businessAddress)) }}@endif

@if (($emailDefinition ?? null) && $emailDefinition->needsUnsubscribeLink() && filled($unsubscribeUrl))
You are receiving this because you joined our mailing list. [Unsubscribe]({{ $unsubscribeUrl }}).
@endif
</x-mail::subcopy>
