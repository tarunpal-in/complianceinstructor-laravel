{{--
    Third-party tags that belong in <head> — client SEO brief §14.

    Rendered only when the client has set them in Settings → Integrations, and
    loaded the way each vendor recommends: Tag Manager and GA4 async so they do
    not block the page (brief §9's Core Web Vitals — a tag that blocks rendering
    is the commonest way an analytics tool wrecks a score). GTM and GA4 are
    mutually exclusive by design, enforced in App\Support\Integrations, so page
    views are never double-counted.
--}}
@php
    use App\Support\Integrations;
    $gtm = Integrations::gtmId();
    $ga4 = Integrations::ga4Id();
    $gsc = Integrations::searchConsoleCode();
@endphp

@if ($gsc)
    <meta name="google-site-verification" content="{{ $gsc }}">
@endif

@if ($gtm)
    {{-- Google Tag Manager --}}
    <script>
        (function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});
        var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;
        j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
        })(window,document,'script','dataLayer','{{ $gtm }}');
    </script>
@elseif ($ga4)
    {{-- Google Analytics 4 (gtag.js), only when not loaded through Tag Manager --}}
    <script async src="https://www.googletagmanager.com/gtag/js?id={{ $ga4 }}"></script>
    <script>
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);}
        gtag('js', new Date());
        gtag('config', '{{ $ga4 }}');
    </script>
@endif
