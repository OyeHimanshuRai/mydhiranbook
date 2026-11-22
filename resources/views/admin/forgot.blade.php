@extends('admin.auth') @section('title','Admin password recovery') @section('content')
<h1>Reset your password</h1><p class="lead">Enter your Dhiran platform admin email.</p>
<form method="post" action="/admin/forgot-password">@csrf
@include('admin._field',['name'=>'email','label'=>'Admin email','type'=>'email','attrs'=>['required'=>'required','autocomplete'=>'username','autocapitalize'=>'off','spellcheck'=>'false']])
<button class="adm-btn adm-btn--block">Request recovery link</button></form>
<p class="adm-hint" style="margin-top:var(--sp-4)">Local edition delivers recovery mail to the private Dhiran mail log.</p>
<p class="adm-auth-links"><a href="/admin/login">← Back to sign in</a></p>
@endsection
