{{-- A settings card: one heading, one line of description, then the fields. Same padding everywhere (--st-pad). --}}
@props(['title', 'desc' => null])
@php $pid = 'st-' . \Illuminate\Support\Str::slug($title); @endphp
<section class="st-panel" aria-labelledby="{{ $pid }}">
    <header class="st-panel-head">
        <h2 class="st-title" id="{{ $pid }}">{{ $title }}</h2>
        @if($desc)<p class="st-desc">{{ $desc }}</p>@endif
    </header>
    @if(trim((string) $slot) !== '')<div class="st-panel-body">{{ $slot }}</div>@endif
</section>
