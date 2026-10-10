<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Trailing-slash redirect
    |--------------------------------------------------------------------------
    |
    | The agreed canonical URL form ends in a slash, matching the live WordPress
    | site so the migration triggers no mass 301s (client SEO brief §7). The
    | NormalizeUrl middleware 301s the bare form to it.
    |
    | This is off in the test HTTP client only. Laravel's test client strips
    | trailing slashes from the URLs it requests (MakesHttpRequests::
    | prepareUrlForRequest), so with the redirect on, every page request in a
    | test would bounce to a URL the client immediately strips again — an
    | infinite redirect. The middleware's redirect is unit-tested directly
    | instead, with a crafted request that keeps its slash.
    |
    */

    'trailing_slash_redirect' => env('SEO_TRAILING_SLASH_REDIRECT', true),

];
