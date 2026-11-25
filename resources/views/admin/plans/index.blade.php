@extends('admin.layout') @section('title','Plans') @section('content')
<div class="adm-head"><div><h1>Plans</h1><p>Edit the staff and item limits that come with each access term.</p></div></div>
@foreach($plans as $p)@php $f='plan-'.$p->id; @endphp<section class="adm-panel"><div class="adm-panel-head"><h2>{{ $p->name }} <span class="adm-muted">{{ $p->code }}</span></h2></div><div class="adm-panel-body"><form action="/admin/plans/{{ $p->id }}" method="post">@csrf @method('PATCH')<input type="hidden" name="_form" value="{{ $f }}"><div class="adm-grid adm-grid--3">
@include('admin._field',['name'=>'grace_days','req'=>true,'label'=>'Grace days','type'=>'number','form'=>$f,'value'=>$p->grace_days,'attrs'=>['min'=>0,'max'=>90,'required'=>'required']])
@include('admin._field',['name'=>'staff_limit','req'=>true,'label'=>'Staff limit','type'=>'number','form'=>$f,'value'=>$p->feature('staff_limit'),'attrs'=>['min'=>1,'required'=>'required']])
@include('admin._field',['name'=>'max_items','req'=>true,'label'=>'Max pledged items','type'=>'number','form'=>$f,'value'=>$p->feature('max_items'),'attrs'=>['min'=>1,'required'=>'required']])
@include('admin._field',['name'=>'reason','label'=>'Reason','form'=>$f,'class'=>'full','attrs'=>['maxlength'=>'500']])
</div><div class="adm-actions"><button class="adm-btn">Save plan</button></div></form></div></section>@endforeach
@endsection
