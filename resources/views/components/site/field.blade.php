{{-- One labelled field: label/for, hint and inline error wired with aria-describedby. Extra attributes land on the <input>.
     req: the server requires it, so the label gets the star and the input aria-required (native `required` stays the caller's choice). --}}
@props(['name', 'label', 'type' => 'text', 'hint' => null, 'value' => null, 'password' => false, 'wide' => false, 'req' => false])
@php($err = $errors->first($name))
@php($desc = trim(($hint ? $name.'-hint ' : '').($err ? $name.'-err' : '')))
<div class="ui-field{{ $err ? ' has-err' : '' }}{{ $wide ? ' ui-field--wide' : '' }}">
<div class="ui-field-top"><label for="{{ $name }}">{{ $label }}@if($req) <x-req />@endif</label>{{ $aside ?? '' }}</div>
<div class="ui-input"><input id="{{ $name }}" name="{{ $name }}" type="{{ $password ? 'password' : $type }}" @if($value !== null) value="{{ $value }}" @endif {{ $attributes }} @if($req) aria-required="true" @endif @if($err) aria-invalid="true" @endif @if($desc) aria-describedby="{{ $desc }}" @endif>@if($password)<button type="button" class="ui-reveal" data-reveal aria-controls="{{ $name }}" aria-label="Show password">Show</button>@endif</div>
@if($hint)<p id="{{ $name }}-hint" class="ui-hint">{{ $hint }}</p>@endif
@if($err)<p id="{{ $name }}-err" class="ui-err" role="alert">{{ $err }}</p>@endif
</div>
