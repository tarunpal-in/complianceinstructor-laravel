{{--
    Third-party tags that belong at the end of <body> — client SEO brief §14.

    The GTM <noscript> fallback (Google requires it immediately after the opening
    body, but right before the close is the pragmatic place that still counts
    no-script visitors), and the Tawk.to live-chat widget, loaded async so the
    chat bubble never delays the page (brief §9). A chat widget has a real
    Core-Web-Vitals cost; loading it last and async keeps it off the critical path.
--}}
@php
    use App\Support\Integrations;
    $gtm = Integrations::gtmId();
    $tawk = Integrations::tawkToId();
    $fbPixel = Integrations::facebookPixelId();
    $linkedIn = Integrations::linkedInPartnerId();
@endphp

@if ($gtm)
    <noscript><iframe src="https://www.googletagmanager.com/ns.html?id={{ $gtm }}"
        height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
@endif

@if ($fbPixel)
    {{-- Meta Pixel no-script fallback --}}
    <noscript><img height="1" width="1" style="display:none"
        src="https://www.facebook.com/tr?id={{ $fbPixel }}&ev=PageView&noscript=1" alt=""></noscript>
@endif

@if ($linkedIn)
    {{-- LinkedIn Insight Tag no-script fallback --}}
    <noscript><img height="1" width="1" style="display:none;"
        src="https://px.ads.linkedin.com/collect/?pid={{ $linkedIn }}&fmt=gif" alt=""></noscript>
@endif

@if ($tawk)
    {{-- Tawk.to live chat --}}
    <script>
        var Tawk_API=Tawk_API||{}, Tawk_LoadStart=new Date();
        (function(){
            var s1=document.createElement("script"),s0=document.getElementsByTagName("script")[0];
            s1.async=true;
            s1.src='https://embed.tawk.to/{{ $tawk }}';
            s1.charset='UTF-8';
            s1.setAttribute('crossorigin','*');
            s0.parentNode.insertBefore(s1,s0);
        })();
    </script>
@endif
