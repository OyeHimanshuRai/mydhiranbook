{{-- One labelled control. $name,$label; optional $type,$value,$options(array value=>text),$form(hidden _form id for pages with repeated names),$help,$attrs(array),$class. --}}
@php
    $form ??= null; $attrs ??= []; $type ??= 'text'; $value ??= null;
    $id = ($form ?: 'f').'-'.$name;
    $mine = !$form || old('_form') === $form;
    $bad = $errors->has($name) && $mine;
    $val = ($mine && $type !== 'password') ? old($name, $value) : $value;
    $desc = trim(($help ?? false ? $id.'-h ' : '').($bad ? $id.'-e' : ''));
@endphp
<div class="adm-field {{ $class ?? '' }} {{ $bad ? 'has-err' : '' }}">
    <label for="{{ $id }}">{{ $label }}</label>
    @if(isset($options))
        <select id="{{ $id }}" name="{{ $name }}" @if($bad) aria-invalid="true" @endif @if($desc) aria-describedby="{{ $desc }}" @endif @foreach($attrs as $k=>$v) {{ $k }}="{{ $v }}" @endforeach>@foreach($options as $k=>$t)<option value="{{ $k }}" @selected((string)$val === (string)$k)>{{ $t }}</option>@endforeach</select>
    @else
        <input id="{{ $id }}" name="{{ $name }}" type="{{ $type }}" value="{{ $val }}" @if($bad) aria-invalid="true" @endif @if($desc) aria-describedby="{{ $desc }}" @endif @foreach($attrs as $k=>$v) {{ $k }}="{{ $v }}" @endforeach>
    @endif
    @if($help ?? false)<p class="adm-hint" id="{{ $id }}-h">{{ $help }}</p>@endif
    @if($bad)<p class="adm-err" id="{{ $id }}-e">{{ $errors->first($name) }}</p>@endif
</div>
