@props(['name' => null, 'value' => null, 'label', 'hint' => null, 'checked' => false, 'locked' => false, 'id'])
{{-- Reusable on/off switch row (CSS: ui-switch in dhiran-receipt-settings.css, loaded by dhiran-settings.css in the layout head). The whole row is the tap target.
     <x-dhiran.switch id="x" name="show[]" value="a.b" label="Shop name" hint="One line in plain words" :checked="true" />
     `locked`: always on, cannot be changed, no name so it is never posted; stays focusable and is announced as unavailable. --}}
<label class="ui-switch-row{{ $locked ? ' is-locked' : '' }}" for="{{ $id }}">
    <span class="ui-switch-text">
        <span class="ui-switch-label" id="{{ $id }}-l">{{ $label }}</span>
        @if($hint)<span class="ui-switch-hint" id="{{ $id }}-h">{{ $hint }}</span>@endif
    </span>
    <span class="ui-switch">
        <input type="checkbox" role="switch" id="{{ $id }}" class="ui-switch-input"
               @if(! $locked && $name) name="{{ $name }}" value="{{ $value }}" @endif
               @checked($locked || $checked)
               @if($locked) aria-disabled="true" onclick="return false" @endif
               aria-labelledby="{{ $id }}-l" @if($hint) aria-describedby="{{ $id }}-h" @endif>
        <span class="ui-switch-track" aria-hidden="true"><span class="ui-switch-thumb"></span></span>
    </span>
</label>
