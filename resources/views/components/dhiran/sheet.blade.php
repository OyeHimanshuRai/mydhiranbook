{{-- A money or confirm dialog: a centered dialog from 768px, a full-height sheet on phones (CSS: dhiran-detail.css, .dd-sheet). The native
     <dialog> gives the focus trap, Esc and the scrim. The form posts with a fresh idempotency key; Turbo disables the submit button while it
     sends and shows `busy` on it. Slot = the fields. Open with document.getElementById(id).showModal().
     <x-dhiran.sheet id="repayModal" title="Repay Loan" :sub="$ref" :action="route(...)" submit="Submit Payment"> ...fields... </x-dhiran.sheet> --}}
@props(['id', 'title', 'sub' => null, 'action', 'submit', 'cancel' => 'Cancel', 'variant' => 'dark', 'busy' => 'Saving…'])
@once
    @vite(['resources/css/dhiran-detail.css'])
@endonce
<dialog id="{{ $id }}" class="dh-dialog dd-sheet" aria-labelledby="{{ $id }}-t">
    <form method="POST" action="{{ $action }}" data-turbo-frame="_top">
        @csrf <x-dhiran.once />
        <div class="dd-sheet-head">
            <div class="min-w-0">
                <h3 id="{{ $id }}-t">{{ $title }}</h3>
                @if($sub)<p>{{ $sub }}</p>@endif
            </div>
            <button type="button" class="dd-sheet-x" aria-label="Close" onclick="this.closest('dialog').close()"><x-icon name="x" /></button>
        </div>
        <div class="dd-sheet-body">{{ $slot }}</div>
        <div class="dd-sheet-foot">
            <button type="button" class="btn btn-secondary btn-sm" onclick="this.closest('dialog').close()">{{ $cancel }}</button>
            <button type="submit" class="btn btn-{{ $variant }} btn-sm" data-turbo-submits-with="{{ $busy }}">{{ $submit }}</button>
        </div>
    </form>
</dialog>
