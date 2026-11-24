{{-- The bell in the header band: opens the notices card (x-dhiran.notice-card in the layout). Count = lines that need attention. --}}
@php($__n = \App\Support\NoticeView::count())
@if($__n !== null)
    <button type="button" class="dh-bell" data-notices-open aria-label="Notifications{{ $__n ? ', '.$__n.' to look at' : ', all clear' }}" aria-haspopup="dialog">
        <x-icon name="bell" />
        @if($__n)<span class="dh-bell-n" aria-hidden="true">{{ $__n }}</span>@endif
    </button>
@endif
