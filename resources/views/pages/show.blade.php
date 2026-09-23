@extends('layouts.public')

@section('title', $page->title.' — '.config('app.name'))

@section('content')
    @if ($preview ?? false)
        {{--
            Loud on purpose. Somebody sent this link has no other way to tell a
            preview from the live site, and "I looked at it, it's fine" about a
            page that was never published is a conversation worth preventing.
        --}}
        {{-- Whole sentences per branch rather than a conditional mid-sentence:
             Blade's indentation lands inside the text otherwise, which reads
             fine in a browser and breaks anything matching on the string. --}}
        <div class="page-preview-banner" role="status">
            <strong>Preview</strong>
            <span>
                @if ($page->derivedStatus() === \App\Enums\PageStatus::Scheduled)
                    {{ 'This page is scheduled and goes live on '.$page->published_at?->format('j M Y').'.' }}
                @else
                    {{ 'This page has not been published.' }}
                @endif
                {{ 'This link stops working after '.\App\Models\Page::PREVIEW_LIFETIME_DAYS.' days.' }}
            </span>
        </div>
    @endif

    {{--
        A page the client assembled — CLAUDE.md §5.6.

        Each block resolves to a partial named after its type. An unknown type
        renders nothing rather than throwing: a block removed from the code must
        not take a live marketing page down with it, and the client discovering
        that through a 500 on a policy page is the worst way to find out.
    --}}
    <main class="page-blocks">
        @foreach ($page->visibleSections as $section)
            @includeIf('pages.blocks.'.$section->type, ['block' => $section, 'page' => $page])
        @endforeach
    </main>
@endsection
