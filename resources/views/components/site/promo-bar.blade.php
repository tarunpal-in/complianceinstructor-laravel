@php
    use App\Models\Setting;

    $enabled = (bool) Setting::get('promo_bar.enabled', true);

    // Colours and the size go through validating accessors before reaching a
    // style attribute. Settings are admin-authored, but an admin is still an
    // untrusted source for something interpolated into CSS.
    $background = Setting::color('promo_bar.background_color', '#0c4d80');
    $color = Setting::color('promo_bar.text_color', '#ffffff');
    $fontSize = Setting::pixels('promo_bar.font_size', 14);

    $text = (string) Setting::get('promo_bar.text', 'Purchase any webinar and use code :code at checkout to save $10.');
    $code = trim((string) Setting::get('promo_bar.code', ''));
@endphp

@if ($enabled)
    {{-- CLAUDE.md §14 #3 bans serialising the coupon table into a page. One
         promoted code is marketing, not validation, and it is a setting rather
         than a query against `coupons`. --}}
    <div class="promo-bar" style="background: {{ $background }}; color: {{ $color }}; font-size: {{ $fontSize }}px">
        @if ($code !== '' && str_contains($text, ':code'))
            {{-- The placeholder is replaced with markup, so each half is escaped
                 separately rather than rendering the whole string unescaped. --}}
            @php [$before, $after] = explode(':code', $text, 2); @endphp
            {{ $before }}<strong>{{ $code }}</strong>{{ $after }}
        @else
            {{ str_replace(':code', $code, $text) }}
        @endif
    </div>
@endif
