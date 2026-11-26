{{-- The bell in the header band: opens the notices card (x-dhiran.notice-card in the layout). Count = lines that need attention. --}}
@php($__n = \App\Support\NoticeView::count())
{{-- Phones: the palette's search button lives here in the header (CSS hides it from 768px, where the floating pill shows). --}}
@if(auth()->user()?->shop_id)
    <button type="button" class="dh-bell dh-search-btn" data-palette-open aria-haspopup="dialog" aria-label="Search"><x-icon name="search" /></button>
@endif
@if($__n !== null)
    <button type="button" class="dh-bell" data-notices-open aria-label="Notifications{{ $__n ? ', '.$__n.' to look at' : ', all clear' }}" aria-haspopup="dialog">
        <x-icon name="bell" />
        @if($__n)<span class="dh-bell-n" aria-hidden="true">{{ $__n }}</span>@endif
    </button>
@endif
