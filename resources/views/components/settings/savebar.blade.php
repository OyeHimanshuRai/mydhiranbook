{{-- Save bar for a form whose x-data is `stSaveForm()` (settings-pane.js): `dirty` and `saving`. Three states: clean ("No changes yet", Save
     disabled), dirty ("Unsaved changes", Save on), saving (spinner, "Saving…", disabled, a second submit is ignored). Sticky at the bottom up to
     1023px (safe-area aware), a normal row at the end of the form on desktop. The status text is a polite live region.
     lockClean=false keeps Save enabled on a clean form (Add staff: the defaults are already a valid save). --}}
@props(['label' => 'Save changes', 'cancelHref' => null, 'cancelLabel' => 'Cancel', 'icon' => 'save', 'lockClean' => true])
<div class="st-savebar">
    <p class="st-savestate" role="status" aria-live="polite" :class="{ 'is-dirty': dirty && !saving, 'is-saving': saving }">
        <span x-show="!dirty && !saving">No changes yet</span>
        <span x-show="dirty && !saving" x-cloak><x-icon name="circle-alert" size="16" /> Unsaved changes</span>
        <span x-show="saving" x-cloak>Saving…</span>
    </p>
    <div class="st-saveacts">
        @if($cancelHref)<a href="{{ $cancelHref }}" class="btn btn-secondary btn-sm" data-turbo-frame="_top">{{ $cancelLabel }}</a>@endif
        <button type="submit" class="btn btn-dark btn-sm" @if($lockClean) disabled @endif :disabled="saving @if($lockClean) || !dirty @endif" :aria-busy="saving">
            <x-icon :name="$icon" size="16" /> <span x-text="saving ? 'Saving…' : @js($label)">{{ $label }}</span>
        </button>
    </div>
</div>
