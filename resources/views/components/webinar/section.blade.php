@props(['id', 'heading', 'body'])

{{-- scroll-margin-top in CSS keeps the heading clear of the sticky tab bar when
     an anchor is followed; without it the tab bar covers what you jumped to. --}}
<section class="wd-section" id="{{ $id }}" tabindex="-1">
    <h2>{{ $heading }}</h2>

    {{-- Paragraph-per-blank-line, escaped. The client types plain text into a
         textarea; a rich-text editor lands with the page builder at P6, and
         until then nothing here may render markup from the database. --}}
    @foreach (preg_split('/\R{2,}/', trim($body)) as $paragraph)
        <p>{{ trim($paragraph) }}</p>
    @endforeach
</section>
