@extends('admin.layout') @section('title','Plans') @section('content')
<div class="adm-head"><div><h1>Plans</h1><p>Edit prices and limits. Inactive plans leave signup and renewal; existing subscriptions keep working.</p></div></div>
@foreach($plans as $p)@php $f='plan-'.$p->id; @endphp<section class="adm-panel"><div class="adm-panel-head"><h2>{{ $p->name }} <span class="adm-muted">{{ $p->code }}</span></h2>@include('admin._badge',['s'=>$p->is_active?'active':'inactive'])</div><div class="adm-panel-body"><form action="/admin/plans/{{ $p->id }}" method="post">@csrf @method('PATCH')<input type="hidden" name="_form" value="{{ $f }}"><div class="adm-grid adm-grid--3">
@include('admin._field',['name'=>'price_monthly','req'=>true,'label'=>'Monthly price (₹)','type'=>'number','form'=>$f,'value'=>(int)round((float)$p->price_monthly),'help'=>'Whole rupees.','attrs'=>['min'=>0,'step'=>'1','required'=>'required','inputmode'=>'numeric']])
@if($p->price_yearly!==null)@include('admin._field',['name'=>'price_yearly','label'=>'Yearly price (₹)','type'=>'number','form'=>$f,'value'=>(int)round((float)$p->price_yearly),'help'=>'Whole rupees.','attrs'=>['min'=>0,'step'=>'1','inputmode'=>'numeric']])@endif
@include('admin._field',['name'=>'grace_days','req'=>true,'label'=>'Grace days','type'=>'number','form'=>$f,'value'=>$p->grace_days,'attrs'=>['min'=>0,'max'=>90,'required'=>'required']])
@include('admin._field',['name'=>'staff_limit','req'=>true,'label'=>'Staff limit','type'=>'number','form'=>$f,'value'=>$p->feature('staff_limit'),'attrs'=>['min'=>1,'required'=>'required']])
@include('admin._field',['name'=>'max_items','req'=>true,'label'=>'Max pledged items','type'=>'number','form'=>$f,'value'=>$p->feature('max_items'),'attrs'=>['min'=>1,'required'=>'required']])
@include('admin._field',['name'=>'is_active','req'=>true,'label'=>'Offered for sale','form'=>$f,'options'=>['1'=>'Active','0'=>'Inactive'],'value'=>$p->is_active?'1':'0'])
@include('admin._field',['name'=>'reason','label'=>'Reason','form'=>$f,'class'=>'full','attrs'=>['maxlength'=>'500']])
</div><div class="adm-actions"><button class="adm-btn">Save plan</button></div></form></div></section>@endforeach
@endsection
