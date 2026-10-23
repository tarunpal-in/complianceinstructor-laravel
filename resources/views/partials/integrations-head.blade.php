{{--
    Third-party tags that belong in <head> — client SEO brief §14.

    Rendered only when the client has set them in Settings → Integrations, and
    loaded the way each vendor recommends: tags load async so they do not block
    the page (brief §9's Core Web Vitals — a tag that blocks rendering is the
    commonest way an analytics tool wrecks a score). GTM and GA4 are mutually
    exclusive by design (App\Support\Integrations), so page views are never
    double-counted; Google Ads shares the one gtag.js load with GA4.

    Every id interpolated below is validated by App\Support\Integrations before it
    reaches here, so none can carry markup onto the page.
--}}
@php
    use App\Support\Integrations;
    $gtm = Integrations::gtmId();
    $ga4 = Integrations::ga4Id();
    $googleAds = Integrations::googleAdsId();
    $gsc = Integrations::searchConsoleCode();
    $fbPixel = Integrations::facebookPixelId();
    $linkedIn = Integrations::linkedInPartnerId();
    $recaptcha = Integrations::recaptchaSiteKey();
    // GA4 and Google Ads both run on gtag.js — load it once and config each.
    $gtagIds = array_values(array_filter([$ga4, $googleAds]));
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
@elseif (! empty($gtagIds))
    {{-- Google tag (gtag.js) — GA4 and/or Google Ads, loaded once when not via GTM --}}
    <script async src="https://www.googletagmanager.com/gtag/js?id={{ $gtagIds[0] }}"></script>
    <script>
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);}
        gtag('js', new Date());
        @foreach ($gtagIds as $gtagId)
            gtag('config', '{{ $gtagId }}');
        @endforeach
    </script>
@endif

@if ($fbPixel)
    {{-- Meta (Facebook) Pixel --}}
    <script>
        !function(f,b,e,v,n,t,s)
        {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
        n.callMethod.apply(n,arguments):n.queue.push(arguments)};
        if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
        n.queue=[];t=b.createElement(e);t.async=!0;
        t.src=v;s=b.getElementsByTagName(e)[0];
        s.parentNode.insertBefore(t,s)}(window,document,'script',
        'https://connect.facebook.net/en_US/fbevents.js');
        fbq('init', '{{ $fbPixel }}');
        fbq('track', 'PageView');
    </script>
@endif

@if ($linkedIn)
    {{-- LinkedIn Insight Tag --}}
    <script>
        _linkedin_partner_id = "{{ $linkedIn }}";
        window._linkedin_data_partner_ids = window._linkedin_data_partner_ids || [];
        window._linkedin_data_partner_ids.push(_linkedin_partner_id);
        (function(l){
            if (!l){window.lintrk = function(a,b){window.lintrk.q.push([a,b])};window.lintrk.q=[];}
            var s = document.getElementsByTagName("script")[0];
            var b = document.createElement("script");
            b.type = "text/javascript";b.async = true;
            b.src = "https://snap.licdn.com/li.lms-analytics/insight.min.js";
            s.parentNode.insertBefore(b, s);
        })(window.lintrk);
    </script>
@endif

@if ($recaptcha)
    {{-- Google reCAPTCHA v3 — a token is executed per protected form --}}
    <script src="https://www.google.com/recaptcha/api.js?render={{ $recaptcha }}"></script>
@endif
