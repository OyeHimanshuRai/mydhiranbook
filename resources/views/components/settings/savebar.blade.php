{{-- Save bar for a form whose x-data holds `dirty` and `saving` (see x-settings.form-state). Sticky at the bottom up to
     1023px (safe-area aware), a normal row at the end of the form on desktop. The status text is a polite live region. --}}
@props(['label' => 'Save changes', 'cancelHref' => null, 'cancelLabel' => 'Cancel', 'icon' => 'save'])
<div class="st-savebar">
    <p class="st-savestate" role="status" aria-live="polite"><span x-show="dirty" x-cloak><x-icon name="circle-alert" size="16" /> Unsaved changes</span></p>
    <div class="st-saveacts">
        @if($cancelHref)<a href="{{ $cancelHref }}" class="btn btn-secondary btn-sm" data-turbo-frame="_top">{{ $cancelLabel }}</a>@endif
        <button type="submit" class="btn btn-dark btn-sm" :disabled="saving" data-busy="Saving...">
            <x-icon :name="$icon" size="16" /> <span x-text="saving ? 'Saving...' : @js($label)">{{ $label }}</span>
        </button>
    </div>
</div>
