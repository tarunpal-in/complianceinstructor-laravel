@props(['action' => 'submit'])

{{--
    reCAPTCHA v3 token for the form this sits inside. Renders only when the client
    has set a site key (Settings → Integrations); otherwise nothing, and the form
    submits as before. Drop <x-recaptcha-token action="name"/> inside any plain
    POST form and pair it with the App\Rules\Recaptcha('name') rule on the server.
--}}
@php($recaptchaSiteKey = \App\Support\Integrations::recaptchaSiteKey())

@if ($recaptchaSiteKey)
    <input type="hidden" name="recaptcha_token" value="">
    <script>
        (function () {
            var form = document.currentScript.closest('form');
            if (! form) { return; }

            var field = form.querySelector('input[name="recaptcha_token"]');

            function refresh() {
                if (typeof grecaptcha === 'undefined') { return; }
                grecaptcha.ready(function () {
                    grecaptcha.execute(@json($recaptchaSiteKey), { action: @json($action) })
                        .then(function (token) { field.value = token; });
                });
            }

            // A v3 token lasts about two minutes, so fetch one now and refresh it
            // before it expires — the field always holds a fresh token at submit.
            refresh();
            setInterval(refresh, 110000);
        })();
    </script>
@endif
