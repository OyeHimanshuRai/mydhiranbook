@extends('admin.auth') @section('title','Admin sign in') @section('content')
<h1>Platform sign in</h1><p class="lead">Use your Dhiran platform admin credentials. A second step follows.</p>
<form method="post" action="/admin/login">@csrf
@include('admin._field',['name'=>'email','label'=>'Admin email address','type'=>'email','attrs'=>['autocomplete'=>'username','required'=>'required','autocapitalize'=>'off','spellcheck'=>'false']])
@include('admin._field',['name'=>'password','label'=>'Password','type'=>'password','attrs'=>['autocomplete'=>'current-password','required'=>'required']])
<button class="adm-btn adm-btn--block">Continue to MFA</button></form>
<p class="adm-auth-links"><a href="/admin/forgot-password">Forgot password?</a><a href="/login">Shop sign in →</a></p>
@endsection
