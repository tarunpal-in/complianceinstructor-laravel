{{--
    The stored email, rendered in a sandbox.

    `sandbox` with no `allow-scripts` and no `allow-same-origin` is the guard, and
    it is doing real work rather than being belt-and-braces. This is HTML that was
    assembled from admin-authored settings and customer-supplied values — names,
    company names, inquiry notes — and the settings audit found a stored XSS
    through exactly this shape of assumption: a value the form validated and the
    reader did not. An admin screen is a bad place to execute somebody's markup,
    and the person most likely to open this page is the one with the most
    permissions.

    `srcdoc` rather than a route serving the body, so there is no URL anywhere
    that renders stored email HTML on our own origin.
--}}
@php
    /** @var \App\Models\EmailSend $record */
    $record = $getRecord();
@endphp

<div class="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-white/10 dark:bg-white">
    <iframe
        title="The content of this email"
        sandbox
        referrerpolicy="no-referrer"
        srcdoc="{{ $record->body_html }}"
        class="h-[32rem] w-full border-0"
    ></iframe>
</div>

<p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
    Shown exactly as it was sent. Links and images are inert here.
</p>
