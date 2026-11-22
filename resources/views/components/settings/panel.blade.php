{{-- A settings card: one heading, one line of description, then the fields. Same padding everywhere (--st-pad). req: the panel is one required choice (a radio group), so its heading takes the star. --}}
@props(['title', 'desc' => null, 'req' => false])
@php $pid = 'st-' . \Illuminate\Support\Str::slug($title); @endphp
<section class="st-panel" aria-labelledby="{{ $pid }}">
    <header class="st-panel-head">
        <h2 class="st-title" id="{{ $pid }}">{{ $title }}@if($req) <x-req />@endif</h2>
        @if($desc)<p class="st-desc">{{ $desc }}</p>@endif
    </header>
    @if(trim((string) $slot) !== '')<div class="st-panel-body">{{ $slot }}</div>@endif
</section>
