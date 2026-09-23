{{-- Prose. What every policy page is made of. --}}
<section class="section pb-block pb-text">
    @if ($heading = $block->value('heading'))
        <h2 class="pb-heading">{{ $heading }}</h2>
    @endif

    {{-- The editor's own markup, which is why it is not escaped. The toolbar is
         deliberately short (see PageBlocks) so what arrives here is a small,
         known set of tags rather than whatever was pasted out of Word. --}}
    <div class="pb-prose">{!! $block->value('body') !!}</div>
</section>
