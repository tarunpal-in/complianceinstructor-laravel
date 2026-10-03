{{-- Prose. What every policy page is made of. --}}
<section class="section pb-block pb-text">
    @if ($heading = $block->value('heading'))
        <h2 class="pb-heading">{{ $heading }}</h2>
    @endif

    {{-- The editor's own markup, through the sanitiser.

         This used to be echoed raw, on the reasoning that "the toolbar is
         deliberately short, so what arrives here is a small, known set of tags".
         That describes what the editor *offers*. The column holds whatever was
         pasted into it, seeded into it, imported into it, or written to it by
         hand — which is exactly how the speaker social-URL XSS in §9's table was
         found: the form validated and the reader did not. --}}
    <div class="pb-prose">{!! \App\Support\Html::sanitize($block->value('body')) !!}</div>
</section>
