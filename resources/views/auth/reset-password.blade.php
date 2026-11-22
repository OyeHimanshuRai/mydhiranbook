@extends('auth.base') @section('title','Choose a new password') @section('content')
<h1>Choose a new password.</h1>
<form method="post" action="{{ route('password.store') }}" data-once>@csrf<input type="hidden" name="token" value="{{ $token }}">
<x-site.field name="email" label="Email address" type="email" req :value="old('email', $email)" required autocomplete="email" inputmode="email" autocapitalize="none" spellcheck="false" />
<x-site.field name="password" label="New password" password req required minlength="12" autocomplete="new-password" hint="At least 12 characters." />
<x-site.field name="password_confirmation" label="Confirm new password" password required autocomplete="new-password" />
<button class="ui-btn ui-btn--block ui-submit" data-busy="Resetting…">Reset password</button></form>
<div class="auth-alt"><a href="/login">Back to sign in</a></div>
@endsection
