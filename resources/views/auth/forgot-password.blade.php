@extends('auth.base') @section('title','Reset password') @section('content')
<h1>Reset your password.</h1><p class="lede">Enter your account email and we will send you a reset link.</p>
<form method="post" action="{{ route('password.email') }}" data-once>@csrf
<x-site.field name="email" label="Email address" type="email" :value="old('email')" required autocomplete="email" inputmode="email" autocapitalize="none" spellcheck="false" />
<button class="ui-btn ui-btn--block ui-submit" data-busy="Sending…">Send reset link</button></form>
<div class="auth-alt"><a href="/login">Back to sign in</a></div>
@endsection
