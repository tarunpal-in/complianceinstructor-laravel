{{-- One "Webinar Categories" card. Shared by the grid and slider layouts.
     Expects: $category (array with name, count, slug?, image?/image_url?). --}}
@php $image = $category['image_url'] ?? asset('images/home/'.($category['image'] ?? 'seminar').'.jpg'); @endphp

{{-- The scrim is part of the background shorthand in the comp; it is what carries
     the white heading to AA contrast over photography. --}}
<article
    class="category-card"
    style="background-image: linear-gradient(0deg, rgba(5,39,72,.9), rgba(9,45,82,.05)), url('{{ $image }}')"
>
    <div>
        <small>{{ $category['count'] }} Courses</small>
        <h3>{{ $category['name'] }}</h3>
        {{-- A real link to the industry landing page when the card is a live
             industry; the placeholder fallback (no slug) keeps the notice so an
             unseeded install still renders. --}}
        @if (! empty($category['slug']))
            <a class="category-cta" href="{{ route('industries.show', $category['slug']) }}">View Courses</a>
        @else
            <button @click="showNotice('{{ $category['name'] }} courses opened')">View Courses</button>
        @endif
    </div>
</article>
