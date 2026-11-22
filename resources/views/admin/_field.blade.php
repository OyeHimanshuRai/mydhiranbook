{{-- One labelled control. $name,$label; $type,$value,$options(array value=>text),$form(hidden _form id for pages with repeated names),$help,$attrs(array),$class, $req (server-required: star + aria-required). --}}
@php
    $form ??= null; $req ??= false; $attrs ??= []; $type ??= 'text'; $value ??= null;
    $id = ($form ?: 'f').'-'.$name;
    $mine = !$form || old('_form') === $form;
    $bad = $errors->has($name) && $mine;
    $val = ($mine && $type !== 'password') ? old($name, $value) : $value;
    $desc = trim(($help ?? false ? $id.'-h ' : '').($bad ? $id.'-e' : ''));
@endphp
<div class="adm-field {{ $class ?? '' }} {{ $bad ? 'has-err' : '' }}">
    <label for="{{ $id }}">{{ $label }}@if($req) <x-req />@endif</label>
    @if(isset($options))
        <select id="{{ $id }}" name="{{ $name }}" @if($req) aria-required="true" @endif @if($bad) aria-invalid="true" @endif @if($desc) aria-describedby="{{ $desc }}" @endif @foreach($attrs as $k=>$v) {{ $k }}="{{ $v }}" @endforeach>@foreach($options as $k=>$t)<option value="{{ $k }}" @selected((string)$val === (string)$k)>{{ $t }}</option>@endforeach</select>
    @else
        <input id="{{ $id }}" name="{{ $name }}" type="{{ $type }}" value="{{ $val }}" @if($req) aria-required="true" @endif @if($bad) aria-invalid="true" @endif @if($desc) aria-describedby="{{ $desc }}" @endif @foreach($attrs as $k=>$v) {{ $k }}="{{ $v }}" @endforeach>
    @endif
    @if($help ?? false)<p class="adm-hint" id="{{ $id }}-h">{{ $help }}</p>@endif
    @if($bad)<p class="adm-err" id="{{ $id }}-e">{{ $errors->first($name) }}</p>@endif
</div>
