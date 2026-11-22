{{-- One labelled settings control. Wires label[for], hint and error via aria-describedby, marks aria-invalid, and shows a unit
     (prefix = leading, suffix = trailing) so the label stays short. inr: live "₹1,00,000" read-back under a rupee amount.
     type="select" takes the <option>s as the slot. Extra attributes (min, max, step, inputmode, maxlength...) go to the control.
     Server value $value; errors come from the validation bag by $name. --}}
@props(['name', 'label', 'type' => 'text', 'value' => null, 'hint' => null, 'prefix' => null, 'suffix' => null, 'inr' => false, 'wide' => false, 'optional' => false, 'id' => null])
@php
    $id ??= 'f-' . trim(preg_replace('/[^a-z0-9]+/i', '-', $name), '-');
    $error = $errors->first($name);
    $described = trim(($hint || $inr ? $id . '-hint ' : '') . ($error ? $id . '-err' : ''));
    $initial = is_numeric($value) ? (float) $value : null;
@endphp
<div class="st-field{{ $wide ? ' st-wide' : '' }}" @if($inr) x-data="{ n: @js($value === null ? '' : (string) $value) }" @endif>
    <label class="st-label" for="{{ $id }}">{{ $label }}@if($optional) <span class="st-opt">(optional)</span>@endif</label>
    @php
        $attrs = $attributes->merge(['id' => $id, 'name' => $name, 'class' => 'st-control' . ($error ? ' is-invalid' : ''), 'aria-describedby' => $described ?: null, 'aria-invalid' => $error ? 'true' : null]);
    @endphp
    @if($prefix || $suffix)<div class="st-affix{{ $error ? ' is-invalid' : '' }}">@endif
        @if($prefix)<span class="st-unit st-unit--pre" aria-hidden="true">{{ $prefix }}</span>@endif
        @if($type === 'select')
            <select {{ $attrs }}>{{ $slot }}</select>
        @else
            <input type="{{ $type }}" value="{{ $value }}" @if($inr) x-model="n" @endif {{ $attrs }}>
        @endif
        @if($suffix)<span class="st-unit st-unit--suf" aria-hidden="true">{{ $suffix }}</span>@endif
    @if($prefix || $suffix)</div>@endif
    @if($hint || $inr)
        <p class="st-hint" id="{{ $id }}-hint">
            @if($inr)<strong class="st-inr" x-text="n === '' || isNaN(+n) ? '' : '₹' + (+n).toLocaleString('en-IN', { maximumFractionDigits: 2 })">{{ $initial === null ? '' : \App\Support\IndianNumber::rupee($initial, fmod($initial, 1) ? 2 : 0) }}</strong>@endif
            {{ $hint }}
        </p>
    @endif
    @if($error)<p class="st-error" id="{{ $id }}-err"><x-icon name="circle-alert" size="16" /><span>{{ $error }}</span></p>@endif
</div>
