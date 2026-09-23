@extends('layouts.public')

@section('title', $page->title.' — '.config('app.name'))

@section('content')
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
