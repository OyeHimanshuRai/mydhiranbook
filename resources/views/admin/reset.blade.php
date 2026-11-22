@extends('admin.auth') @section('title','Set new admin password') @section('content')
<h1>Set a new password</h1><p class="lead">MFA stays enabled after password recovery.</p>
<form method="post" action="/admin/reset-password">@csrf<input type="hidden" name="token" value="{{ $token }}">
@include('admin._field',['name'=>'email','req'=>true,'label'=>'Admin email','type'=>'email','value'=>$email,'attrs'=>['required'=>'required','autocomplete'=>'username','autocapitalize'=>'off','spellcheck'=>'false']])
@include('admin._field',['name'=>'password','req'=>true,'label'=>'New password','type'=>'password','help'=>'At least 12 characters.','attrs'=>['minlength'=>'12','autocomplete'=>'new-password','required'=>'required']])
@include('admin._field',['name'=>'password_confirmation','label'=>'Confirm password','type'=>'password','attrs'=>['autocomplete'=>'new-password','required'=>'required']])
<button class="adm-btn adm-btn--block">Set password</button></form>
@endsection
