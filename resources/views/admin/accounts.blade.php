@extends('admin.layout') @section('title','Admin accounts') @section('content')
@php $perms=['can_manage_shops'=>'Manage shops','can_manage_subscriptions'=>'Manage subscriptions','can_view_audit_logs'=>'View audit log','can_manage_admins'=>'Manage admins']; @endphp
<div class="adm-head"><div><h1>Admin accounts</h1><p>Who can sign in to the platform panel, and what each admin may do.</p></div></div>
<div class="adm-split"><section class="adm-panel"><div class="adm-panel-head"><h2>Administrators</h2></div><ul class="adm-people">
@foreach($admins as $a)@php $f='admin-'.$a->id; @endphp<li class="adm-person"><div class="adm-person-top"><div><strong class="adm-wrap">{{ $a->name }}</strong><span class="adm-sub adm-wrap">{{ $a->email }} · {{ $a->role }}</span></div>@include('admin._badge',['s'=>$a->two_factor_enabled?'enabled':'pending','label'=>$a->two_factor_enabled?'MFA enabled':'Enrollment required'])</div>
@if(!$a->isSuperAdmin())<form method="post" action="/admin/accounts/{{ $a->id }}">@csrf @method('PATCH')<input type="hidden" name="_form" value="{{ $f }}">
@include('admin._field',['name'=>'is_active','label'=>'Access','form'=>$f,'options'=>['1'=>'Active','0'=>'Inactive'],'value'=>$a->is_active?'1':'0','help'=>'Inactive admins cannot sign in.'])
<fieldset class="adm-set" style="margin-top:var(--sp-3)"><legend class="adm-legend">Permissions</legend>@foreach($perms as $permission=>$label)<label class="adm-check"><input type="checkbox" name="permissions[]" value="{{ $permission }}" @checked($a->hasPermission($permission))> {{ $label }}</label>@endforeach</fieldset>
<div class="adm-actions" style="margin-top:var(--sp-3)"><button class="adm-btn adm-btn--ghost">Save access</button></div></form>@else<p class="adm-hint">Full platform access</p>@endif</li>@endforeach
</ul></section>
<section class="adm-panel"><div class="adm-panel-head"><h2>Add an admin</h2></div><form class="adm-panel-body" method="post" action="/admin/accounts">@csrf<input type="hidden" name="_form" value="new-admin"><div class="adm-grid" style="grid-template-columns:1fr">
@include('admin._field',['name'=>'name','label'=>'Name','form'=>'new-admin','attrs'=>['required'=>'required','maxlength'=>'120','autocomplete'=>'off']])
@include('admin._field',['name'=>'email','label'=>'Email','type'=>'email','form'=>'new-admin','attrs'=>['required'=>'required','autocomplete'=>'off','autocapitalize'=>'off','spellcheck'=>'false']])
@include('admin._field',['name'=>'password','label'=>'Temporary password','type'=>'password','form'=>'new-admin','help'=>'At least 12 characters. MFA setup is mandatory on first sign in.','attrs'=>['required'=>'required','minlength'=>'12','autocomplete'=>'new-password']])
<fieldset class="adm-set"><legend class="adm-legend">Permissions</legend>@foreach($perms as $permission=>$label)<label class="adm-check"><input type="checkbox" name="permissions[]" value="{{ $permission }}"> {{ $label }}</label>@endforeach</fieldset>
</div><div class="adm-actions"><button class="adm-btn">Create admin</button></div></form></section></div>
@endsection
